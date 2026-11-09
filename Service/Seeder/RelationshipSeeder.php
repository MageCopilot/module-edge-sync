<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service\Seeder;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Seeder for product cross-sell and up-sell relationships
 */
class RelationshipSeeder
{
    /**
     * @param AdapterInterface $conn
     * @param callable $log
     * @return void
     */
    public function seedCrossSellLinks(AdapterInterface $conn, callable $log): void
    {
        $hoodieId = (int) $conn->fetchOne("SELECT entity_id FROM catalog_product_entity WHERE sku = 'MC-APEX-HOODIE'");
        $sneakersId = (int) $conn->fetchOne("SELECT entity_id FROM catalog_product_entity WHERE sku = 'MC-AEROGLIDE-SNK'");
        $backpackId = (int) $conn->fetchOne("SELECT entity_id FROM catalog_product_entity WHERE sku = 'MC-BAG-NOMAD-28'");
        $bundleId = (int) $conn->fetchOne("SELECT entity_id FROM catalog_product_entity WHERE sku = 'MC-PRO-BUNDLE'");

        if ($hoodieId && $sneakersId) {
            // Cross-sell (link_type_id = 5), Up-sell (link_type_id = 4)
            $conn->query("
                INSERT IGNORE INTO catalog_product_link (product_id, linked_product_id, link_type_id)
                VALUES ({$hoodieId}, {$sneakersId}, 5),
                       ({$hoodieId}, {$backpackId}, 5),
                       ({$hoodieId}, {$bundleId}, 4),
                       ({$sneakersId}, {$hoodieId}, 5),
                       ({$sneakersId}, {$backpackId}, 5);
            ");
            $log(' [MageCopilot] Cross-sell and Up-sell links registered between products.');
        }
    }
}
