<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Model\Schema;

class ProductSchema
{
    /**
     * Get collection schema definition for Typesense.
     *
     * @param string $collectionName
     * @return array<string, mixed>
     */
    public function getSchema(string $collectionName = 'products'): array
    {
        return [
            'name' => $collectionName,
            'enable_nested_fields' => true,
            'synonym_sets' => ['ecommerce_synonyms'],
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'sku', 'type' => 'string', 'facet' => true],
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'product_type', 'type' => 'string', 'facet' => true],
                ['name' => 'description', 'type' => 'string', 'optional' => true],
                ['name' => 'short_description', 'type' => 'string', 'optional' => true],
                ['name' => 'description_en', 'type' => 'string', 'optional' => true],
                ['name' => 'description_es', 'type' => 'string', 'optional' => true],
                ['name' => 'description_pt', 'type' => 'string', 'optional' => true],
                ['name' => 'short_description_en', 'type' => 'string', 'optional' => true],
                ['name' => 'short_description_es', 'type' => 'string', 'optional' => true],
                ['name' => 'short_description_pt', 'type' => 'string', 'optional' => true],
                ['name' => 'price', 'type' => 'float', 'facet' => true],
                ['name' => 'special_price', 'type' => 'float', 'optional' => true, 'facet' => true],
                ['name' => 'price_usd', 'type' => 'float', 'optional' => true, 'facet' => true],
                ['name' => 'price_eur', 'type' => 'float', 'optional' => true, 'facet' => true],
                ['name' => 'price_gbp', 'type' => 'float', 'optional' => true, 'facet' => true],
                ['name' => 'price_brl', 'type' => 'float', 'optional' => true, 'facet' => true],
                ['name' => 'special_price_usd', 'type' => 'float', 'optional' => true, 'facet' => true],
                ['name' => 'special_price_eur', 'type' => 'float', 'optional' => true, 'facet' => true],
                ['name' => 'special_price_gbp', 'type' => 'float', 'optional' => true, 'facet' => true],
                ['name' => 'special_price_brl', 'type' => 'float', 'optional' => true, 'facet' => true],
                ['name' => 'price_group_0', 'type' => 'float', 'optional' => true, 'facet' => true],
                ['name' => 'store_ids', 'type' => 'int32[]', 'optional' => true, 'facet' => true],
                ['name' => 'stores', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                ['name' => 'category_ids', 'type' => 'int32[]', 'facet' => true],
                ['name' => 'category_names', 'type' => 'string[]', 'facet' => true],
                ['name' => 'category_slugs', 'type' => 'string[]', 'facet' => true],
                ['name' => 'is_in_stock', 'type' => 'bool', 'facet' => true],
                ['name' => 'salable_qty', 'type' => 'float'],
                ['name' => 'image_url', 'type' => 'string', 'optional' => true],
                ['name' => 'images', 'type' => 'string[]', 'optional' => true],
                ['name' => 'url_key', 'type' => 'string', 'facet' => true],
                ['name' => 'color', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'size', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'brand', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'created_at', 'type' => 'int64'],
                ['name' => 'configurable_options', 'type' => 'object[]', 'optional' => true],
                ['name' => 'variants', 'type' => 'object[]', 'optional' => true],
                ['name' => 'bundle_options', 'type' => 'object[]', 'optional' => true],
                ['name' => 'cross_sell_skus', 'type' => 'string[]', 'optional' => true],
                ['name' => 'up_sell_skus', 'type' => 'string[]', 'optional' => true],
                ['name' => 'rating_summary', 'type' => 'float', 'optional' => true, 'facet' => true],
                ['name' => 'reviews_count', 'type' => 'int32', 'optional' => true, 'facet' => true],
                ['name' => 'reviews', 'type' => 'object[]', 'optional' => true],
                ['name' => 'embedding', 'type' => 'float[]', 'num_dim' => 512, 'optional' => true]
            ],
            'default_sorting_field' => 'created_at'
        ];
    }
}
