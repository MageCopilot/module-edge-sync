<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service\Seeder;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Seeder for bundle products with composite options and selections
 */
class BundleSeeder
{
    /**
     * @param ProductFactory $productFactory
     * @param ProductRepositoryInterface $productRepository
     * @param StockRegistryInterface $stockRegistry
     */
    public function __construct(
        private ProductFactory $productFactory,
        private ProductRepositoryInterface $productRepository,
        private StockRegistryInterface $stockRegistry
    ) {
    }

    /**
     * @param array<string, int> $categories
     * @param AdapterInterface $conn
     * @param callable $log
     * @return int
     */
    public function seedAthleteBundle(array $categories, AdapterInterface $conn, callable $log): int
    {
        $bundleSku = 'MC-PRO-BUNDLE';
        $catId = $categories['Gear & Accessories > Technical Backpacks'] ?? 14;

        try {
            $bundle = $this->productRepository->get($bundleSku);
            if (!$bundle instanceof Product) {
                $bundle = $this->productFactory->create();
            }
        } catch (\Throwable) {
            $bundle = $this->productFactory->create();
        }

        $bundle->setSku($bundleSku);
        $bundle->setName('Pro Athlete Performance Bundle');
        $bundle->setTypeId('bundle');
        $bundle->setAttributeSetId(4);
        $bundle->setStatus(Status::STATUS_ENABLED);
        $bundle->setVisibility(Visibility::VISIBILITY_BOTH);
        $bundle->setPrice(199.00);
        $bundle->setData('special_price', 179.00);
        $bundle->setData('price_type', 1); // Fixed Price
        $bundle->setData('sku_type', 1);
        $bundle->setData('weight_type', 1);
        $bundle->setData('price_view', 1);
        $bundle->setData('shipment_type', 0);
        $bundle->setWeight(1.5);
        $bundle->setData('tax_class_id', 2);
        $bundle->setData('description', '<p>The ultimate performance equipment kit for high-demand athletes. Includes waterproof technical backpack, Supima organic cotton tee, and insulated sports vacuum flask with special savings.</p>');
        $bundle->setData('short_description', 'Pro training kit featuring technical backpack, performance tee, and insulated flask.');
        $bundle->setUrlKey('pro-athlete-performance-bundle');
        $bundle->setWebsiteIds([1]);
        $bundle->setData('category_ids', [$catId]);

        $savedBundle = $this->productRepository->save($bundle);
        $bundleId = (int) $savedBundle->getId();

        $stockItem = $this->stockRegistry->getStockItem($bundleId);
        $stockItem->setQty(50);
        $stockItem->setIsInStock(true);
        $stockItem->setManageStock(true);
        $stockItem->setUseConfigManageStock(true);
        $this->stockRegistry->updateStockItemBySku($bundleSku, $stockItem);

        // Get simple product IDs for bundle selections
        $backpackId = (int) $conn->fetchOne("SELECT entity_id FROM catalog_product_entity WHERE sku = 'MC-BAG-NOMAD-28'");
        $teeId = (int) $conn->fetchOne("SELECT entity_id FROM catalog_product_entity WHERE sku = 'MC-TS-ORGANIC-WHT'");
        $hoodieId = (int) $conn->fetchOne("SELECT entity_id FROM catalog_product_entity WHERE sku = 'MC-HD-BLK-01'");
        $flaskId = (int) $conn->fetchOne("SELECT entity_id FROM catalog_product_entity WHERE sku = 'MC-FLASK-VACUUM-750'");

        if ($backpackId && $teeId) {
            // Clean prior bundle options if re-seeding
            $conn->query("DELETE FROM catalog_product_bundle_option WHERE parent_id = {$bundleId}");

            // Option 1: Backpack (Required, select)
            $conn->insert('catalog_product_bundle_option', [
                'parent_id' => $bundleId,
                'required' => 1,
                'position' => 1,
                'type' => 'select'
            ]);
            $opt1Id = (int) $conn->fetchOne('SELECT LAST_INSERT_ID()');
            $conn->insert('catalog_product_bundle_option_value', [
                'option_id' => $opt1Id,
                'store_id' => 0,
                'title' => 'Technical Backpack (Base)',
                'parent_product_id' => $bundleId
            ]);
            $conn->insert('catalog_product_bundle_selection', [
                'option_id' => $opt1Id,
                'parent_product_id' => $bundleId,
                'product_id' => $backpackId,
                'position' => 1,
                'is_default' => 1,
                'selection_price_type' => 0,
                'selection_price_value' => 0,
                'selection_qty' => 1,
                'selection_can_change_qty' => 0
            ]);

            // Option 2: Performance Apparel (Required, select)
            $conn->insert('catalog_product_bundle_option', [
                'parent_id' => $bundleId,
                'required' => 1,
                'position' => 2,
                'type' => 'select'
            ]);
            $opt2Id = (int) $conn->fetchOne('SELECT LAST_INSERT_ID()');
            $conn->insert('catalog_product_bundle_option_value', [
                'option_id' => $opt2Id,
                'store_id' => 0,
                'title' => 'Training Top Apparel',
                'parent_product_id' => $bundleId
            ]);
            $conn->insert('catalog_product_bundle_selection', [
                'option_id' => $opt2Id,
                'parent_product_id' => $bundleId,
                'product_id' => $teeId,
                'position' => 1,
                'is_default' => 1,
                'selection_price_type' => 0,
                'selection_price_value' => 0,
                'selection_qty' => 1,
                'selection_can_change_qty' => 0
            ]);
            if ($hoodieId) {
                $conn->insert('catalog_product_bundle_selection', [
                    'option_id' => $opt2Id,
                    'parent_product_id' => $bundleId,
                    'product_id' => $hoodieId,
                    'position' => 2,
                    'is_default' => 0,
                    'selection_price_type' => 0,
                    'selection_price_value' => 45.0,
                    'selection_qty' => 1,
                    'selection_can_change_qty' => 0
                ]);
            }

            // Option 3: Hydration Accessory (Optional, checkbox)
            if ($flaskId) {
                $conn->insert('catalog_product_bundle_option', [
                    'parent_id' => $bundleId,
                    'required' => 0,
                    'position' => 3,
                    'type' => 'checkbox'
                ]);
                $opt3Id = (int) $conn->fetchOne('SELECT LAST_INSERT_ID()');
                $conn->insert('catalog_product_bundle_option_value', [
                    'option_id' => $opt3Id,
                    'store_id' => 0,
                    'title' => 'Optional Accessory',
                    'parent_product_id' => $bundleId
                ]);
                $conn->insert('catalog_product_bundle_selection', [
                    'option_id' => $opt3Id,
                    'parent_product_id' => $bundleId,
                    'product_id' => $flaskId,
                    'position' => 1,
                    'is_default' => 0,
                    'selection_price_type' => 0,
                    'selection_price_value' => 18.0,
                    'selection_qty' => 1,
                    'selection_can_change_qty' => 1
                ]);
            }

            // Link bundle children in catalog_product_relation for MSI composite stock indexation
            $bundleChildIds = array_filter([$backpackId, $teeId, $hoodieId, $flaskId]);
            foreach ($bundleChildIds as $childId) {
                $conn->query("
                    INSERT IGNORE INTO catalog_product_relation (parent_id, child_id)
                    VALUES ({$bundleId}, {$childId});
                ");
            }
        }

        $log(" [OK] Bundle product '{$bundleSku}' created with configurable options.");
        return 1;
    }
}
