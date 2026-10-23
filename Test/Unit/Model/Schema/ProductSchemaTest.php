<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Model\Schema;

use MageCopilot\EdgeSync\Model\Schema\ProductSchema;
use PHPUnit\Framework\TestCase;

class ProductSchemaTest extends TestCase
{
    private ProductSchema $schema;

    protected function setUp(): void
    {
        $this->schema = new ProductSchema();
    }

    public function testGetSchemaReturnsExpectedStructure(): void
    {
        $result = $this->schema->getSchema('custom_products');

        $this->assertSame('custom_products', $result['name']);
        $this->assertSame('created_at', $result['default_sorting_field']);
        $this->assertIsArray($result['fields']);

        $fieldNames = array_column($result['fields'], 'name');
        $this->assertContains('id', $fieldNames);
        $this->assertContains('sku', $fieldNames);
        $this->assertContains('name', $fieldNames);
        $this->assertContains('price', $fieldNames);
        $this->assertContains('price_group_0', $fieldNames);
        $this->assertContains('price_group_2', $fieldNames);
        $this->assertContains('is_in_stock', $fieldNames);
        $this->assertContains('salable_qty', $fieldNames);
        $this->assertContains('category_ids', $fieldNames);
        $this->assertContains('category_slugs', $fieldNames);
    }
}
