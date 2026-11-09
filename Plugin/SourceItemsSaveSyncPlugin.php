<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Plugin;

use MageCopilot\EdgeSync\Model\Queue\Publisher;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Psr\Log\LoggerInterface;

class SourceItemsSaveSyncPlugin
{
    public function __construct(
        private readonly Publisher $publisher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Intercept MSI source item inventory updates and publish affected SKUs to RabbitMQ.
     *
     * @param SourceItemsSaveInterface $subject
     * @param mixed $result
     * @param array<int, mixed> $sourceItems
     * @return void
     */
    public function afterExecute(
        SourceItemsSaveInterface $subject,
        mixed $result,
        array $sourceItems
    ): void {
        try {
            $skus = [];
            foreach ($sourceItems as $item) {
                if ($item instanceof SourceItemInterface) {
                    $sku = (string) $item->getSku();
                    if ($sku !== '') {
                        $skus[] = $sku;
                    }
                }
            }

            if (!empty($skus)) {
                $uniqueSkus = array_values(array_unique($skus));
                $this->publisher->publish([
                    'type' => 'stock_delta_update',
                    'skus' => $uniqueSkus,
                    'timestamp' => time()
                ]);
                $this->logger->info(sprintf(
                    '[MageCopilot_EdgeSync] Dispatched MSI source item updates for %d SKUs',
                    count($uniqueSkus)
                ));
            }
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf(
                '[MageCopilot_EdgeSync] Error publishing MSI source items update to RabbitMQ: %s',
                $e->getMessage()
            ));
        }
    }
}

