<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Plugin;

use MageCopilot\EdgeSync\Model\Queue\Publisher;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use Psr\Log\LoggerInterface;

class InventoryReservationSyncPlugin
{
    public function __construct(
        private Publisher $publisher,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param PlaceReservationsForSalesEventInterface $subject
     * @param mixed $result
     * @param array<int, mixed> $items
     * @param SalesChannelInterface $salesChannel
     * @param SalesEventInterface $salesEvent
     * @return void
     */
    public function afterExecute(
        PlaceReservationsForSalesEventInterface $subject,
        mixed $result,
        array $items,
        SalesChannelInterface $salesChannel,
        SalesEventInterface $salesEvent
    ): void {
        try {
            $affectedSkus = [];
            foreach ($items as $item) {
                if ($item instanceof ItemToSellInterface) {
                    $affectedSkus[] = $item->getSku();
                }
            }

            if (!empty($affectedSkus)) {
                $this->publisher->publish([
                    'type' => 'stock_delta_update',
                    'skus' => array_values(array_unique($affectedSkus)),
                    'timestamp' => time()
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->critical(
                sprintf('[MageCopilot_EdgeSync] Error publicando evento de stock a RabbitMQ: %s', $e->getMessage())
            );
        }
    }
}
