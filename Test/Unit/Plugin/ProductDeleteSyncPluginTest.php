<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Plugin;

use MageCopilot\EdgeSync\Plugin\ProductDeleteSyncPlugin;
use MageCopilot\EdgeSync\Service\IndexerService;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductDeleteSyncPluginTest extends TestCase
{
    private IndexerService&MockObject $indexerServiceMock;
    private LoggerInterface $loggerStub;
    private PublisherInterface&MockObject $publisherMock;
    private ProductResource $subjectStub;
    private Product&MockObject $productMock;
    private ProductDeleteSyncPlugin $plugin;

    protected function setUp(): void
    {
        $this->indexerServiceMock = $this->createMock(IndexerService::class);
        $this->loggerStub = $this->createStub(LoggerInterface::class);
        $this->publisherMock = $this->createMock(PublisherInterface::class);
        $this->subjectStub = $this->createStub(ProductResource::class);
        $this->productMock = $this->createMock(Product::class);

        $this->productMock->method('getId')->willReturn(42);
        $this->productMock->method('getData')
            ->with('sku')
            ->willReturn('DELETED-SKU-001');

        $this->plugin = new ProductDeleteSyncPlugin(
            $this->indexerServiceMock,
            $this->loggerStub,
            $this->publisherMock
        );
    }

    public function testAroundDeleteResolvesParentsAndPublishesPayload(): void
    {
        $this->indexerServiceMock->expects($this->once())
            ->method('resolveParentProductIds')
            ->with([42])
            ->willReturn([100, 200]);

        $this->publisherMock->expects($this->once())
            ->method('publish')
            ->with(
                ProductDeleteSyncPlugin::TOPIC_NAME,
                $this->callback(function (string $message) {
                    $payload = json_decode($message, true);
                    return is_array($payload)
                        && ($payload['action'] ?? '') === 'delete'
                        && ($payload['ids'] ?? []) === [42]
                        && ($payload['parent_ids'] ?? []) === [100, 200]
                        && ($payload['skus'] ?? []) === ['DELETED-SKU-001'];
                })
            );

        $proceedCalled = false;
        $proceed = function ($object) use (&$proceedCalled) {
            $proceedCalled = true;
            return true;
        };

        $result = $this->plugin->aroundDelete(
            $this->subjectStub,
            $proceed,
            $this->productMock
        );

        $this->assertTrue($proceedCalled);
        $this->assertTrue($result);
    }

    public function testAroundDeleteFallsBackToDirectReindexWhenQueueFails(): void
    {
        $this->indexerServiceMock->expects($this->once())
            ->method('resolveParentProductIds')
            ->with([42])
            ->willReturn([100]);

        $this->publisherMock->expects($this->once())
            ->method('publish')
            ->willThrowException(new \RuntimeException('Broker unreachable'));

        $this->indexerServiceMock->expects($this->once())
            ->method('reindexList')
            ->with([42, 100])
            ->willReturn(['indexed' => 1, 'deleted' => 1, 'failed' => 0]);

        $proceed = fn($object) => true;

        $result = $this->plugin->aroundDelete(
            $this->subjectStub,
            $proceed,
            $this->productMock
        );

        $this->assertTrue($result);
    }
}

