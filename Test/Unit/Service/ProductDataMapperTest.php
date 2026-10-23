<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Service;

use MageCopilot\EdgeSync\Service\ProductDataMapper;
use Magento\Catalog\Api\Data\ProductTierPriceInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\UrlInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductDataMapperTest extends TestCase
{
    private CategoryCollectionFactory $categoryCollectionFactoryStub;
    private StockRegistryInterface $stockRegistryStub;
    private IsProductSalableInterface $isSalableStub;
    private GetProductSalableQtyInterface $salableQtyStub;
    private StoreManagerInterface $storeManagerStub;
    private LoggerInterface $loggerStub;
    private ProductDataMapper $mapper;

    protected function setUp(): void
    {
        $this->categoryCollectionFactoryStub = $this->createStub(CategoryCollectionFactory::class);
        $this->stockRegistryStub = $this->createStub(StockRegistryInterface::class);
        $this->isSalableStub = $this->createStub(IsProductSalableInterface::class);
        $this->salableQtyStub = $this->createStub(GetProductSalableQtyInterface::class);
        $this->storeManagerStub = $this->createStub(StoreManagerInterface::class);
        $this->loggerStub = $this->createStub(LoggerInterface::class);

        $storeStub = $this->createStub(Store::class);
        $storeStub->method('getBaseUrl')
            ->willReturn('https://example.com/media/');
        $this->storeManagerStub->method('getStore')->willReturn($storeStub);

        $categoryCollectionStub = $this->createStub(CategoryCollection::class);
        $category1 = $this->createStub(Category::class);
        $category1->method('getId')->willReturn(14);
        $category1->method('getName')->willReturn('Electronics');
        $category1->method('getUrlKey')->willReturn('electronics');

        $categoryCollectionStub->method('getIterator')->willReturn(new \ArrayIterator([$category1]));
        $this->categoryCollectionFactoryStub->method('create')->willReturn($categoryCollectionStub);

        $this->mapper = new ProductDataMapper(
            $this->categoryCollectionFactoryStub,
            $this->stockRegistryStub,
            $this->isSalableStub,
            $this->salableQtyStub,
            $this->storeManagerStub,
            $this->loggerStub
        );
    }

    public function testMapProducesExpectedTypesenseDocument(): void
    {
        $tierPriceStub = $this->createStub(ProductTierPriceInterface::class);
        $tierPriceStub->method('getCustomerGroupId')->willReturn(2);
        $tierPriceStub->method('getQty')->willReturn(1.0);
        $tierPriceStub->method('getValue')->willReturn(90.00);

        $productStub = new class extends Product {
            public function __construct()
            {
            }
            public function getId() { return 42; }
            public function getSku() { return 'TEST-SKU-001'; }
            public function getName() { return 'Premium Test Headphones'; }
            public function getDescription() { return '<p>Detailed description</p>'; }
            public function getShortDescription() { return 'Short desc'; }
            public function getPrice() { return 150.00; }
            public function getSpecialPrice() { return 120.00; }
            public function getSpecialFromDate() { return null; }
            public function getSpecialToDate() { return null; }
            public function getCategoryIds() { return [14]; }
            public function getImage() { return '/t/e/test.jpg'; }
            public function getMediaGalleryEntries() { return []; }
            public function getUrlKey() { return 'premium-test-headphones'; }
            public function getCreatedAt() { return '2026-01-01 00:00:00'; }
            public function getTierPrices() {
                return $this->getData('tier_prices') ?: [];
            }
        };
        $productStub->setData('tier_prices', [$tierPriceStub]);

        // MSI Stock
        $this->isSalableStub->method('execute')->willReturn(true);
        $this->salableQtyStub->method('execute')->willReturn(75.0);

        $doc = $this->mapper->map($productStub);

        $this->assertSame('42', $doc['id']);
        $this->assertSame('TEST-SKU-001', $doc['sku']);
        $this->assertSame('Premium Test Headphones', $doc['name']);
        $this->assertSame(150.00, $doc['price']);
        $this->assertSame(120.00, $doc['special_price']);
        $this->assertSame(120.00, $doc['price_group_0']);
        $this->assertSame(90.00, $doc['price_group_2']); // B2B Wholesale price
        $this->assertTrue($doc['is_in_stock']);
        $this->assertSame(75.0, $doc['salable_qty']);
        $this->assertSame([14], $doc['category_ids']);
        $this->assertSame(['Electronics'], $doc['category_names']);
        $this->assertSame(['electronics'], $doc['category_slugs']);
        $this->assertSame('https://example.com/media/catalog/product/t/e/test.jpg', $doc['image_url']);
    }

    public function testMapFallsBackToLegacyStockItemWhenMsiUnavailable(): void
    {
        $mapperWithoutMsi = new ProductDataMapper(
            $this->categoryCollectionFactoryStub,
            $this->stockRegistryStub,
            null,
            null,
            $this->storeManagerStub,
            $this->loggerStub
        );

        $productStub = new class extends Product {
            public function __construct()
            {
            }
            public function getId() { return 10; }
            public function getSku() { return 'LEGACY-SKU'; }
            public function getName() { return 'Legacy Product'; }
            public function getPrice() { return 50.00; }
            public function getSpecialPrice() { return null; }
            public function getCategoryIds() { return []; }
            public function getTierPrices() { return []; }
            public function getDescription() { return ''; }
            public function getShortDescription() { return ''; }
            public function getImage() { return null; }
            public function getMediaGalleryEntries() { return []; }
            public function getUrlKey() { return 'legacy-product'; }
            public function getCreatedAt() { return '2026-01-01'; }
        };

        $stockItemStub = $this->createStub(StockItemInterface::class);
        $stockItemStub->method('getIsInStock')->willReturn(true);
        $stockItemStub->method('getQty')->willReturn(15.0);
        $this->stockRegistryStub->method('getStockItem')->willReturn($stockItemStub);

        $doc = $mapperWithoutMsi->map($productStub);

        $this->assertTrue($doc['is_in_stock']);
        $this->assertSame(15.0, $doc['salable_qty']);
        $this->assertIsArray($doc['group_prices']);
    }

    public function testMapUsesDynamicCurrencyRatesAndCatalogRulesAndGroupPrices(): void
    {
        $currencyResolverStub = $this->createStub(\MageCopilot\EdgeSync\Service\CurrencyRateResolver::class);
        $currencyResolverStub->method('getRate')->willReturnCallback(function ($base, $target) {
            return match ($target) {
                'EUR' => 0.95,
                'GBP' => 0.82,
                'BRL' => 6.10,
                default => 1.0,
            };
        });

        $catalogRuleStub = $this->createStub(\MageCopilot\EdgeSync\Service\CatalogRulePriceResolver::class);
        $catalogRuleStub->method('getRulePrice')->willReturnCallback(function ($pid, $wid, $gid) {
            if ($gid === 2) {
                return 85.0; // B2B rule price
            }
            return 110.0;
        });

        $customMapper = new ProductDataMapper(
            $this->categoryCollectionFactoryStub,
            $this->stockRegistryStub,
            $this->isSalableStub,
            $this->salableQtyStub,
            $this->storeManagerStub,
            $this->loggerStub,
            null,
            null,
            null,
            $currencyResolverStub,
            $catalogRuleStub
        );

        $productStub = new class extends Product {
            public function __construct() {}
            public function getId() { return 88; }
            public function getSku() { return 'DYNAMIC-FX-SKU'; }
            public function getName() { return 'Dynamic FX Product'; }
            public function getPrice() { return 200.00; }
            public function getSpecialPrice() { return null; }
            public function getCategoryIds() { return []; }
            public function getTierPrices() { return []; }
            public function getDescription() { return ''; }
            public function getShortDescription() { return ''; }
            public function getImage() { return null; }
            public function getMediaGalleryEntries() { return []; }
            public function getUrlKey() { return 'dynamic-fx-sku'; }
            public function getCreatedAt() { return '2026-01-01'; }
        };

        $this->isSalableStub->method('execute')->willReturn(true);
        $this->salableQtyStub->method('execute')->willReturn(10.0);

        $doc = $customMapper->map($productStub);

        // Assert dynamic currency rates were applied
        $this->assertSame(190.0, $doc['price_eur']); // 200 * 0.95
        $this->assertSame(164.0, $doc['price_gbp']); // 200 * 0.82
        $this->assertSame(1220.0, $doc['price_brl']); // 200 * 6.10

        // Assert catalog rule price influenced group prices
        $this->assertSame(85.0, $doc['price_group_2']); // wholesale rule price

        // Assert nested group_prices structure
        $this->assertIsArray($doc['group_prices']);
        $groupIdsInDoc = array_column($doc['group_prices'], 'group_id');
        $this->assertContains(0, $groupIdsInDoc);
        $this->assertContains(2, $groupIdsInDoc);

        $group2Entry = null;
        foreach ($doc['group_prices'] as $gp) {
            if ($gp['group_id'] === 2) {
                $group2Entry = $gp;
                break;
            }
        }
        $this->assertNotNull($group2Entry);
        $this->assertSame(85.0, $group2Entry['price']);
    }
}

