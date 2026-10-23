<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service\Seeder;

use Magento\Catalog\Api\Data\ProductTierPriceInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;

class ConfigurableSeeder
{
    public function __construct(
        private ProductFactory $productFactory,
        private ProductRepositoryInterface $productRepository,
        private ProductTierPriceInterfaceFactory $tierPriceFactory,
        private StockRegistryInterface $stockRegistry
    ) {
    }

    public function seedHoodieConfigurable(
        array $categories,
        int $colorAttrId,
        array $colorOptions,
        int $sizeAttrId,
        array $sizeOptions,
        $conn,
        callable $log
    ): int {
        $parentSku = 'MC-APEX-HOODIE';
        $catId = $categories['Men > Hoodies & Sweatshirts'] ?? 3;
        $childSkus = [];
        $childIds = [];

        $colors = [
            'Negro' => [
                'img' => 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?auto=format&fit=crop&w=800&q=80',
                'code' => 'BLK'
            ],
            'Azul Marino' => [
                'img' => 'https://images.unsplash.com/photo-1578587018452-892bacefd3f2?auto=format&fit=crop&w=800&q=80',
                'code' => 'NVY'
            ],
            'Verde Oliva' => [
                'img' => 'https://images.unsplash.com/photo-1509631179647-0177331693ae?auto=format&fit=crop&w=800&q=80',
                'code' => 'OLV'
            ]
        ];
        $sizes = ['S', 'M', 'L', 'XL'];

        foreach ($colors as $colorName => $colorData) {
            $cOpt = $colorOptions[$colorName]['id'];
            $cCode = $colorData['code'];

            foreach ($sizes as $sizeLabel) {
                $sOpt = $sizeOptions[$sizeLabel];
                $variantSku = "MC-APEX-HD-{$cCode}-{$sizeLabel}";

                // Deliberately make Navy XL out of stock for UI verification
                $stock = ($cCode === 'NVY' && $sizeLabel === 'XL') ? 0 : 35;
                $price = ($sizeLabel === 'XL') ? 99.00 : 95.00;

                try {
                    $variant = $this->productRepository->get($variantSku);
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
                $variant->setTaxClassId(2);
                $variant->setUrlKey(strtolower($variantSku));
                $variant->setWebsiteIds([1]);
                $variant->setCategoryIds([$catId]);
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
                $stockItem->setManageStock(1);
                $stockItem->setUseConfigManageStock(1);
                $this->stockRegistry->updateStockItemBySku($variantSku, $stockItem);

                $childSkus[] = $variantSku;
                $childIds[] = $variantId;
            }
        }

        // Save Parent Configurable Product
        try {
            $parent = $this->productRepository->get($parentSku);
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
        $parent->setTaxClassId(2);
        $parent->setDescription('<p>Nuestra sudadera insignia de alto rendimiento con matriz de variantes en color y talla. Confeccionada con fibra térmica transpirable y acabados termosellados de ultra-durabilidad.</p>');
        $parent->setShortDescription('Sudadera técnica insignia con ajuste ergonómico y aislamiento térmico.');
        $parent->setUrlKey('apex-hybrid-performance-hoodie');
        $parent->setWebsiteIds([1]);
        $parent->setCategoryIds([$catId]);

        $savedParent = $this->productRepository->save($parent);
        $parentId = (int) $savedParent->getId();

        // Ensure stock record for parent
        $parentStock = $this->stockRegistry->getStockItem($parentId);
        $parentStock->setQty(100);
        $parentStock->setIsInStock(true);
        $parentStock->setManageStock(1);
        $parentStock->setUseConfigManageStock(1);
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

        $log(" ✓ Producto Configurable '{$parentSku}' creado con " . count($childIds) . " variantes hijas.");
        return 1 + count($childIds);
    }

    public function seedSneakersConfigurable(
        array $categories,
        int $colorAttrId,
        array $colorOptions,
        int $sizeAttrId,
        array $sizeOptions,
        $conn,
        callable $log
    ): int {
        $parentSku = 'MC-AEROGLIDE-SNK';
        $catId = $categories['Men > Sneakers & Footwear'] ?? 6;
        $childIds = [];

        $colors = [
            'Negro' => ['code' => 'BLK'],
            'Blanco' => ['code' => 'WHT'],
            'Rojo Carmesí' => ['code' => 'RED']
        ];
        $sizes = ['40', '41', '42', '43', '44'];

        foreach ($colors as $colorName => $colorData) {
            $cOpt = $colorOptions[$colorName]['id'];
            $cCode = $colorData['code'];

            foreach ($sizes as $sizeLabel) {
                $sOpt = $sizeOptions[$sizeLabel];
                $variantSku = "MC-AG-SNK-{$cCode}-{$sizeLabel}";

                try {
                    $variant = $this->productRepository->get($variantSku);
                } catch (\Throwable) {
                    $variant = $this->productFactory->create();
                }

                $variant->setSku($variantSku);
                $variant->setName("AeroGlide Carbon Running Sneakers ({$colorName}, Talla {$sizeLabel})");
                $variant->setTypeId(ProductType::TYPE_SIMPLE);
                $variant->setAttributeSetId(4);
                $variant->setStatus(Status::STATUS_ENABLED);
                $variant->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE);
                $variant->setPrice(185.00);
                $variant->setWeight(0.6);
                $variant->setTaxClassId(2);
                $variant->setUrlKey(strtolower($variantSku));
                $variant->setWebsiteIds([1]);
                $variant->setCategoryIds([$catId]);
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
                $stockItem->setManageStock(1);
                $stockItem->setUseConfigManageStock(1);
                $this->stockRegistry->updateStockItemBySku($variantSku, $stockItem);

                $childIds[] = $variantId;
            }
        }

        try {
            $parent = $this->productRepository->get($parentSku);
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
        $parent->setTaxClassId(2);
        $parent->setDescription('<p>Zapatillas de running con placa propulsora de fibra de carbono y espuma de nitrógeno infusionado. Diseñadas para batir récords en media maratón y maratón.</p>');
        $parent->setShortDescription('Zapatillas de running de carbono con retorno de energía superior al 85%.');
        $parent->setUrlKey('aeroglide-carbon-running-sneakers');
        $parent->setWebsiteIds([1]);
        $parent->setCategoryIds([$catId]);

        $savedParent = $this->productRepository->save($parent);
        $parentId = (int) $savedParent->getId();

        $parentStock = $this->stockRegistry->getStockItem($parentId);
        $parentStock->setQty(75);
        $parentStock->setIsInStock(true);
        $parentStock->setManageStock(1);
        $parentStock->setUseConfigManageStock(1);
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

        $log(" ✓ Producto Configurable '{$parentSku}' creado con " . count($childIds) . " variantes.");
        return 1 + count($childIds);
    }

    public function saveIntAttribute($conn, int $productId, int $attributeId, int $value): void
    {
        $conn->query("
            INSERT INTO catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            VALUES ({$attributeId}, 0, {$productId}, {$value})
            ON DUPLICATE KEY UPDATE value = {$value};
        ");
    }
}
