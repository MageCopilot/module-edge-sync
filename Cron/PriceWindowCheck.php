<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Cron;

use MageCopilot\EdgeSync\Service\IndexerService;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily cron job to detect products crossing special_from_date/to_date or catalog rule boundaries
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
        private readonly ?IndexerService $indexerService = null,
        private readonly ?ResourceConnection $resourceConnection = null
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
            $todayDate = $today->format('Y-m-d');

            $yesterday = (clone $today)->modify('-1 day');
            $yesterdayStart = $yesterday->format('Y-m-d 00:00:00');
            $yesterdayDate = $yesterday->format('Y-m-d');

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

            // Detect catalog rule transitions from catalogrule_product_price
            if ($this->resourceConnection !== null) {
                try {
                    $conn = $this->resourceConnection->getConnection();
                    $ruleTable = $this->resourceConnection->getTableName('catalogrule_product_price');
                    $entityTable = $this->resourceConnection->getTableName('catalog_product_entity');

                    $select = $conn->select()
                        ->from(['cr' => $ruleTable], ['product_id'])
                        ->join(['p' => $entityTable], 'cr.product_id = p.entity_id', ['sku'])
                        ->where('cr.rule_date IN (?)', [$todayDate, $yesterdayDate])
                        ->distinct(true);

                    $rows = $conn->fetchAll($select);
                    foreach ($rows as $row) {
                        $ids[] = (int) $row['product_id'];
                        $skus[] = (string) $row['sku'];
                    }
                } catch (\Throwable $ruleEx) {
                    $this->logger->warning(
                        '[MageCopilot_EdgeSync] Failed checking catalog rule price transitions: ' . $ruleEx->getMessage()
                    );
                }
            }

            $uniqueIds = array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
            $uniqueSkus = array_values(array_unique(array_filter($skus, fn($s) => $s !== '')));

            $count = count($uniqueIds);
            if ($count === 0) {
                $this->logger->info('[MageCopilot_EdgeSync] Price window check complete: 0 products transitioning today');
                return;
            }

            $this->logger->info(sprintf(
                '[MageCopilot_EdgeSync] Detected %d products with active/expired price window transitions',
                $count
            ));

            $payload = json_encode([
                'skus' => $uniqueSkus,
                'ids' => $uniqueIds,
                'transition_date' => $todayDate,
                'timestamp' => time()
            ]);

            $published = false;
            if ($this->publisher !== null) {
                try {
                    $this->publisher->publish(self::TOPIC_NAME, (string) $payload);
                    $published = true;
                    $this->logger->info(sprintf(
                        '[MageCopilot_EdgeSync] Dispatched %d product IDs to RabbitMQ topic "%s"',
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
                $res = $this->indexerService->reindexList($uniqueIds);
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
