<?php
/**
 * MageCopilot - AI-Powered Store Operations Platform
 *
 * @category  MageCopilot
 * @package   MageCopilot_EdgeSync
 * @author    MageCopilot Team
 * @copyright Copyright (c) 2026 MageCopilot
 * @license   OSL-3.0
 */

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service\Seeder;

use Magento\Catalog\Api\Data\ProductTierPriceInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Seeder for configurable products with color and size variation matrices
 */
class ConfigurableSeeder
{
    /**
     * @param ProductFactory $productFactory
     * @param ProductRepositoryInterface $productRepository
     * @param ProductTierPriceInterfaceFactory $tierPriceFactory
     * @param StockRegistryInterface $stockRegistry
     */
    public function __construct(
        private ProductFactory $productFactory,
        private ProductRepositoryInterface $productRepository,
        private ProductTierPriceInterfaceFactory $tierPriceFactory,
        private StockRegistryInterface $stockRegistry
    ) {
    }

    /**
     * Seed Hoodie Configurable Product with child simple variants
     *
     * @param array<string, int> $categories
     * @param int $colorAttrId
     * @param array<string, array{id: int, code: string, hex: string}> $colorOptions
     * @param int $sizeAttrId
     * @param array<int|string, int> $sizeOptions
     * @param AdapterInterface $conn
     * @param callable $log
     * @return int
     */
    public function seedHoodieConfigurable(
        array $categories,
        int $colorAttrId,
        array $colorOptions,
        int $sizeAttrId,
        array $sizeOptions,
        AdapterInterface $conn,
        callable $log
    ): int {
        $parentSku = 'MC-APEX-HOODIE';
        $catId = $categories['Men > Hoodies & Sweatshirts'] ?? 3;
        $childSkus = [];
        $childIds = [];

        $colors = [
            'Black' => [
                'img' => 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?auto=format&fit=crop&w=800&q=80',
                'code' => 'BLK'
            ],
            'Navy Blue' => [
                'img' => 'https://images.unsplash.com/photo-1578587018452-892bacefd3f2?auto=format&fit=crop&w=800&q=80',
                'code' => 'NVY'
            ],
            'Olive Green' => [
                'img' => 'https://images.unsplash.com/photo-1509631179647-0177331693ae?auto=format&fit=crop&w=800&q=80',
                'code' => 'OLV'
            ]
        ];
        $sizes = ['S', 'M', 'L', 'XL'];

        foreach ($colors as $colorName => $colorData) {
            $cOpt = $colorOptions[$colorName]['id'] ?? 0;
            $cCode = $colorData['code'];

            foreach ($sizes as $sizeLabel) {
                $sOpt = $sizeOptions[$sizeLabel] ?? 0;
                $variantSku = "MC-APEX-HD-{$cCode}-{$sizeLabel}";

                // Deliberately make Navy XL out of stock for UI verification
                $stock = ($cCode === 'NVY' && $sizeLabel === 'XL') ? 0 : 35;
                $price = ($sizeLabel === 'XL') ? 99.00 : 95.00;

                try {
                    $variant = $this->productRepository->get($variantSku);
                    if (!$variant instanceof Product) {
                        $variant = $this->productFactory->create();
                    }
                } catch (\Throwable) {
                    $variant = $this->productFactory->create();
                }

                $variant->setSku($variantSku);
                $variant->setName("Apex Hybrid Performance Hoodie ({$colorName}, {$sizeLabel})");
                $variant->setTypeId(ProductType::TYPE_SIMPLE);
                $variant->setAttributeSetId(4);
                $variant->setStatus(Status::STATUS_ENABLED);
                $variant->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE); // Zero search clutter
                $variant->setPrice($price);
                $variant->setWeight(0.8);
                $variant->setData('tax_class_id', 2);
                $variant->setUrlKey(strtolower($variantSku));
                $variant->setWebsiteIds([1]);
                $variant->setData('category_ids', [$catId]);
                $variant->setData('color', $cOpt);
                $variant->setData('size', $sOpt);

                // Tier Prices
                $tp1 = $this->tierPriceFactory->create();
                $tp1->setCustomerGroupId(2); // Wholesale B2B
                $tp1->setQty(1);
                $tp1->setValue(58.00);

                $tp2 = $this->tierPriceFactory->create();
                $tp2->setCustomerGroupId(3); // Retailer VIP
                $tp2->setQty(1);
                $tp2->setValue(78.00);
                $variant->setTierPrices([$tp1, $tp2]);

                $savedVariant = $this->productRepository->save($variant);
                $variantId = (int) $savedVariant->getId();

                // Direct EAV attribute link for select options
                $this->saveIntAttribute($conn, $variantId, $colorAttrId, $cOpt);
                $this->saveIntAttribute($conn, $variantId, $sizeAttrId, $sOpt);

                // Stock update
                $stockItem = $this->stockRegistry->getStockItem($variantId);
                $stockItem->setQty($stock);
                $stockItem->setIsInStock($stock > 0);
                $stockItem->setManageStock(true);
                $stockItem->setUseConfigManageStock(true);
                $this->stockRegistry->updateStockItemBySku($variantSku, $stockItem);

                $childSkus[] = $variantSku;
                $childIds[] = $variantId;
            }
        }

        // Save Parent Configurable Product
        try {
            $parent = $this->productRepository->get($parentSku);
            if (!$parent instanceof Product) {
                $parent = $this->productFactory->create();
            }
        } catch (\Throwable) {
            $parent = $this->productFactory->create();
        }

        $parent->setSku($parentSku);
        $parent->setName('Apex Hybrid Performance Hoodie');
        $parent->setTypeId('configurable');
        $parent->setAttributeSetId(4);
        $parent->setStatus(Status::STATUS_ENABLED);
        $parent->setVisibility(Visibility::VISIBILITY_BOTH);
        $parent->setPrice(95.00);
        $parent->setWeight(0.8);
        $parent->setData('tax_class_id', 2);
        $parent->setData(
            'description',
            '<p>Our flagship high-performance hoodie with color and size variant matrix. '
            . 'Crafted with breathable thermal fibers and ultra-durable heat-sealed finishes.</p>'
        );
        $parent->setData(
            'short_description',
            'Flagship technical hoodie featuring ergonomic fit and thermal insulation.'
        );
        $parent->setUrlKey('apex-hybrid-performance-hoodie');
        $parent->setWebsiteIds([1]);
        $parent->setData('category_ids', [$catId]);

        $savedParent = $this->productRepository->save($parent);
        $parentId = (int) $savedParent->getId();

        // Ensure stock record for parent
        $parentStock = $this->stockRegistry->getStockItem($parentId);
        $parentStock->setQty(100);
        $parentStock->setIsInStock(true);
        $parentStock->setManageStock(true);
        $parentStock->setUseConfigManageStock(true);
        $this->stockRegistry->updateStockItemBySku($parentSku, $parentStock);

        // Link Super Attributes
        $conn->query("
            INSERT IGNORE INTO catalog_product_super_attribute (product_id, attribute_id, position)
            VALUES ({$parentId}, {$colorAttrId}, 0), ({$parentId}, {$sizeAttrId}, 1);
        ");

        // Link Children
        foreach ($childIds as $cId) {
            $conn->query("
                INSERT IGNORE INTO catalog_product_super_link (product_id, parent_id)
                VALUES ({$cId}, {$parentId});
            ");
            $conn->query("
                INSERT IGNORE INTO catalog_product_relation (parent_id, child_id)
                VALUES ({$parentId}, {$cId});
            ");
        }

        $log(" [OK] Configurable product '{$parentSku}' created with " . count($childIds) . " child variants.");
        return 1 + count($childIds);
    }

    /**
     * Seed Sneakers Configurable Product with child simple variants
     *
     * @param array<string, int> $categories
     * @param int $colorAttrId
     * @param array<string, array{id: int, code: string, hex: string}> $colorOptions
     * @param int $sizeAttrId
     * @param array<int|string, int> $sizeOptions
     * @param AdapterInterface $conn
     * @param callable $log
     * @return int
     */
    public function seedSneakersConfigurable(
        array $categories,
        int $colorAttrId,
        array $colorOptions,
        int $sizeAttrId,
        array $sizeOptions,
        AdapterInterface $conn,
        callable $log
    ): int {
        $parentSku = 'MC-AEROGLIDE-SNK';
        $catId = $categories['Men > Sneakers & Footwear'] ?? 6;
        $childIds = [];

        $colors = [
            'Black' => ['code' => 'BLK'],
            'White' => ['code' => 'WHT'],
            'Crimson Red' => ['code' => 'RED']
        ];
        $sizes = ['40', '41', '42', '43', '44'];

        foreach ($colors as $colorName => $colorData) {
            $cOpt = $colorOptions[$colorName]['id'] ?? 0;
            $cCode = $colorData['code'];

            foreach ($sizes as $sizeLabel) {
                $sOpt = $sizeOptions[$sizeLabel] ?? 0;
                $variantSku = "MC-AG-SNK-{$cCode}-{$sizeLabel}";

                try {
                    $variant = $this->productRepository->get($variantSku);
                    if (!$variant instanceof Product) {
                        $variant = $this->productFactory->create();
                    }
                } catch (\Throwable) {
                    $variant = $this->productFactory->create();
                }

                $variant->setSku($variantSku);
                $variant->setName("AeroGlide Carbon Running Sneakers ({$colorName}, Size {$sizeLabel})");
                $variant->setTypeId(ProductType::TYPE_SIMPLE);
                $variant->setAttributeSetId(4);
                $variant->setStatus(Status::STATUS_ENABLED);
                $variant->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE);
                $variant->setPrice(185.00);
                $variant->setWeight(0.6);
                $variant->setData('tax_class_id', 2);
                $variant->setUrlKey(strtolower($variantSku));
                $variant->setWebsiteIds([1]);
                $variant->setData('category_ids', [$catId]);
                $variant->setData('color', $cOpt);
                $variant->setData('size', $sOpt);

                $tp1 = $this->tierPriceFactory->create();
                $tp1->setCustomerGroupId(2);
                $tp1->setQty(1);
                $tp1->setValue(115.00);
                $variant->setTierPrices([$tp1]);

                $savedVariant = $this->productRepository->save($variant);
                $variantId = (int) $savedVariant->getId();

                $this->saveIntAttribute($conn, $variantId, $colorAttrId, $cOpt);
                $this->saveIntAttribute($conn, $variantId, $sizeAttrId, $sOpt);

                $stockItem = $this->stockRegistry->getStockItem($variantId);
                $stockItem->setQty(25);
                $stockItem->setIsInStock(true);
                $stockItem->setManageStock(true);
                $stockItem->setUseConfigManageStock(true);
                $this->stockRegistry->updateStockItemBySku($variantSku, $stockItem);

                $childIds[] = $variantId;
            }
        }

        try {
            $parent = $this->productRepository->get($parentSku);
            if (!$parent instanceof Product) {
                $parent = $this->productFactory->create();
            }
        } catch (\Throwable) {
            $parent = $this->productFactory->create();
        }

        $parent->setSku($parentSku);
        $parent->setName('AeroGlide Carbon Running Sneakers');
        $parent->setTypeId('configurable');
        $parent->setAttributeSetId(4);
        $parent->setStatus(Status::STATUS_ENABLED);
        $parent->setVisibility(Visibility::VISIBILITY_BOTH);
        $parent->setPrice(185.00);
        $parent->setWeight(0.6);
        $parent->setData('tax_class_id', 2);
        $parent->setData(
            'description',
            '<p>Carbon fiber plate running shoes engineered with nitrogen-infused foam. '
            . 'Designed to break records in half-marathon and marathon distances.</p>'
        );
        $parent->setData(
            'short_description',
            'Carbon running sneakers with over 85% energy return rate.'
        );
        $parent->setUrlKey('aeroglide-carbon-running-sneakers');
        $parent->setWebsiteIds([1]);
        $parent->setData('category_ids', [$catId]);

        $savedParent = $this->productRepository->save($parent);
        $parentId = (int) $savedParent->getId();

        $parentStock = $this->stockRegistry->getStockItem($parentId);
        $parentStock->setQty(75);
        $parentStock->setIsInStock(true);
        $parentStock->setManageStock(true);
        $parentStock->setUseConfigManageStock(true);
        $this->stockRegistry->updateStockItemBySku($parentSku, $parentStock);

        $conn->query("
            INSERT IGNORE INTO catalog_product_super_attribute (product_id, attribute_id, position)
            VALUES ({$parentId}, {$colorAttrId}, 0), ({$parentId}, {$sizeAttrId}, 1);
        ");

        foreach ($childIds as $cId) {
            $conn->query("
                INSERT IGNORE INTO catalog_product_super_link (product_id, parent_id)
                VALUES ({$cId}, {$parentId});
            ");
            $conn->query("
                INSERT IGNORE INTO catalog_product_relation (parent_id, child_id)
                VALUES ({$parentId}, {$cId});
            ");
        }

        $log(" [OK] Configurable product '{$parentSku}' created with " . count($childIds) . " variants.");
        return 1 + count($childIds);
    }

    /**
     * Save integer attribute directly in catalog_product_entity_int table
     *
     * @param AdapterInterface $conn
     * @param int $productId
     * @param int $attributeId
     * @param int $value
     * @return void
     */
    public function saveIntAttribute(AdapterInterface $conn, int $productId, int $attributeId, int $value): void
    {
        $conn->query("
            INSERT INTO catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            VALUES ({$attributeId}, 0, {$productId}, {$value})
            ON DUPLICATE KEY UPDATE value = {$value};
        ");
    }
}
