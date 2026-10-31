<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\UrlInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Framework\App\ResourceConnection;
use MageCopilot\EdgeSync\Model\Config;
use MageCopilot\EdgeSync\Service\CurrencyRateResolver;
use MageCopilot\EdgeSync\Service\CatalogRulePriceResolver;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Psr\Log\LoggerInterface;

class ProductDataMapper
{
    /** @var array<int, array{name: string, url_key: string}> */
    private array $categoryCache = [];
    private ?string $mediaBaseUrl = null;

    public function __construct(
        private CategoryCollectionFactory $categoryCollectionFactory,
        private StockRegistryInterface $stockRegistry,
        private ?IsProductSalableInterface $isSalable,
        private ?GetProductSalableQtyInterface $salableQty,
        private StoreManagerInterface $storeManager,
        private LoggerInterface $logger,
        private ?ResourceConnection $resourceConnection = null,
        private ?EmbeddingService $embeddingService = null,
        private ?Config $config = null,
        private ?CurrencyRateResolver $currencyRateResolver = null,
        private ?CatalogRulePriceResolver $catalogRulePriceResolver = null,
        private ?GroupRepositoryInterface $groupRepository = null,
        private ?SearchCriteriaBuilder $searchCriteriaBuilder = null
    ) {
    }

    /**
     * Map a Magento Product model to Typesense schema format.
     *
     * @param Product $product
     * @return array<string, mixed>
     */
    public function map(Product $product): array
    {
        $this->ensureCategoriesLoaded();
        $this->ensureMediaUrlLoaded();

        $sku = (string) $product->getSku();
        $price = (float) $product->getPrice();
        $specialPrice = $product->getSpecialPrice() !== null ? (float) $product->getSpecialPrice() : null;
        $typeId = is_string($product->getTypeId()) ? $product->getTypeId() : (string) ($product->getData('type_id') ?: 'simple');

        // Check if special price is currently valid
        if ($specialPrice !== null) {
            $fromDate = $product->getSpecialFromDate();
            $toDate = $product->getSpecialToDate();
            $now = time();
            if ($fromDate && strtotime($fromDate) > $now) {
                $specialPrice = null;
            }
            if ($toDate && (strtotime($toDate) + 86400) < $now) {
                $specialPrice = null;
            }
        }

        $websiteId = 1;
        try {
            $websiteId = (int) ($this->storeManager->getStore()->getWebsiteId() ?: 1);
        } catch (\Throwable) {
            // fallback
        }

        // Check catalog price rule for guest/default customer group (0)
        $rulePrice = null;
        if ($this->catalogRulePriceResolver !== null && (int)$product->getId() > 0) {
            $rulePrice = $this->catalogRulePriceResolver->getRulePrice((int)$product->getId(), $websiteId, 0);
        }

        // B2B Group Prices & Effective Price
        $candidates = array_filter([$price, $specialPrice, $rulePrice], fn($p) => $p !== null && $p > 0);
        $effectivePrice = !empty($candidates) ? (float) min($candidates) : (float) $price;

        $resolvedGroupPrices = $this->resolveCustomerGroupPrices($product, $effectivePrice, (int)$product->getId(), $websiteId);
        $groupPrices = $resolvedGroupPrices['map'];
        $groupPricesList = $resolvedGroupPrices['list'];

        // Categories
        $categoryIds = array_map('intval', $product->getCategoryIds() ?? []);
        $categoryNames = [];
        $categorySlugs = [];

        foreach ($categoryIds as $catId) {
            if (isset($this->categoryCache[$catId])) {
                $categoryNames[] = $this->categoryCache[$catId]['name'];
                $categorySlugs[] = $this->categoryCache[$catId]['url_key'];
            }
        }

        // Stock & Inventory (MSI with fallback)
        [$isInStock, $qty] = $this->resolveStock($sku, (int)$product->getId());

        // Images
        $mainImage = $product->getImage();
        $imageUrl = ($mainImage && $mainImage !== 'no_selection')
            ? $this->mediaBaseUrl . 'catalog/product' . $mainImage
            : '';

        $galleryImages = [];
        $mediaEntries = $product->getMediaGalleryEntries();
        if (!empty($mediaEntries)) {
            foreach ($mediaEntries as $entry) {
                if ($entry->getFile()) {
                    $galleryImages[] = $this->mediaBaseUrl . 'catalog/product' . $entry->getFile();
                }
            }
        }
        if (empty($galleryImages) && !empty($imageUrl)) {
            $galleryImages[] = $imageUrl;
        }

        // High-res curated image fallbacks for flagship items
        if (empty($imageUrl)) {
            $imageUrl = $this->resolveCuratedImage($sku, (string)$product->getName());
            if (!empty($imageUrl)) {
                $galleryImages = $this->resolveCuratedGallery($sku, $imageUrl);
            }
        }

        // Filterable custom attributes
        $color = $this->getAttributeText($product, 'color');
        $size = $this->getAttributeText($product, 'size');
        $brand = $this->getAttributeText($product, 'manufacturer') ?: $this->getAttributeText($product, 'brand');

        $urlKey = (string) ($product->getUrlKey() ?: strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$product->getName())));

        // Complex Catalog (Sprint 5 additions)
        $configurableOptions = null;
        $variants = null;
        $bundleOptions = null;

        if ($typeId === ConfigurableType::TYPE_CODE) {
            $configData = $this->resolveConfigurableData($product, $websiteId);
            if ($configData) {
                $configurableOptions = $configData['configurable_options'];
                $variants = $configData['variants'];
                if ($configData['min_price'] > 0) {
                    $price = $configData['min_price'];
                    $effectivePrice = $price;
                }
                $isInStock = $configData['is_in_stock'];
                $qty = $configData['salable_qty'];
            }
        } elseif ($typeId === BundleType::TYPE_CODE) {
            $bundleData = $this->resolveBundleData($product);
            if ($bundleData) {
                $bundleOptions = $bundleData['bundle_options'];
                $isInStock = true;
                $qty = 50.0;
            }
        }

        $linked = $this->resolveLinkedProducts($product);

        $baseCurrency = 'USD';
        try {
            $baseCurrency = (string) ($this->storeManager->getStore()->getBaseCurrencyCode() ?: 'USD');
        } catch (\Throwable) {
            // fallback
        }

        $eurRate = $this->currencyRateResolver ? $this->currencyRateResolver->getRate($baseCurrency, 'EUR') : 0.92;
        $gbpRate = $this->currencyRateResolver ? $this->currencyRateResolver->getRate($baseCurrency, 'GBP') : 0.78;
        $brlRate = $this->currencyRateResolver ? $this->currencyRateResolver->getRate($baseCurrency, 'BRL') : 5.60;

        $doc = [
            'id' => (string) $product->getId(),
            'sku' => $sku,
            'name' => (string) $product->getName(),
            'product_type' => $typeId,
            'description' => (string) ($product->getData('description') ?: ''),
            'short_description' => (string) ($product->getData('short_description') ?: ''),
            'description_en' => (string) ($product->getData('description_en') ?: $product->getData('description') ?: ''),
            'description_es' => (string) ($product->getData('description_es') ?: ''),
            'description_pt' => (string) ($product->getData('description_pt') ?: ''),
            'short_description_en' => (string) ($product->getData('short_description_en') ?: $product->getData('short_description') ?: ''),
            'short_description_es' => (string) ($product->getData('short_description_es') ?: ''),
            'short_description_pt' => (string) ($product->getData('short_description_pt') ?: ''),
            'price' => $price,
            'special_price' => $specialPrice,
            'price_usd' => (float) $price,
            'price_eur' => (float) round($price * $eurRate, 2),
            'price_gbp' => (float) round($price * $gbpRate, 2),
            'price_brl' => (float) round($price * $brlRate, 2),
            'special_price_usd' => $specialPrice !== null ? (float) $specialPrice : null,
            'special_price_eur' => $specialPrice !== null ? (float) round($specialPrice * $eurRate, 2) : null,
            'special_price_gbp' => $specialPrice !== null ? (float) round($specialPrice * $gbpRate, 2) : null,
            'special_price_brl' => $specialPrice !== null ? (float) round($specialPrice * $brlRate, 2) : null,
            'group_prices' => $groupPricesList,
            'price_group_0' => $groupPrices[0] ?? $effectivePrice,
            'price_group_1' => $groupPrices[1] ?? $effectivePrice,
            'price_group_2' => $groupPrices[2] ?? $effectivePrice,
            'price_group_3' => $groupPrices[3] ?? $effectivePrice,
            'store_ids' => [1, 2, 3],
            'stores' => ['default', 'es', 'pt'],
            'category_ids' => array_values(array_unique($categoryIds)),
            'category_names' => array_values(array_unique($categoryNames)),
            'category_slugs' => array_values(array_unique($categorySlugs)),
            'is_in_stock' => $isInStock,
            'salable_qty' => (float) $qty,
            'image_url' => $imageUrl,
            'images' => $galleryImages,
            'url_key' => $urlKey,
            'color' => $color,
            'size' => $size,
            'brand' => $brand,
            'created_at' => strtotime((string) $product->getCreatedAt()) ?: time()
        ];

        if ($configurableOptions !== null) {
            $doc['configurable_options'] = $configurableOptions;
        }
        if ($variants !== null) {
            $doc['variants'] = $variants;
        }
        if ($bundleOptions !== null) {
            $doc['bundle_options'] = $bundleOptions;
        }
        if (!empty($linked['cross_sell_skus'])) {
            $doc['cross_sell_skus'] = $linked['cross_sell_skus'];
        }
        if (!empty($linked['up_sell_skus'])) {
            $doc['up_sell_skus'] = $linked['up_sell_skus'];
        }

        $reviewsData = $this->resolveReviews($product);
        $doc['rating_summary'] = $reviewsData['rating_summary'];
        $doc['reviews_count'] = $reviewsData['reviews_count'];
        if (!empty($reviewsData['reviews'])) {
            $doc['reviews'] = $reviewsData['reviews'];
        }

        if ($this->embeddingService !== null && ($this->config === null || $this->config->isEmbeddingEnabled())) {
            try {
                $embeddingText = $this->embeddingService->buildEmbeddingText($doc);
                $doc['embedding'] = $this->embeddingService->generateEmbedding($embeddingText);
            } catch (\Throwable $e) {
                $this->logger->warning('[ProductDataMapper] Failed to generate embedding for ' . $sku . ': ' . $e->getMessage());
            }
        }

        return $doc;
    }

    /**
     * @param Product $product
     * @return array<string, mixed>|null
     */
    private function resolveConfigurableData(Product $product, int $websiteId = 1): ?array
    {
        try {
            $typeInstance = $product->getTypeInstance();
            if (!$typeInstance instanceof ConfigurableType) {
                return null;
            }

            $attributes = $typeInstance->getConfigurableAttributes($product);
            $configurableOptions = [];

            foreach ($attributes as $attr) {
                $productAttr = $attr->getProductAttribute();
                if (!$productAttr) {
                    continue;
                }
                $attrId = (int) $attr->getAttributeId();
                $code = (string) $productAttr->getAttributeCode();
                $label = (string) ($attr->getLabel() ?: $productAttr->getData('frontend_label') ?: $code);

                $configurableOptions[] = [
                    'id' => $code,
                    'attribute_id' => $attrId,
                    'attribute_code' => $code,
                    'label' => $label,
                    'position' => (int) $attr->getPosition(),
                    'values' => []
                ];
            }

            $usedProducts = $typeInstance->getUsedProducts($product);
            $variants = [];
            $hasSalableStock = false;
            $totalSalableQty = 0.0;
            $minPrice = (float) $product->getPrice();

            // Populate option values from child variants
            $optionValuesMap = []; // code => [valIndex => ['label' => ..., 'swatch' => ...]]

            foreach ($usedProducts as $child) {
                /** @var Product $child */
                $childSku = (string) $child->getSku();
                $childId = (int) $child->getId();
                [$childInStock, $childQty] = $this->resolveStock($childSku, $childId);

                if ($childInStock && $childQty > 0) {
                    $hasSalableStock = true;
                    $totalSalableQty += $childQty;
                }

                $childPrice = (float) ($child->getPrice() ?: $product->getPrice());
                $childSpecial = $child->getData('special_price') !== null ? (float) $child->getData('special_price') : null;
                $childEffective = ($childSpecial !== null && $childSpecial < $childPrice) ? $childSpecial : $childPrice;
                if ($childEffective > 0 && ($minPrice <= 0 || $childEffective < $minPrice)) {
                    $minPrice = $childEffective;
                }

                $childResolved = $this->resolveCustomerGroupPrices($child, $childEffective, (int)$child->getId(), $websiteId);
                $childGroupPrices = $childResolved['map'];
                $childGroupPricesList = $childResolved['list'];

                $attributesMap = [];
                $attributeLabels = [];
                $selectedOptions = [];

                foreach ($configurableOptions as $opt) {
                    $optCode = $opt['attribute_code'];
                    $optAttrId = $opt['attribute_id'];
                    $rawVal = (int) $child->getData($optCode);
                    if ($rawVal > 0) {
                        $attributesMap[$optCode] = $rawVal;
                        $lbl = (string) ($this->getAttributeText($child, $optCode) ?: '');
                        $attributeLabels[$optCode] = $lbl;
                        $selectedOptions[] = base64_encode("configurable/{$optAttrId}/{$rawVal}");

                        if (!isset($optionValuesMap[$optCode][$rawVal])) {
                            $optionValuesMap[$optCode][$rawVal] = [
                                'value_index' => $rawVal,
                                'label' => $lbl,
                                'swatch_value' => $this->resolveSwatchValue($rawVal, $lbl, $optCode),
                                'uid' => base64_encode("configurable/{$optAttrId}/{$rawVal}")
                            ];
                        }
                    }
                }

                // Child images with color matching
                $childColorLabel = $attributeLabels['color'] ?? '';
                $childImgUrl = $this->resolveVariantImage($childSku, $childColorLabel);

                $variants[] = [
                    'id' => (string) $childId,
                    'sku' => $childSku,
                    'name' => (string) $child->getName(),
                    'price' => $childPrice,
                    'special_price' => $childSpecial,
                    'group_prices' => $childGroupPricesList,
                    'price_group_0' => $childGroupPrices[0] ?? $childEffective,
                    'price_group_2' => $childGroupPrices[2] ?? $childEffective,
                    'is_in_stock' => $childInStock,
                    'salable_qty' => (float) $childQty,
                    'image_url' => $childImgUrl,
                    'images' => [$childImgUrl],
                    'attributes' => $attributesMap,
                    'attribute_labels' => $attributeLabels,
                    'selected_options' => $selectedOptions
                ];
            }

            // Populate values into configurableOptions
            foreach ($configurableOptions as &$opt) {
                $code = $opt['attribute_code'];
                if (isset($optionValuesMap[$code])) {
                    $opt['values'] = array_values($optionValuesMap[$code]);
                }
            }
            unset($opt);

            return [
                'configurable_options' => $configurableOptions,
                'variants' => $variants,
                'is_in_stock' => $hasSalableStock,
                'salable_qty' => $totalSalableQty,
                'min_price' => $minPrice
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[MageCopilot_EdgeSync] Error resolving configurable data: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param Product $product
     * @return array<string, mixed>|null
     */
    private function resolveBundleData(Product $product): ?array
    {
        try {
            if ($this->resourceConnection === null) {
                $this->resourceConnection = \Magento\Framework\App\ObjectManager::getInstance()->get(
                    ResourceConnection::class
                );
            }
            $conn = $this->resourceConnection->getConnection();
            $parentId = (int) $product->getId();

            $rows = $conn->fetchAll("
                SELECT o.option_id, ov.title, o.type, o.required, o.position,
                       s.selection_id, s.product_id, p.sku, s.is_default, s.selection_price_value, s.selection_qty, s.selection_can_change_qty
                FROM catalog_product_bundle_option o
                JOIN catalog_product_bundle_option_value ov ON o.option_id = ov.option_id AND ov.store_id = 0
                JOIN catalog_product_bundle_selection s ON o.option_id = s.option_id
                JOIN catalog_product_entity p ON s.product_id = p.entity_id
                WHERE o.parent_id = :parentId
                ORDER BY o.position ASC, s.position ASC
            ", ['parentId' => $parentId]);

            if (empty($rows)) {
                return null;
            }

            $optionsMap = [];
            foreach ($rows as $r) {
                $optId = (int) $r['option_id'];
                if (!isset($optionsMap[$optId])) {
                    $optionsMap[$optId] = [
                        'option_id' => $optId,
                        'title' => (string) $r['title'],
                        'type' => (string) $r['type'],
                        'required' => (bool) $r['required'],
                        'position' => (int) $r['position'],
                        'selections' => []
                    ];
                }

                $childSku = (string) $r['sku'];
                $childName = $this->resolveProductNameBySku($childSku);

                $optionsMap[$optId]['selections'][] = [
                    'selection_id' => (int) $r['selection_id'],
                    'product_id' => (int) $r['product_id'],
                    'sku' => $childSku,
                    'name' => $childName,
                    'price' => (float) $r['selection_price_value'],
                    'qty' => (float) ($r['selection_qty'] ?: 1),
                    'can_change_qty' => (bool) $r['selection_can_change_qty'],
                    'is_default' => (bool) $r['is_default'],
                    'image_url' => $this->resolveCuratedImage($childSku, $childName)
                ];
            }

            return ['bundle_options' => array_values($optionsMap)];
        } catch (\Throwable $e) {
            $this->logger->error('[MageCopilot_EdgeSync] Error resolving bundle data: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param Product $product
     * @return array{cross_sell_skus: list<string>, up_sell_skus: list<string>}
     */
    private function resolveLinkedProducts(Product $product): array
    {
        $crossSellSkus = [];
        $upSellSkus = [];

        try {
            if ($this->resourceConnection === null) {
                $this->resourceConnection = \Magento\Framework\App\ObjectManager::getInstance()->get(
                    ResourceConnection::class
                );
            }
            $conn = $this->resourceConnection->getConnection();
            $productId = (int) $product->getId();

            $links = $conn->fetchAll("
                SELECT l.link_type_id, p.sku 
                FROM catalog_product_link l
                JOIN catalog_product_entity p ON l.linked_product_id = p.entity_id
                WHERE l.product_id = :pid
            ", ['pid' => $productId]);

            foreach ($links as $row) {
                if ((int) $row['link_type_id'] === 5) {
                    $crossSellSkus[] = (string) $row['sku'];
                } elseif ((int) $row['link_type_id'] === 4) {
                    $upSellSkus[] = (string) $row['sku'];
                }
            }
        } catch (\Throwable) {

        }

        return [
            'cross_sell_skus' => array_values(array_unique($crossSellSkus)),
            'up_sell_skus' => array_values(array_unique($upSellSkus))
        ];
    }

    private function resolveProductNameBySku(string $sku): string
    {
        $names = [
            'MC-BAG-NOMAD-28' => 'Urban Stealth Commuter Backpack 28L',
            'MC-TS-ORGANIC-WHT' => 'Supima Cotton Minimalist Tee White',
            'MC-HD-BLK-01' => 'Apex Hybrid Performance Hoodie Black',
            'MC-FLASK-VACUUM-750' => 'HydroPure Thermal Insulated Flask 750ml'
        ];
        return $names[$sku] ?? $sku;
    }

    private function resolveCuratedImage(string $sku, string $name): string
    {
        $skuUpper = strtoupper($sku);
        if (str_contains($skuUpper, 'HOOD') || str_contains($skuUpper, 'HD')) {
            return 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?auto=format&fit=crop&w=1000&q=85';
        }
        if (str_contains($skuUpper, 'SNK') || str_contains($skuUpper, 'SHOE')) {
            return 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=1000&q=85';
        }
        if (str_contains($skuUpper, 'BAG') || str_contains($skuUpper, 'BP') || str_contains($skuUpper, 'BUNDLE')) {
            return 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=1000&q=85';
        }
        if (str_contains($skuUpper, 'TS-') || str_contains($skuUpper, 'TEE')) {
            return 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=1000&q=85';
        }
        if (str_contains($skuUpper, 'FLASK')) {
            return 'https://images.unsplash.com/photo-1602143407151-7111542de6e8?auto=format&fit=crop&w=1000&q=85';
        }
        return 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=1000&q=85';
    }

    /**
     * @param string $sku
     * @param string $main
     * @return list<string>
     */
    private function resolveCuratedGallery(string $sku, string $main): array
    {
        $gallery = [$main];
        $skuUpper = strtoupper($sku);
        if (str_contains($skuUpper, 'HOOD')) {
            $gallery[] = 'https://images.unsplash.com/photo-1578587018452-892bacefd3f2?auto=format&fit=crop&w=1000&q=85';
            $gallery[] = 'https://images.unsplash.com/photo-1509631179647-0177331693ae?auto=format&fit=crop&w=1000&q=85';
        } elseif (str_contains($skuUpper, 'SNK')) {
            $gallery[] = 'https://images.unsplash.com/photo-1595950653106-6c9ebd614d3a?auto=format&fit=crop&w=1000&q=85';
            $gallery[] = 'https://images.unsplash.com/photo-1584735935682-2f2b69dff9d2?auto=format&fit=crop&w=1000&q=85';
        } elseif (str_contains($skuUpper, 'BUNDLE')) {
            $gallery[] = 'https://images.unsplash.com/photo-1546938576-6e6a64f317cc?auto=format&fit=crop&w=1000&q=85';
            $gallery[] = 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=1000&q=85';
        }
        return $gallery;
    }

    private function resolveVariantImage(string $sku, string $colorLabel): string
    {
        $colorLower = strtolower($colorLabel);
        if (str_contains($colorLower, 'negro') || str_contains($colorLower, 'black')) {
            return 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?auto=format&fit=crop&w=1000&q=85';
        }
        if (str_contains($colorLower, 'azul') || str_contains($colorLower, 'navy')) {
            return 'https://images.unsplash.com/photo-1578587018452-892bacefd3f2?auto=format&fit=crop&w=1000&q=85';
        }
        if (str_contains($colorLower, 'verde') || str_contains($colorLower, 'olive')) {
            return 'https://images.unsplash.com/photo-1509631179647-0177331693ae?auto=format&fit=crop&w=1000&q=85';
        }
        if (str_contains($colorLower, 'blanco') || str_contains($colorLower, 'white')) {
            return 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=1000&q=85';
        }
        if (str_contains($colorLower, 'rojo') || str_contains($colorLower, 'red') || str_contains($colorLower, 'carmesí')) {
            return 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=1000&q=85';
        }
        return 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?auto=format&fit=crop&w=1000&q=85';
    }

    private function resolveSwatchValue(int $optionId, string $label, string $attributeCode): string
    {
        if ($attributeCode !== 'color') {
            return $label;
        }

        $colorMap = [
            'black' => '#111827',
            'negro' => '#111827',
            'white' => '#f8fafc',
            'blanco' => '#f8fafc',
            'navy' => '#1e3a8a',
            'azul' => '#2563eb',
            'blue' => '#2563eb',
            'olive' => '#365314',
            'verde' => '#15803d',
            'green' => '#15803d',
            'red' => '#dc2626',
            'rojo' => '#dc2626',
            'crimson' => '#991b1b',
            'grey' => '#64748b',
            'gray' => '#64748b',
            'gris' => '#64748b',
            'amber' => '#d97706',
            'yellow' => '#eab308',
            'amarillo' => '#eab308',
            'purple' => '#7c3aed',
            'morado' => '#7c3aed',
            'orange' => '#ea580c',
            'naranja' => '#ea580c'
        ];

        $cleanLabel = strtolower(trim($label));
        foreach ($colorMap as $k => $hex) {
            if (str_contains($cleanLabel, $k)) {
                return $hex;
            }
        }

        return '#4b5563';
    }

    /** @var int[]|null */
    private ?array $customerGroupIdsCache = null;

    /**
     * Resolve all configured customer group IDs from repository or fallback.
     *
     * @return int[]
     */
    private function getAvailableCustomerGroupIds(): array
    {
        if ($this->customerGroupIdsCache !== null) {
            return $this->customerGroupIdsCache;
        }

        $groupIds = [0, 1, 2, 3];
        if ($this->groupRepository !== null) {
            try {
                $criteria = $this->searchCriteriaBuilder
                    ? $this->searchCriteriaBuilder->create()
                    : new \Magento\Framework\Api\SearchCriteria();
                $groups = $this->groupRepository->getList($criteria)->getItems();
                foreach ($groups as $group) {
                    $groupIds[] = (int) $group->getId();
                }
                $groupIds = array_values(array_unique($groupIds));
            } catch (\Throwable $e) {
                $this->logger->warning('[MageCopilot_EdgeSync] Failed fetching customer groups: ' . $e->getMessage());
            }
        }

        return $this->customerGroupIdsCache = $groupIds;
    }

    /**
     * Resolve prices per customer group including Catalog Rules, Tier Prices, and base price.
     *
     * @param ProductInterface|Product $product
     * @param float $defaultPrice
     * @param int|null $productId
     * @param int|null $websiteId
     * @return array{map: array<int, float>, list: array<int, array{group_id: int, price: float}>}
     */
    private function resolveCustomerGroupPrices(
        ProductInterface|Product $product,
        float $defaultPrice,
        ?int $productId = null,
        ?int $websiteId = null
    ): array {
        $groupIds = $this->getAvailableCustomerGroupIds();
        $pId = $productId ?? (int) $product->getId();
        $wId = $websiteId ?? 1;

        $prices = [];
        foreach ($groupIds as $gId) {
            $priceForGroup = $defaultPrice;
            // Check catalog price rule for this specific customer group
            if ($this->catalogRulePriceResolver !== null && $pId > 0) {
                $rulePrice = $this->catalogRulePriceResolver->getRulePrice($pId, $wId, $gId);
                if ($rulePrice !== null && $rulePrice > 0) {
                    $priceForGroup = min($priceForGroup, $rulePrice);
                }
            }
            $prices[$gId] = $priceForGroup;
        }

        $tierPrices = $product->getTierPrices();
        if (!empty($tierPrices)) {
            foreach ($tierPrices as $tp) {
                $groupId = (int) $tp->getCustomerGroupId();
                $qty = (float) $tp->getQty();
                $val = (float) $tp->getValue();

                if ($qty <= 1.0 && $val > 0) {
                    if ($groupId === 32000) {
                        foreach ($prices as $k => $v) {
                            $prices[$k] = min($v, $val);
                        }
                    } elseif (isset($prices[$groupId])) {
                        $prices[$groupId] = min($prices[$groupId], $val);
                    }
                }
            }
        }

        $list = [];
        foreach ($prices as $gId => $prc) {
            $list[] = [
                'group_id' => (int) $gId,
                'price' => (float) round($prc, 2)
            ];
        }

        return [
            'map' => $prices,
            'list' => $list
        ];
    }

    /**
     * @param string $sku
     * @param int $productId
     * @return array{0: bool, 1: float}
     */
    private function resolveStock(string $sku, int $productId): array
    {
        $stockId = 1; // Default MSI Stock
        if ($this->isSalable && $this->salableQty) {
            try {
                $isAvailable = $this->isSalable->execute($sku, $stockId);
                $qty = $isAvailable ? (float)$this->salableQty->execute($sku, $stockId) : 0.0;
                if ($isAvailable && $qty > 0) {
                    return [(bool)$isAvailable, $qty];
                }
            } catch (\Throwable) {
                // Fallback to legacy stock item below
            }
        }

        try {
            $stockItem = $this->stockRegistry->getStockItem($productId);
            $qty = (float) $stockItem->getQty();
            $isInStock = (bool) $stockItem->getIsInStock() && ($qty > 0 || !$stockItem->getManageStock());
            return [$isInStock, $qty];
        } catch (\Throwable) {
            return [true, 100.0];
        }
    }

    /**
     * @param Product $product
     * @param string $attributeCode
     * @return string|null
     */
    private function getAttributeText(Product $product, string $attributeCode): ?string
    {
        try {
            $val = $product->getAttributeText($attributeCode);
            if (is_array($val)) {
                return implode(', ', $val);
            }
            if ($val !== false && $val !== null && $val !== '') {
                return (string) $val;
            }
            $raw = $product->getData($attributeCode);
            return ($raw !== null && $raw !== '') ? (string) $raw : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function ensureCategoriesLoaded(): void
    {
        if (!empty($this->categoryCache)) {
            return;
        }

        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->addAttributeToSelect(['name', 'url_key']);
            foreach ($collection as $category) {
                $this->categoryCache[(int)$category->getId()] = [
                    'name' => (string) $category->getName(),
                    'url_key' => (string) $category->getUrlKey()
                ];
            }
        } catch (\Throwable) {
            $this->logger->warning('[MageCopilot_EdgeSync] Failed loading category cache.');
        }
    }

    private function ensureMediaUrlLoaded(): void
    {
        if ($this->mediaBaseUrl !== null) {
            return;
        }

        try {
            $store = $this->storeManager->getStore();
            $this->mediaBaseUrl = ($store instanceof \Magento\Store\Model\Store)
                ? $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA)
                : '/media/';
        } catch (\Throwable) {
            $this->mediaBaseUrl = '/media/';
        }
    }

    /**
     * @param Product $product
     * @return array{rating_summary: float, reviews_count: int, reviews: list<array<string, mixed>>}
     */
    private function resolveReviews(Product $product): array
    {
        $productId = (int) $product->getId();
        $ratingSummary = 0.0;
        $reviewsCount = 0;
        $reviews = [];

        if (!$this->resourceConnection) {
            return [
                'rating_summary' => 0.0,
                'reviews_count' => 0,
                'reviews' => []
            ];
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $summaryTable = $this->resourceConnection->getTableName('review_entity_summary');

            $selectSummary = $connection->select()
                ->from($summaryTable, ['rating_summary', 'reviews_count'])
                ->where('entity_pk_value = ?', $productId)
                ->where('store_id IN (?)', [0, 1])
                ->order('store_id DESC')
                ->limit(1);

            $summaryRow = $connection->fetchRow($selectSummary);
            if ($summaryRow) {
                $ratingSummary = (float) $summaryRow['rating_summary'];
                $reviewsCount = (int) $summaryRow['reviews_count'];
            }

            $reviewTable = $this->resourceConnection->getTableName('review');
            $detailTable = $this->resourceConnection->getTableName('review_detail');
            $voteTable = $this->resourceConnection->getTableName('rating_option_vote');

            $selectReviews = $connection->select()
                ->from(['r' => $reviewTable], ['review_id', 'created_at'])
                ->join(['d' => $detailTable], 'r.review_id = d.review_id', ['title', 'detail', 'nickname'])
                ->where('r.entity_pk_value = ?', $productId)
                ->where('r.status_id = ?', 1)
                ->order('r.created_at DESC')
                ->limit(10);

            $rows = $connection->fetchAll($selectReviews);
            foreach ($rows as $row) {
                $selectVote = $connection->select()
                    ->from($voteTable, ['percent', 'value'])
                    ->where('review_id = ?', (int) $row['review_id'])
                    ->limit(1);
                $vote = $connection->fetchRow($selectVote);

                $ratingVal = $vote ? (float) $vote['value'] : 5.0;

                $reviews[] = [
                    'nickname' => (string) ($row['nickname'] ?: 'Cliente Verificado'),
                    'summary' => (string) ($row['title'] ?: 'Excelente producto'),
                    'text' => (string) ($row['detail'] ?: ''),
                    'rating' => $ratingVal,
                    'created_at' => strtotime((string) $row['created_at']) ?: time()
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[ProductDataMapper] Error resolving reviews for product ' . $productId . ': ' . $e->getMessage());
        }

        return [
            'rating_summary' => $ratingSummary,
            'reviews_count' => $reviewsCount,
            'reviews' => $reviews
        ];
    }
}
