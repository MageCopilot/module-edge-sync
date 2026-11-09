<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Plugin;

use MageCopilot\EdgeSync\Model\Queue\Publisher;
use MageCopilot\EdgeSync\Plugin\SourceItemsSaveSyncPlugin;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SourceItemsSaveSyncPluginTest extends TestCase
{
    private Publisher $publisherMock;
    private LoggerInterface $loggerMock;
    private SourceItemsSaveSyncPlugin $plugin;

    protected function setUp(): void
    {
        $this->publisherMock = $this->createMock(Publisher::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->plugin = new SourceItemsSaveSyncPlugin(
            $this->publisherMock,
            $this->loggerMock
        );
    }

    public function testAfterExecutePublishesAffectedSkus(): void
    {
        $subject = $this->createMock(SourceItemsSaveInterface::class);

        $item1 = $this->createMock(SourceItemInterface::class);
        $item1->method('getSku')->willReturn('SKU-100');

        $item2 = $this->createMock(SourceItemInterface::class);
        $item2->method('getSku')->willReturn('SKU-200');

        $itemDuplicate = $this->createMock(SourceItemInterface::class);
        $itemDuplicate->method('getSku')->willReturn('SKU-100');

        $this->publisherMock->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (array $payload) {
                return $payload['type'] === 'stock_delta_update'
                    && $payload['skus'] === ['SKU-100', 'SKU-200']
                    && isset($payload['timestamp']);
            }));

        $this->plugin->afterExecute(
            $subject,
            null,
            [$item1, $item2, $itemDuplicate]
        );
    }

    public function testAfterExecuteDoesNothingWhenNoItems(): void
    {
        $subject = $this->createMock(SourceItemsSaveInterface::class);

        $this->publisherMock->expects($this->never())
            ->method('publish');

        $this->plugin->afterExecute($subject, null, []);
    }
}

