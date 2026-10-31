<?php

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Controller\Product;

use MageCopilot\EdgeSync\Controller\Adminhtml\Product\SurgicalSync;
use MageCopilot\EdgeSync\Model\Client\TypesenseClient;
use MageCopilot\EdgeSync\Model\Config as TypesenseConfig;
use MageCopilot\EdgeSync\Service\IndexerService;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SurgicalSyncTest extends TestCase
{
    private ProductRepositoryInterface $productRepositoryMock;
    private IndexerService $indexerServiceMock;
    private TypesenseClient $typesenseClientMock;
    private TypesenseConfig $typesenseConfigMock;
    private JsonFactory $resultJsonFactoryMock;
    private ScopeConfigInterface $scopeConfigMock;
    private LoggerInterface $loggerMock;
    private RequestInterface $requestMock;
    private Json $jsonResultMock;
    private SurgicalSync $controller;

    protected function setUp(): void
    {
        $contextMock = $this->createMock(Context::class);
        $this->requestMock = $this->createMock(RequestInterface::class);
        $contextMock->method('getRequest')->willReturn($this->requestMock);

        $this->productRepositoryMock = $this->createMock(ProductRepositoryInterface::class);
        $this->indexerServiceMock = $this->createMock(IndexerService::class);
        $this->typesenseClientMock = $this->createMock(TypesenseClient::class);
        $this->typesenseConfigMock = $this->createMock(TypesenseConfig::class);
        $this->resultJsonFactoryMock = $this->createMock(JsonFactory::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->jsonResultMock = $this->createMock(Json::class);
        $this->jsonResultMock->method('setData')->willReturnSelf();
        $this->resultJsonFactoryMock->method('create')->willReturn($this->jsonResultMock);

        $this->typesenseConfigMock->method('getCollectionName')->willReturn('products');

        $this->controller = new SurgicalSync(
            $contextMock,
            $this->productRepositoryMock,
            $this->indexerServiceMock,
            $this->typesenseClientMock,
            $this->typesenseConfigMock,
            $this->resultJsonFactoryMock,
            $this->scopeConfigMock,
            $this->loggerMock
        );
    }

    public function testExecuteFailsWhenProductIdIsMissing(): void
    {
        $this->requestMock->method('getParam')
            ->willReturnMap([
                ['product_id', null, null],
                ['id', null, null],
                ['action', 'inspect', 'inspect']
            ]);

        $this->jsonResultMock->expects($this->once())
            ->method('setData')
            ->with($this->callback(function (array $data) {
                return $data['success'] === false && str_contains($data['error'], 'Valid product ID');
            }));

        $this->controller->execute();
    }

    public function testExecuteInspectReturnsTripleTierMetrics(): void
    {
        $this->requestMock->method('getParam')
            ->willReturnMap([
                ['product_id', null, '42'],
                ['action', 'inspect', 'inspect']
            ]);

        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->method('getId')->willReturn(42);
        $productMock->method('getSku')->willReturn('SKU-BALL-VALVE');
        $productMock->method('getName')->willReturn('High Pressure Ball Valve');
        $productMock->method('getPrice')->willReturn(149.99);
        $productMock->method('getFinalPrice')->willReturn(149.99);
        $productMock->method('getStatus')->willReturn(1);
        $productMock->method('getQuantityAndStockStatus')->willReturn(['is_in_stock' => true, 'qty' => 14]);

        $this->productRepositoryMock->method('getById')->with(42)->willReturn($productMock);

        $this->typesenseClientMock->method('getDocument')
            ->with('products', '42')
            ->willReturn([
                'id' => '42',
                'sku' => 'SKU-BALL-VALVE',
                'price' => 149.99,
                'is_in_stock' => true,
                'stock_qty' => 14
            ]);

        $this->jsonResultMock->expects($this->once())
            ->method('setData')
            ->with($this->callback(function (array $data) {
                return $data['success'] === true
                    && $data['mariadb']['sku'] === 'SKU-BALL-VALVE'
                    && $data['typesense']['synced'] === true
                    && isset($data['cloudflare']['cache_tag']);
            }));

        $this->controller->execute();
    }

    public function testExecutePurgeSyncTriggersIndexerAndReturnsPurgedConfirmation(): void
    {
        $this->requestMock->method('getParam')
            ->willReturnMap([
                ['product_id', null, '42'],
                ['action', null, 'purge_sync'],
                ['action', 'inspect', 'purge_sync']
            ]);

        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->method('getId')->willReturn(42);
        $productMock->method('getSku')->willReturn('SKU-BALL-VALVE');
        $productMock->method('getName')->willReturn('High Pressure Ball Valve');
        $productMock->method('getPrice')->willReturn(149.99);
        $productMock->method('getFinalPrice')->willReturn(149.99);
        $productMock->method('getStatus')->willReturn(1);
        $productMock->method('getQuantityAndStockStatus')->willReturn(['is_in_stock' => true, 'qty' => 14]);

        $this->productRepositoryMock->method('getById')->with(42)->willReturn($productMock);

        $this->indexerServiceMock->expects($this->once())
            ->method('reindexList')
            ->with([42]);

        $this->jsonResultMock->expects($this->once())
            ->method('setData')
            ->with($this->callback(function (array $data) {
                return $data['success'] === true
                    && $data['purged'] === true
                    && str_contains($data['message'], 'Purga quirúrgica');
            }));

        $this->controller->execute();
    }
}
