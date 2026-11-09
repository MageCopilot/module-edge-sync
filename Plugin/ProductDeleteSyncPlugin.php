<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Plugin;

use MageCopilot\EdgeSync\Service\IndexerService;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Model\AbstractModel;
use Psr\Log\LoggerInterface;

/**
 * Captures product deletion mutations and publishes delta deletion events to RabbitMQ.
 */
class ProductDeleteSyncPlugin
{
    public const TOPIC_NAME = 'magecopilot.edgesync.product';

    /**
     * @param IndexerService $indexerService
     * @param LoggerInterface $logger
     * @param PublisherInterface|null $publisher
     */
    public function __construct(
        private readonly IndexerService $indexerService,
        private readonly LoggerInterface $logger,
        private readonly ?PublisherInterface $publisher = null
    ) {
    }

    /**
     * Intercept product deletion to resolve parent product IDs before deletion and purge from Typesense.
     *
     * @param ProductResource $subject
     * @param callable $proceed
     * @param AbstractModel $object
     * @return mixed
     */
    public function aroundDelete(
        ProductResource $subject,
        callable $proceed,
        AbstractModel $object
    ) {
        $productId = (int) $object->getId();
        $sku = (string) $object->getData('sku');
        $parentIds = [];

        if ($productId > 0) {
            try {
                $parentIds = $this->indexerService->resolveParentProductIds([$productId]);
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    '[MageCopilot_EdgeSync] Failed to resolve parent product IDs for deleted product ID %d: %s',
                    $productId,
                    $e->getMessage()
                ));
            }
        }

        $result = $proceed($object);

        if ($productId > 0) {
            $payload = [
                'action' => 'delete',
                'ids' => [$productId],
                'parent_ids' => $parentIds,
                'skus' => array_values(array_filter([$sku])),
                'timestamp' => time()
            ];

            $published = false;
            if ($this->publisher !== null) {
                try {
                    $this->publisher->publish(self::TOPIC_NAME, json_encode($payload, JSON_THROW_ON_ERROR));
                    $published = true;
                    $this->logger->info(sprintf(
                        '[MageCopilot_EdgeSync] Dispatched product delete event for ID %d (SKU: %s) to RabbitMQ',
                        $productId,
                        $sku
                    ));
                } catch (\Throwable $e) {
                    $this->logger->warning(sprintf(
                        '[MageCopilot_EdgeSync] RabbitMQ publish failed for product deletion %d, executing direct sync: %s',
                        $productId,
                        $e->getMessage()
                    ));
                }
            }

            if (!$published) {
                try {
                    $syncIds = array_values(array_unique(array_merge([$productId], $parentIds)));
                    $this->indexerService->reindexList($syncIds);
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf(
                        '[MageCopilot_EdgeSync] Direct delta purge failed for product ID %d: %s',
                        $productId,
                        $e->getMessage()
                    ));
                }
            }
        }

        return $result;
    }
}

