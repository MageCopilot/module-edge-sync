<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Cron;

use MageCopilot\EdgeSync\Cron\PriceWindowCheck;
use MageCopilot\EdgeSync\Service\IndexerService;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PriceWindowCheckTest extends TestCase
{
    private ProductCollectionFactory $collectionFactoryMock;
    private TimezoneInterface $timezoneMock;
    private LoggerInterface $loggerMock;
    private PublisherInterface $publisherMock;
    private IndexerService $indexerServiceMock;

    protected function setUp(): void
    {
        $this->collectionFactoryMock = $this->createMock(ProductCollectionFactory::class);
        $this->timezoneMock = $this->createMock(TimezoneInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->publisherMock = $this->createMock(PublisherInterface::class);
        $this->indexerServiceMock = $this->createMock(IndexerService::class);

        $now = new \DateTimeImmutable('2026-11-09 12:00:00');
        $this->timezoneMock->method('date')->willReturn($now);
    }

    public function testExecutePublishesToRabbitMqWhenProductsFound(): void
    {
        $product1 = $this->createMock(Product::class);
        $product1->method('getId')->willReturn(101);
        $product1->method('getSku')->willReturn('SKU-PROMO-1');

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$product1]));

        $this->collectionFactoryMock->method('create')->willReturn($collection);

        $this->publisherMock->expects($this->once())
            ->method('publish')
            ->with(
                PriceWindowCheck::TOPIC_NAME,
                $this->callback(function (string $json) {
                    $data = json_decode($json, true);
                    return $data['ids'] === [101]
                        && $data['skus'] === ['SKU-PROMO-1']
                        && $data['transition_date'] === '2026-11-09';
                })
            );

        $cron = new PriceWindowCheck(
            $this->collectionFactoryMock,
            $this->timezoneMock,
            $this->loggerMock,
            $this->publisherMock,
            $this->indexerServiceMock
        );

        $cron->execute();
    }

    public function testExecuteDoesNothingWhenNoTransitions(): void
    {
        $collection = $this->createMock(ProductCollection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->collectionFactoryMock->method('create')->willReturn($collection);

        $this->publisherMock->expects($this->never())->method('publish');
        $this->indexerServiceMock->expects($this->never())->method('reindexList');

        $cron = new PriceWindowCheck(
            $this->collectionFactoryMock,
            $this->timezoneMock,
            $this->loggerMock,
            $this->publisherMock,
            $this->indexerServiceMock
        );

        $cron->execute();
    }

    public function testExecuteFallsBackToDirectReindexWhenQueueFails(): void
    {
        $product1 = $this->createMock(Product::class);
        $product1->method('getId')->willReturn(102);
        $product1->method('getSku')->willReturn('SKU-FALLBACK-1');

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$product1]));

        $this->collectionFactoryMock->method('create')->willReturn($collection);

        $this->publisherMock->method('publish')
            ->willThrowException(new \RuntimeException('Connection to RabbitMQ lost'));

        $this->indexerServiceMock->expects($this->once())
            ->method('reindexList')
            ->with([102])
            ->willReturn(['indexed' => 1, 'deleted' => 0, 'failed' => 0]);

        $cron = new PriceWindowCheck(
            $this->collectionFactoryMock,
            $this->timezoneMock,
            $this->loggerMock,
            $this->publisherMock,
            $this->indexerServiceMock
        );

        $cron->execute();
    }
}

