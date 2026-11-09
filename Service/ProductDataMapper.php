<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service;

use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\UrlInterface;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageCopilot\EdgeSync\Model\Config;
use Psr\Log\LoggerInterface;

class ProductDataMapper
{
    /** @var array<int, array{name: string, url_key: string}> */
    private array $categoryCache = [];
    private ?string $mediaBaseUrl = null;

    /** @var array<int, string> */
    private array $swatchCache = [];

    /** @var array<string, string> */
    private array $productNameCache = [];

    /** @var array<string, int> */
    private array $attributeIdCache = [];

    /** @var int[]|null */
    private ?array $customerGroupIdsCache = null;

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
        private ?SearchCriteriaBuilder $searchCriteriaBuilder = null,
        private ?StockResolverInterface $stockResolver = null
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
        $specialPriceRaw = $product->getSpecialPrice();
        $specialPrice = ($specialPriceRaw > 0) ? (float) $specialPriceRaw : null;
        $typeIdRaw = $product->getTypeId();
        $typeId = is_array($typeIdRaw) ? (string) reset($typeIdRaw) : (string) ($typeIdRaw ?: 'simple');

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
            $store = $this->storeManager->getStore();
            $websiteId = (int) ($store->getWebsiteId() ?: 1);
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
        $categoryIds = array_map('intval', (array) $product->getCategoryIds());
        $categoryNames = [];
        $categorySlugs = [];

        foreach ($categoryIds as $catId) {
            if (isset($this->categoryCache[$catId])) {
                $categoryNames[] = $this->categoryCache[$catId]['name'];
                $categorySlugs[] = $this->categoryCache[$catId]['url_key'];
            }
        }

        // Stock & Inventory (MSI with fallback)
        [$isInStock, $qty] = $this->resolveStock($sku, (int)$product->getId(), $websiteId);

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

        // Filterable custom attributes
        $color = $this->getAttributeText($product, 'color');
        $size = $this->getAttributeText($product, 'size');
        $brand = $this->getAttributeText($product, 'manufacturer') ?: $this->getAttributeText($product, 'brand');

        $urlKey = (string) ($product->getUrlKey() ?: strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$product->getName())));

        // Complex Catalog Product Types
        $configurableOptions = null;
        $variants = null;
        $bundleOptions = null;
        $groupedItems = null;
        $minPrice = null;
        $maxPrice = null;

        if ($typeId === ConfigurableType::TYPE_CODE) {
            $configData = $this->resolveConfigurableData($product, $websiteId, $imageUrl);
            if ($configData) {
                $configurableOptions = $configData['configurable_options'];
                $variants = $configData['variants'];
                if ($configData['min_price'] > 0) {
                    $price = $configData['min_price'];
                    $effectivePrice = $price;
                }
                $minPrice = $configData['min_price'];
                $maxPrice = $configData['max_price'];
                $isInStock = $configData['is_in_stock'];
                $qty = $configData['salable_qty'];
            }
        } elseif ($typeId === BundleType::TYPE_CODE) {
            $bundleData = $this->resolveBundleData($product, $websiteId, $imageUrl);
            if ($bundleData) {
                $bundleOptions = $bundleData['bundle_options'];
                $isInStock = $bundleData['is_in_stock'];
                $qty = $bundleData['salable_qty'];
                if ($bundleData['min_price'] > 0) {
                    $price = $bundleData['min_price'];
                    $effectivePrice = $price;
                }
                $minPrice = $bundleData['min_price'];
                $maxPrice = $bundleData['max_price'];
            }
        } elseif ($typeId === GroupedType::TYPE_CODE || $typeId === 'grouped') {
            $groupedData = $this->resolveGroupedData($product, $websiteId, $imageUrl);
            if ($groupedData) {
                $groupedItems = $groupedData['grouped_items'];
                $isInStock = $groupedData['is_in_stock'];
                $qty = $groupedData['salable_qty'];
                if ($groupedData['min_price'] > 0) {
                    $price = $groupedData['min_price'];
                    $effectivePrice = $price;
                }
                $minPrice = $groupedData['min_price'];
                $maxPrice = $groupedData['max_price'];
            }
        }

        $linked = $this->resolveLinkedProducts($product);

        // Dynamic store assignments
        $storeIds = [];
        $storeCodes = [];
        try {
            $websiteIds = array_map('intval', (array) $product->getWebsiteIds());
            if (empty($websiteIds)) {
                $websiteIds = [$websiteId];
            }
            foreach ($this->storeManager->getStores() as $store) {
                if (in_array((int) $store->getWebsiteId(), $websiteIds, true)) {
                    $storeIds[] = (int) $store->getId();
                    $storeCodes[] = (string) $store->getCode();
                }
            }
        } catch (\Throwable) {
            $storeIds = [1];
            $storeCodes = ['default'];
        }

        $baseCurrency = 'USD';
        try {
            $store = $this->storeManager->getStore();
            if ($store instanceof Store) {
                $baseCurrency = (string) ($store->getBaseCurrencyCode() ?: 'USD');
            }
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
            'store_ids' => array_values(array_unique($storeIds)),
            'stores' => array_values(array_unique($storeCodes)),
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

        // Format and append tier prices if present
        $rawTierPrices = $product->getTierPrices();
        if (is_array($rawTierPrices) && !empty($rawTierPrices)) {
            $formattedTierPrices = [];
            foreach ($rawTierPrices as $tp) {
                $percentage = null;
                if ($tp instanceof DataObject && $tp->hasData('percentage')) {
                    $percentage = (float) $tp->getData('percentage');
                }
                $formattedTierPrices[] = [
                    'customer_group_id' => (int) $tp->getCustomerGroupId(),
                    'qty' => (float) $tp->getQty(),
                    'value' => (float) $tp->getValue(),
                    'percentage' => $percentage
                ];
            }
            if ($formattedTierPrices !== []) {
                $doc['tier_prices'] = $formattedTierPrices;
            }
        }

        if ($minPrice !== null) {
            $doc['min_price'] = (float) round($minPrice, 2);
        }
        if ($maxPrice !== null) {
            $doc['max_price'] = (float) round($maxPrice, 2);
        }
        if ($configurableOptions !== null) {
            $doc['configurable_options'] = $configurableOptions;
        }
        if ($variants !== null) {
            $doc['variants'] = $variants;
        }
        if ($bundleOptions !== null) {
            $doc['bundle_options'] = $bundleOptions;
        }
        if ($groupedItems !== null) {
            $doc['grouped_items'] = $groupedItems;
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
     * @param int $websiteId
     * @param string $parentImageUrl
     * @return array<string, mixed>|null
     */
    private function resolveConfigurableData(Product $product, int $websiteId = 1, string $parentImageUrl = ''): ?array
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
            $minPrice = 0.0;
            $maxPrice = 0.0;

            // Populate option values from child variants
            $optionValuesMap = []; // code => [valIndex => ['label' => ..., 'swatch' => ...]]

            foreach ($usedProducts as $child) {
                /** @var Product $child */
                $childSku = (string) $child->getSku();
                $childId = (int) $child->getId();
                [$childInStock, $childQty] = $this->resolveStock($childSku, $childId, $websiteId);

                if ($childInStock && $childQty > 0) {
                    $hasSalableStock = true;
                    $totalSalableQty += $childQty;
                }

                $childPrice = (float) ($child->getPrice() ?: $product->getPrice());
                $childSpecial = $child->getData('special_price') !== null ? (float) $child->getData('special_price') : null;
                $childEffective = ($childSpecial !== null && $childSpecial < $childPrice) ? $childSpecial : $childPrice;

                if ($childEffective > 0) {
                    if ($minPrice <= 0 || $childEffective < $minPrice) {
                        $minPrice = $childEffective;
                    }
                    if ($childEffective > $maxPrice) {
                        $maxPrice = $childEffective;
                    }
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

                // Child images: use child image if present, fallback to parent main image
                $childImage = $child->getImage();
                $childImgUrl = ($childImage && $childImage !== 'no_selection')
                    ? $this->mediaBaseUrl . 'catalog/product' . $childImage
                    : $parentImageUrl;

                $childGallery = [];
                $childMediaEntries = $child->getMediaGalleryEntries();
                if (!empty($childMediaEntries)) {
                    foreach ($childMediaEntries as $entry) {
                        if ($entry->getFile()) {
                            $childGallery[] = $this->mediaBaseUrl . 'catalog/product' . $entry->getFile();
                        }
                    }
                }
                if (empty($childGallery) && !empty($childImgUrl)) {
                    $childGallery[] = $childImgUrl;
                }

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
                    'images' => $childGallery,
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
                'min_price' => $minPrice,
                'max_price' => $maxPrice > 0 ? $maxPrice : $minPrice
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[MageCopilot_EdgeSync] Error resolving configurable data: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param Product $product
     * @param int $websiteId
     * @param string $parentImageUrl
     * @return array<string, mixed>|null
     */
    private function resolveBundleData(Product $product, int $websiteId = 1, string $parentImageUrl = ''): ?array
    {
        if ($this->resourceConnection === null) {
            return null;
        }

        try {
            $conn = $this->resourceConnection->getConnection();
            $parentId = (int) $product->getId();

            $optionTable = $this->resourceConnection->getTableName('catalog_product_bundle_option');
            $optionValTable = $this->resourceConnection->getTableName('catalog_product_bundle_option_value');
            $selectionTable = $this->resourceConnection->getTableName('catalog_product_bundle_selection');
            $entityTable = $this->resourceConnection->getTableName('catalog_product_entity');

            $select = $conn->select()
                ->from(['o' => $optionTable], ['option_id', 'type', 'required', 'position'])
                ->join(['ov' => $optionValTable], 'o.option_id = ov.option_id AND ov.store_id = 0', ['title'])
                ->join(['s' => $selectionTable], 'o.option_id = s.option_id', [
                    'selection_id', 'product_id', 'is_default', 'selection_price_value', 'selection_qty', 'selection_can_change_qty'
                ])
                ->join(['p' => $entityTable], 's.product_id = p.entity_id', ['sku'])
                ->where('o.parent_id = ?', $parentId)
                ->order(['o.position ASC', 's.position ASC']);

            $rows = $conn->fetchAll($select);
            if (empty($rows)) {
                return null;
            }

            $optionsMap = [];
            $requiredOptionsSalable = []; // optId => bool
            $optionMaxUnits = []; // optId => float

            foreach ($rows as $r) {
                $optId = (int) $r['option_id'];
                $isRequired = (bool) $r['required'];

                if (!isset($optionsMap[$optId])) {
                    $optionsMap[$optId] = [
                        'option_id' => $optId,
                        'title' => (string) $r['title'],
                        'type' => (string) $r['type'],
                        'required' => $isRequired,
                        'position' => (int) $r['position'],
                        'selections' => []
                    ];
                    if ($isRequired) {
                        $requiredOptionsSalable[$optId] = false;
                        $optionMaxUnits[$optId] = 0.0;
                    }
                }

                $childId = (int) $r['product_id'];
                $childSku = (string) $r['sku'];
                $childName = $this->resolveProductName($childId, $childSku);
                $selectionQty = (float) ($r['selection_qty'] ?: 1.0);

                [$childInStock, $childQty] = $this->resolveStock($childSku, $childId, $websiteId);
                $isSelectionSalable = ($childInStock && $childQty >= $selectionQty);

                if ($isRequired && $isSelectionSalable) {
                    $requiredOptionsSalable[$optId] = true;
                    $unitsForSelection = floor($childQty / max(1.0, $selectionQty));
                    $optionMaxUnits[$optId] = max($optionMaxUnits[$optId], $unitsForSelection);
                }

                $selectionImgUrl = $this->resolveProductImage($childId, $parentImageUrl);

                $optionsMap[$optId]['selections'][] = [
                    'selection_id' => (int) $r['selection_id'],
                    'product_id' => $childId,
                    'sku' => $childSku,
                    'name' => $childName,
                    'price' => (float) $r['selection_price_value'],
                    'qty' => $selectionQty,
                    'can_change_qty' => (bool) $r['selection_can_change_qty'],
                    'is_default' => (bool) $r['is_default'],
                    'is_in_stock' => $childInStock,
                    'salable_qty' => (float) $childQty,
                    'image_url' => $selectionImgUrl
                ];
            }

            // Real Bundle Stock Availability check
            $bundleInStock = true;
            foreach ($requiredOptionsSalable as $optId => $salable) {
                if (!$salable) {
                    $bundleInStock = false;
                    break;
                }
            }

            $bundleSalableQty = 0.0;
            if ($bundleInStock) {
                if (!empty($optionMaxUnits)) {
                    $bundleSalableQty = (float) min(array_values($optionMaxUnits));
                } else {
                    $bundleSalableQty = 100.0; // Bundle with only optional items
                }
            }

            // Real Bundle Pricing
            $basePrice = (float) $product->getPrice();
            $minPrice = $basePrice;
            $maxPrice = $basePrice;

            foreach ($optionsMap as $opt) {
                $prices = array_map(fn($s) => (float) $s['price'], $opt['selections']);
                $minSel = min($prices);
                $maxSel = max($prices);

                if ($opt['required']) {
                    $minPrice += $minSel;
                }
                $maxPrice += $maxSel;
            }

            return [
                'bundle_options' => array_values($optionsMap),
                'is_in_stock' => $bundleInStock,
                'salable_qty' => $bundleSalableQty,
                'min_price' => (float) round($minPrice, 2),
                'max_price' => (float) round($maxPrice, 2)
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[MageCopilot_EdgeSync] Error resolving bundle data: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param Product $product
     * @param int $websiteId
     * @param string $parentImageUrl
     * @return array<string, mixed>|null
     */
    private function resolveGroupedData(Product $product, int $websiteId = 1, string $parentImageUrl = ''): ?array
    {
        if ($this->resourceConnection === null) {
            return null;
        }

        try {
            $conn = $this->resourceConnection->getConnection();
            $parentId = (int) $product->getId();

            $linkTable = $this->resourceConnection->getTableName('catalog_product_link');
            $entityTable = $this->resourceConnection->getTableName('catalog_product_entity');

            $select = $conn->select()
                ->from(['l' => $linkTable], ['link_id', 'linked_product_id'])
                ->join(['p' => $entityTable], 'l.linked_product_id = p.entity_id', ['sku'])
                ->where('l.product_id = ?', $parentId)
                ->where('l.link_type_id = ?', 3) // LINK_TYPE_GROUPED = 3
                ->order('l.link_id ASC');

            $rows = $conn->fetchAll($select);
            if (empty($rows)) {
                return null;
            }

            $groupedItems = [];
            $hasSalableStock = false;
            $totalSalableQty = 0.0;
            $minPrice = 0.0;
            $maxPrice = 0.0;

            foreach ($rows as $row) {
                $childId = (int) $row['linked_product_id'];
                $childSku = (string) $row['sku'];
                $childName = $this->resolveProductName($childId, $childSku);

                [$childInStock, $childQty] = $this->resolveStock($childSku, $childId, $websiteId);
                if ($childInStock && $childQty > 0) {
                    $hasSalableStock = true;
                    $totalSalableQty += $childQty;
                }

                $childPrice = $this->getProductDecimalAttribute($childId, 'price') ?? 0.0;
                $childSpecialPrice = $this->getProductDecimalAttribute($childId, 'special_price');
                $effectiveChildPrice = ($childSpecialPrice !== null && $childSpecialPrice > 0 && $childSpecialPrice < $childPrice)
                    ? $childSpecialPrice
                    : $childPrice;

                if ($effectiveChildPrice > 0) {
                    if ($minPrice <= 0 || $effectiveChildPrice < $minPrice) {
                        $minPrice = $effectiveChildPrice;
                    }
                    if ($effectiveChildPrice > $maxPrice) {
                        $maxPrice = $effectiveChildPrice;
                    }
                }

                $childImgUrl = $this->resolveProductImage($childId, $parentImageUrl);

                $groupedItems[] = [
                    'id' => (string) $childId,
                    'sku' => $childSku,
                    'name' => $childName,
                    'price' => (float) $childPrice,
                    'special_price' => $childSpecialPrice !== null ? (float) $childSpecialPrice : null,
                    'is_in_stock' => $childInStock,
                    'salable_qty' => (float) $childQty,
                    'image_url' => $childImgUrl
                ];
            }

            return [
                'grouped_items' => $groupedItems,
                'is_in_stock' => $hasSalableStock,
                'salable_qty' => $totalSalableQty,
                'min_price' => $minPrice,
                'max_price' => $maxPrice > 0 ? $maxPrice : $minPrice
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[MageCopilot_EdgeSync] Error resolving grouped data: ' . $e->getMessage());
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

        if ($this->resourceConnection === null) {
            return ['cross_sell_skus' => [], 'up_sell_skus' => []];
        }

        try {
            $conn = $this->resourceConnection->getConnection();
            $productId = (int) $product->getId();

            $linkTable = $this->resourceConnection->getTableName('catalog_product_link');
            $entityTable = $this->resourceConnection->getTableName('catalog_product_entity');

            $select = $conn->select()
                ->from(['l' => $linkTable], ['link_type_id'])
                ->join(['p' => $entityTable], 'l.linked_product_id = p.entity_id', ['sku'])
                ->where('l.product_id = ?', $productId);

            $links = $conn->fetchAll($select);

            foreach ($links as $row) {
                if ((int) $row['link_type_id'] === 5) {
                    $crossSellSkus[] = (string) $row['sku'];
                } elseif ((int) $row['link_type_id'] === 4) {
                    $upSellSkus[] = (string) $row['sku'];
                }
            }
        } catch (\Throwable) {
            // fallback
        }

        return [
            'cross_sell_skus' => array_values(array_unique($crossSellSkus)),
            'up_sell_skus' => array_values(array_unique($upSellSkus))
        ];
    }

    private function resolveSwatchValue(int $optionId, string $label, string $attributeCode): string
    {
        if ($attributeCode !== 'color') {
            return $label;
        }

        if (isset($this->swatchCache[$optionId])) {
            return $this->swatchCache[$optionId];
        }

        if ($this->resourceConnection !== null && $optionId > 0) {
            try {
                $conn = $this->resourceConnection->getConnection();
                $tableName = $this->resourceConnection->getTableName('eav_attribute_option_swatch');
                $select = $conn->select()
                    ->from($tableName, ['value', 'type'])
                    ->where('option_id = ?', $optionId)
                    ->order('store_id DESC')
                    ->limit(1);
                $row = $conn->fetchRow($select);
                if ($row && !empty($row['value'])) {
                    $swatchVal = (string) $row['value'];
                    if (str_starts_with($swatchVal, '#')) {
                        return $this->swatchCache[$optionId] = $swatchVal;
                    }
                    if ((int) $row['type'] === 1 && preg_match('/^[a-f0-9]{3,6}$/i', $swatchVal)) {
                        return $this->swatchCache[$optionId] = '#' . $swatchVal;
                    }
                }
            } catch (\Throwable) {
                // fallback to color map
            }
        }

        $colorMap = [
            'black' => '#111827',
            'white' => '#f8fafc',
            'navy' => '#1e3a8a',
            'blue' => '#2563eb',
            'olive' => '#365314',
            'green' => '#15803d',
            'red' => '#dc2626',
            'crimson' => '#991b1b',
            'grey' => '#64748b',
            'gray' => '#64748b',
            'amber' => '#d97706',
            'yellow' => '#eab308',
            'purple' => '#7c3aed',
            'orange' => '#ea580c'
        ];

        $cleanLabel = strtolower(trim($label));
        foreach ($colorMap as $k => $hex) {
            if (str_contains($cleanLabel, $k)) {
                return $this->swatchCache[$optionId] = $hex;
            }
        }

        return $this->swatchCache[$optionId] = '#4b5563';
    }

    /**
     * Resolve child product name dynamically from catalog EAV tables with SKU fallback.
     */
    private function resolveProductName(int $productId, string $sku): string
    {
        if (isset($this->productNameCache[$sku])) {
            return $this->productNameCache[$sku];
        }

        $name = $this->getProductVarcharAttribute($productId, 'name');
        if ($name && trim($name) !== '') {
            return $this->productNameCache[$sku] = trim($name);
        }

        return $this->productNameCache[$sku] = $sku;
    }

    /**
     * Resolve product image URL from catalog EAV tables.
     */
    private function resolveProductImage(int $productId, string $fallbackUrl = ''): string
    {
        $imageFile = $this->getProductVarcharAttribute($productId, 'image');
        if ($imageFile && $imageFile !== 'no_selection') {
            return $this->mediaBaseUrl . 'catalog/product' . $imageFile;
        }
        return $fallbackUrl;
    }

    private function getAttributeId(string $attributeCode, string $backendType): ?int
    {
        $key = "{$attributeCode}_{$backendType}";
        if (isset($this->attributeIdCache[$key])) {
            return $this->attributeIdCache[$key];
        }

        if ($this->resourceConnection === null) {
            return null;
        }

        try {
            $conn = $this->resourceConnection->getConnection();
            $select = $conn->select()
                ->from($this->resourceConnection->getTableName('eav_attribute'), ['attribute_id'])
                ->where('attribute_code = ?', $attributeCode)
                ->where('backend_type = ?', $backendType)
                ->where('entity_type_id = ?', 4)
                ->limit(1);
            $attrId = $conn->fetchOne($select);
            if ($attrId) {
                return $this->attributeIdCache[$key] = (int) $attrId;
            }
        } catch (\Throwable) {
            // fallback
        }

        return null;
    }

    private function getProductVarcharAttribute(int $productId, string $attributeCode): ?string
    {
        $attrId = $this->getAttributeId($attributeCode, 'varchar');
        if (!$attrId || $this->resourceConnection === null) {
            return null;
        }

        try {
            $conn = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
            $select = $conn->select()
                ->from($table, ['value'])
                ->where('entity_id = ?', $productId)
                ->where('attribute_id = ?', $attrId)
                ->order('store_id DESC')
                ->limit(1);
            $val = $conn->fetchOne($select);
            if (is_string($val) && $val !== '' && $val !== 'no_selection') {
                return $val;
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getProductDecimalAttribute(int $productId, string $attributeCode): ?float
    {
        $attrId = $this->getAttributeId($attributeCode, 'decimal');
        if (!$attrId || $this->resourceConnection === null) {
            return null;
        }

        try {
            $conn = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('catalog_product_entity_decimal');
            $select = $conn->select()
                ->from($table, ['value'])
                ->where('entity_id = ?', $productId)
                ->where('attribute_id = ?', $attrId)
                ->order('store_id DESC')
                ->limit(1);
            $val = $conn->fetchOne($select);
            if (is_numeric($val)) {
                return (float) $val;
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

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
     * Resolve stock availability and quantity using MSI StockResolver with legacy fallback.
     *
     * @param string $sku
     * @param int $productId
     * @param int $websiteId
     * @return array{0: bool, 1: float}
     */
    private function resolveStock(string $sku, int $productId, int $websiteId = 1): array
    {
        $stockId = 1;
        if ($this->stockResolver !== null) {
            try {
                $website = $this->storeManager->getWebsite($websiteId);
                $websiteCode = (string) $website->getCode();
                $stock = $this->stockResolver->execute(
                    SalesChannelInterface::TYPE_WEBSITE,
                    $websiteCode
                );
                $stockId = (int) $stock->getStockId();
            } catch (\Throwable) {
                $stockId = 1;
            }
        }

        if ($this->isSalable !== null && $this->salableQty !== null) {
            try {
                $isAvailable = (bool) $this->isSalable->execute($sku, $stockId);
                $qty = $isAvailable ? (float) $this->salableQty->execute($sku, $stockId) : 0.0;
                return [$isAvailable, $qty];
            } catch (\Magento\Framework\Exception\NoSuchEntityException) {
                // If product is not linked to this MSI stock, it is out of stock
                return [false, 0.0];
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    '[MageCopilot_EdgeSync] MSI stock lookup failed for SKU "%s" on stock ID %d: %s',
                    $sku,
                    $stockId,
                    $e->getMessage()
                ));
            }
        }

        try {
            $stockItem = $this->stockRegistry->getStockItem($productId);
            $manageStock = (bool) $stockItem->getManageStock();
            $qty = (float) $stockItem->getQty();
            $isInStock = (bool) $stockItem->getIsInStock();
            if ($manageStock) {
                $isInStock = $isInStock && ($qty > 0);
            }
            return [$isInStock, $qty];
        } catch (\Throwable) {
            return [false, 0.0];
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
            $this->mediaBaseUrl = ($store instanceof Store)
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
                    'nickname' => (string) ($row['nickname'] ?: 'Verified Customer'),
                    'summary' => (string) ($row['title'] ?: 'Verified Review'),
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
