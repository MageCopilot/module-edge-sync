<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Plugin;

use MageCopilot\EdgeSync\Model\Queue\Publisher;
use MageCopilot\EdgeSync\Plugin\InventoryReservationSyncPlugin;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InventoryReservationSyncPluginTest extends TestCase
{
    private Publisher&MockObject $publisherMock;
    private LoggerInterface $loggerStub;
    private PlaceReservationsForSalesEventInterface $subjectMock;
    private SalesChannelInterface $channelMock;
    private SalesEventInterface $eventMock;
    private InventoryReservationSyncPlugin $plugin;

    protected function setUp(): void
    {
        $this->publisherMock = $this->createMock(Publisher::class);
        $this->loggerStub = $this->createStub(LoggerInterface::class);
        $this->subjectMock = $this->createStub(PlaceReservationsForSalesEventInterface::class);
        $this->channelMock = $this->createStub(SalesChannelInterface::class);
        $this->eventMock = $this->createStub(SalesEventInterface::class);

        $this->plugin = new InventoryReservationSyncPlugin(
            $this->publisherMock,
            $this->loggerStub
        );
    }

    public function testAfterExecutePublishesAffectedSkus(): void
    {
        $item1 = $this->createStub(ItemToSellInterface::class);
        $item1->method('getSku')->willReturn('SKU-001');

        $item2 = $this->createStub(ItemToSellInterface::class);
        $item2->method('getSku')->willReturn('SKU-002');

        $this->publisherMock->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (array $data) {
                return $data['type'] === 'stock_delta_update'
                    && $data['skus'] === ['SKU-001', 'SKU-002']
                    && isset($data['timestamp']);
            }));

        $this->plugin->afterExecute(
            $this->subjectMock,
            null,
            [$item1, $item2],
            $this->channelMock,
            $this->eventMock
        );
    }

    public function testAfterExecuteDoesNotPublishWhenNoItems(): void
    {
        $this->publisherMock->expects($this->never())
            ->method('publish');

        $this->plugin->afterExecute(
            $this->subjectMock,
            null,
            [],
            $this->channelMock,
            $this->eventMock
        );
    }

    public function testAfterExecuteCatchesExceptionAndLogsCritical(): void
    {
        $item1 = $this->createStub(ItemToSellInterface::class);
        $item1->method('getSku')->willReturn('SKU-ERR');

        $this->publisherMock->expects($this->once())
            ->method('publish')
            ->willThrowException(new \RuntimeException('RabbitMQ connection lost'));

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->once())
            ->method('critical')
            ->with($this->stringContains('RabbitMQ connection lost'));

        $pluginWithMockLogger = new InventoryReservationSyncPlugin(
            $this->publisherMock,
            $loggerMock
        );

        $pluginWithMockLogger->afterExecute(
            $this->subjectMock,
            null,
            [$item1],
            $this->channelMock,
            $this->eventMock
        );
    }
}
