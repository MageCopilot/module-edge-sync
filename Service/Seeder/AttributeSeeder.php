<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service\Seeder;

class AttributeSeeder
{
    /**
     * Ensures configurable super-attributes (color, size) exist and are filterable in catalog.
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $conn
     * @param callable $log
     * @return array{color: array{id: int, options: array<string, array{id: int, code: string, hex: string}>}, size: array{id: int, options: array<string, int>}}
     */
    public function ensureConfigurableAttributes($conn, callable $log): array
    {
        // Color attribute ID (default 93)
        $colorAttrId = (int) $conn->fetchOne(
            "SELECT attribute_id FROM eav_attribute WHERE entity_type_id = 4 AND attribute_code = 'color'"
        );
        if (!$colorAttrId) {
            $colorAttrId = 93;
        }

        // Ensure catalog_eav_attribute for color
        $conn->query("
            INSERT INTO catalog_eav_attribute (attribute_id, is_global, is_visible, is_searchable, is_filterable, is_comparable, apply_to)
            VALUES ({$colorAttrId}, 1, 1, 1, 1, 1, 'simple,virtual,configurable')
            ON DUPLICATE KEY UPDATE is_global = 1, is_filterable = 1, apply_to = 'simple,virtual,configurable';
        ");
        $conn->query("
            INSERT IGNORE INTO eav_entity_attribute (entity_type_id, attribute_set_id, attribute_group_id, attribute_id, sort_order)
            VALUES (4, 4, 7, {$colorAttrId}, 10);
        ");

        // Ensure size attribute
        $sizeAttrId = (int) $conn->fetchOne(
            "SELECT attribute_id FROM eav_attribute WHERE entity_type_id = 4 AND attribute_code = 'size'"
        );
        if (!$sizeAttrId) {
            $conn->insert('eav_attribute', [
                'entity_type_id' => 4,
                'attribute_code' => 'size',
                'backend_type' => 'int',
                'frontend_input' => 'select',
                'frontend_label' => 'Talla',
                'source_model' => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
                'is_required' => 0,
                'is_user_defined' => 1
            ]);
            $sizeAttrId = (int) $conn->lastInsertId();

            $conn->insert('catalog_eav_attribute', [
                'attribute_id' => $sizeAttrId,
                'is_global' => 1,
                'is_visible' => 1,
                'is_searchable' => 1,
                'is_filterable' => 1,
                'is_comparable' => 1,
                'used_in_product_listing' => 1,
                'apply_to' => 'simple,virtual,configurable'
            ]);
        } else {
            $conn->query("
                UPDATE catalog_eav_attribute 
                SET is_global = 1, is_filterable = 1, apply_to = 'simple,virtual,configurable'
                WHERE attribute_id = {$sizeAttrId}
            ");
        }

        $conn->query("
            INSERT IGNORE INTO eav_entity_attribute (entity_type_id, attribute_set_id, attribute_group_id, attribute_id, sort_order)
            VALUES (4, 4, 7, {$sizeAttrId}, 20);
        ");

        // Seed Color Options & Swatches
        $colorDefs = [
            'Negro' => ['code' => 'BLK', 'hex' => '#111827'],
            'Azul Marino' => ['code' => 'NVY', 'hex' => '#1e3a8a'],
            'Verde Oliva' => ['code' => 'OLV', 'hex' => '#365314'],
            'Blanco' => ['code' => 'WHT', 'hex' => '#f8fafc'],
            'Rojo Carmesí' => ['code' => 'RED', 'hex' => '#991b1b']
        ];
        $colorOptions = [];

        foreach ($colorDefs as $colorName => $meta) {
            $optId = (int) $conn->fetchOne(
                "SELECT o.option_id FROM eav_attribute_option o 
                 JOIN eav_attribute_option_value v ON o.option_id = v.option_id 
                 WHERE o.attribute_id = :aid AND v.value = :val",
                ['aid' => $colorAttrId, 'val' => $colorName]
            );

            if (!$optId) {
                $conn->insert('eav_attribute_option', ['attribute_id' => $colorAttrId, 'sort_order' => count($colorOptions) + 1]);
                $optId = (int) $conn->lastInsertId();
                $conn->insert('eav_attribute_option_value', ['option_id' => $optId, 'store_id' => 0, 'value' => $colorName]);
                $conn->insert('eav_attribute_option_value', ['option_id' => $optId, 'store_id' => 1, 'value' => $colorName]);
            }

            // Swatch
            $conn->query("
                INSERT INTO eav_attribute_option_swatch (option_id, store_id, type, value)
                VALUES ({$optId}, 0, 1, '{$meta['hex']}')
                ON DUPLICATE KEY UPDATE value = '{$meta['hex']}';
            ");

            $colorOptions[$colorName] = [
                'id' => $optId,
                'code' => $meta['code'],
                'hex' => $meta['hex']
            ];
        }

        // Seed Size Options
        $sizeDefs = ['XS', 'S', 'M', 'L', 'XL', '40', '41', '42', '43', '44', '45'];
        $sizeOptions = [];

        foreach ($sizeDefs as $idx => $sizeLabel) {
            $optId = (int) $conn->fetchOne(
                "SELECT o.option_id FROM eav_attribute_option o 
                 JOIN eav_attribute_option_value v ON o.option_id = v.option_id 
                 WHERE o.attribute_id = :aid AND v.value = :val",
                ['aid' => $sizeAttrId, 'val' => $sizeLabel]
            );

            if (!$optId) {
                $conn->insert('eav_attribute_option', ['attribute_id' => $sizeAttrId, 'sort_order' => $idx + 1]);
                $optId = (int) $conn->lastInsertId();
                $conn->insert('eav_attribute_option_value', ['option_id' => $optId, 'store_id' => 0, 'value' => $sizeLabel]);
                $conn->insert('eav_attribute_option_value', ['option_id' => $optId, 'store_id' => 1, 'value' => $sizeLabel]);
            }

            $sizeOptions[$sizeLabel] = $optId;
        }

        $log(' ✓ Super-atributos color y size sincronizados con opciones y swatches.');

        return [
            'color' => ['id' => $colorAttrId, 'options' => $colorOptions],
            'size' => ['id' => $sizeAttrId, 'options' => $sizeOptions]
        ];
    }
}
