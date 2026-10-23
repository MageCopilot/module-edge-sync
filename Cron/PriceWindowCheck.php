<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Cron;

use MageCopilot\EdgeSync\Service\IndexerService;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily cron job to detect products crossing special_from_date or special_to_date boundaries
 * and queue them for atomic delta reindexing into Typesense.
 */
class PriceWindowCheck
{
    public const TOPIC_NAME = 'magecopilot.edgesync.product';

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger,
        private readonly ?PublisherInterface $publisher = null,
        private readonly ?IndexerService $indexerService = null
    ) {
    }

    /**
     * Execute price window verification and publish affected SKUs to RabbitMQ.
     */
    public function execute(): void
    {
        $this->logger->info('[MageCopilot_EdgeSync] Running scheduled price window check');

        try {
            $today = $this->timezone->date();
            $todayStart = $today->format('Y-m-d 00:00:00');
            $todayEnd = $today->format('Y-m-d 23:59:59');

            $yesterday = (clone $today)->modify('-1 day');
            $yesterdayStart = $yesterday->format('Y-m-d 00:00:00');

            $collection = $this->productCollectionFactory->create();
            $collection->addAttributeToSelect(['sku', 'special_price', 'special_from_date', 'special_to_date']);

            // Find products where special price promotion begins today or expired within last 24h
            $collection->addAttributeToFilter([
                [
                    'attribute' => 'special_from_date',
                    'from' => $todayStart,
                    'to' => $todayEnd,
                    'date' => true
                ],
                [
                    'attribute' => 'special_to_date',
                    'from' => $yesterdayStart,
                    'to' => $todayEnd,
                    'date' => true
                ]
            ]);

            $skus = [];
            $ids = [];

            foreach ($collection as $product) {
                $skus[] = (string) $product->getSku();
                $ids[] = (int) $product->getId();
            }

            $count = count($skus);
            if ($count === 0) {
                $this->logger->info('[MageCopilot_EdgeSync] Price window check complete: 0 products transitioning today');
                return;
            }

            $this->logger->info(sprintf(
                '[MageCopilot_EdgeSync] Detected %d products with active/expired price window transitions',
                $count
            ));

            $payload = json_encode([
                'skus' => array_values(array_unique($skus)),
                'ids' => array_values(array_unique($ids)),
                'transition_date' => $today->format('Y-m-d'),
                'timestamp' => time()
            ]);

            $published = false;
            if ($this->publisher !== null) {
                try {
                    $this->publisher->publish(self::TOPIC_NAME, (string) $payload);
                    $published = true;
                    $this->logger->info(sprintf(
                        '[MageCopilot_EdgeSync] Dispatched %d SKUs to RabbitMQ topic "%s"',
                        $count,
                        self::TOPIC_NAME
                    ));
                } catch (\Throwable $queueEx) {
                    $this->logger->warning(
                        '[MageCopilot_EdgeSync] RabbitMQ publish failed, attempting fallback direct reindex: ' . $queueEx->getMessage()
                    );
                }
            }

            // Fallback direct reindex if publisher is missing or queue failed
            if (!$published && $this->indexerService !== null) {
                $res = $this->indexerService->reindexIds($ids);
                $this->logger->info(sprintf(
                    '[MageCopilot_EdgeSync] Fallback direct reindex completed: %d indexed, %d failed',
                    $res['indexed'],
                    $res['failed']
                ));
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                '[MageCopilot_EdgeSync] Error executing PriceWindowCheck: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
