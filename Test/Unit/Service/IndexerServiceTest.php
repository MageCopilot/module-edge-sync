<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Service;

use MageCopilot\EdgeSync\Model\Client\TypesenseClient;
use MageCopilot\EdgeSync\Model\Config;
use MageCopilot\EdgeSync\Model\Schema\ProductSchema;
use MageCopilot\EdgeSync\Service\IndexerService;
use MageCopilot\EdgeSync\Service\ProductDataMapper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IndexerServiceTest extends TestCase
{
    private TypesenseClient&MockObject $clientMock;
    private ProductSchema $schemaStub;
    private ProductDataMapper&MockObject $mapperMock;
    private ProductCollectionFactory&MockObject $collectionFactoryMock;
    private Config $configStub;
    private LoggerInterface $loggerStub;
    private StoreManagerInterface $storeManagerStub;
    private ResourceConnection&MockObject $resourceConnectionMock;
    private IndexerService $service;

    protected function setUp(): void
    {
        $this->clientMock = $this->createMock(TypesenseClient::class);
        $this->schemaStub = $this->createStub(ProductSchema::class);
        $this->mapperMock = $this->createMock(ProductDataMapper::class);
        $this->collectionFactoryMock = $this->createMock(ProductCollectionFactory::class);
        $this->configStub = $this->createStub(Config::class);
        $this->loggerStub = $this->createStub(LoggerInterface::class);
        $this->storeManagerStub = $this->createStub(StoreManagerInterface::class);
        $this->resourceConnectionMock = $this->createMock(ResourceConnection::class);

        $this->configStub->method('getCollectionName')->willReturn('products');

        $defaultStore = $this->createStub(StoreInterface::class);
        $defaultStore->method('getCode')->willReturn('default');
        $this->storeManagerStub->method('getStores')->willReturn([$defaultStore]);

        $this->service = new IndexerService(
            $this->clientMock,
            $this->schemaStub,
            $this->mapperMock,
            $this->collectionFactoryMock,
            $this->configStub,
            $this->loggerStub,
            $this->storeManagerStub,
            $this->resourceConnectionMock
        );
    }

    public function testReindexListReturnsZeroOnEmptyArray(): void
    {
        $result = $this->service->reindexList([]);
        $this->assertSame(['indexed' => 0, 'deleted' => 0, 'failed' => 0], $result);
    }

    public function testReindexIdsCallsReindexList(): void
    {
        $result = $this->service->reindexIds([]);
        $this->assertSame(['indexed' => 0, 'deleted' => 0, 'failed' => 0], $result);
    }

    public function testReindexListPurgesDeletedOrDisabledProductsFromTypesense(): void
    {
        // Setup empty collection returning 0 products for requested ID 99 (simulating deletion/disablement)
        $collectionMock = $this->createMock(ProductCollection::class);
        $collectionMock->method('addAttributeToSelect')->willReturnSelf();
        $collectionMock->method('addFieldToFilter')->willReturnSelf();
        $collectionMock->method('addAttributeToFilter')->willReturnSelf();
        $collectionMock->method('addMediaGalleryData')->willReturnSelf();
        $collectionMock->method('addTierPriceData')->willReturnSelf();
        $collectionMock->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->collectionFactoryMock->method('create')->willReturn($collectionMock);

        // Expect deleteDocument called for ID 99 on 'products' and 'products_default'
        $this->clientMock->expects($this->exactly(2))
            ->method('deleteDocument')
            ->with($this->logicalOr('products', 'products_default'), '99')
            ->willReturn(true);

        $result = $this->service->reindexList([99]);

        $this->assertSame(0, $result['indexed']);
        $this->assertSame(1, $result['deleted']);
        $this->assertSame(0, $result['failed']);
    }

    public function testResolveParentProductIdsQueriesRelations(): void
    {
        $connectionMock = $this->createMock(AdapterInterface::class);
        $this->resourceConnectionMock->method('getConnection')->willReturn($connectionMock);
        $this->resourceConnectionMock->method('getTableName')->willReturnArgument(0);

        $selectMock = $this->createMock(Select::class);
        $selectMock->method('from')->willReturnSelf();
        $selectMock->method('where')->willReturnSelf();

        $connectionMock->method('select')->willReturn($selectMock);
        $connectionMock->method('fetchCol')
            ->willReturnOnConsecutiveCalls(
                ['10', '11'], // super link parents
                ['20'],       // bundle parents
                ['30']        // grouped parents
            );

        $parentIds = $this->service->resolveParentProductIds([1, 2]);

        $this->assertEquals([10, 11, 20, 30], $parentIds);
    }
}

