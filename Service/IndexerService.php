<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service;

use MageCopilot\EdgeSync\Model\Client\TypesenseClient;
use MageCopilot\EdgeSync\Model\Config;
use MageCopilot\EdgeSync\Model\Schema\ProductSchema;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class IndexerService
{
    public function __construct(
        private TypesenseClient $client,
        private ProductSchema $schema,
        private ProductDataMapper $mapper,
        private ProductCollectionFactory $productCollectionFactory,
        private Config $config,
        private LoggerInterface $logger,
        private StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Ensure collection exists in Typesense with current schema.
     */
    public function ensureCollection(bool $recreate = false, ?string $customCollectionName = null): void
    {
        $collectionName = $customCollectionName ?: $this->config->getCollectionName();

        if ($this->client->collectionExists($collectionName)) {
            if ($recreate) {
                $this->logger->info("[MageCopilot_EdgeSync] Recreating collection {$collectionName}");
                $this->client->deleteCollection($collectionName);
                $this->client->createCollection($this->schema->getSchema($collectionName));
            }
        } else {
            $this->logger->info("[MageCopilot_EdgeSync] Creating collection {$collectionName}");
            $this->client->createCollection($this->schema->getSchema($collectionName));
        }
    }

    /**
     * Reindex all catalog products to Typesense with multi-store partitioning.
     *
     * @param bool $recreateCollection
     * @param string|null $targetStore Optional store code (e.g. 'es', 'pt', 'default')
     * @return array{total: int, indexed: int, failed: int, duration_seconds: float}
     */
    public function reindexAll(bool $recreateCollection = false, ?string $targetStore = null): array
    {
        $startTime = microtime(true);
        $mainCollection = $this->config->getCollectionName();
        $this->ensureCollection($recreateCollection, $mainCollection);

        $partitionedStores = array_map(
            fn(\Magento\Store\Api\Data\StoreInterface $store) => (string) $store->getCode(),
            $this->storeManager->getStores(true)
        );
        if (!in_array('default', $partitionedStores, true)) {
            $partitionedStores[] = 'default';
        }

        if ($targetStore) {
            $targetCol = "products_{$targetStore}";
            $this->ensureCollection($recreateCollection, $targetCol);
        } else {
            foreach ($partitionedStores as $st) {
                $this->ensureCollection($recreateCollection, "products_{$st}");
            }
        }

        $batchSize = $this->config->getBatchSize();

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('*')
            ->addMediaGalleryData()
            ->addTierPriceData()
            ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
            ->addAttributeToFilter('visibility', ['in' => [
                Visibility::VISIBILITY_BOTH,
                Visibility::VISIBILITY_IN_CATALOG,
                Visibility::VISIBILITY_IN_SEARCH
            ]]);

        $totalProducts = $collection->getSize();
        $indexedCount = 0;
        $failedCount = 0;

        $collection->setPageSize($batchSize);
        $pages = $collection->getLastPageNumber();

        for ($currentPage = 1; $currentPage <= $pages; $currentPage++) {
            $collection->setCurPage($currentPage);
            $collection->load();

            $batch = [];
            foreach ($collection as $product) {
                try {
                    $batch[] = $this->mapper->map($product);
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf('[MageCopilot_EdgeSync] Error mapping product ID %s: %s', $product->getId(), $e->getMessage()));
                    $failedCount++;
                }
            }

            if (!empty($batch)) {
                // Upsert to main global collection
                $res = $this->client->importDocuments($mainCollection, $batch, 'upsert');
                $indexedCount += $res['success_count'];
                $failedCount += $res['failed_count'];

                // Upsert to partitioned store collections
                if ($targetStore) {
                    $this->client->importDocuments("products_{$targetStore}", $batch, 'upsert');
                } else {
                    foreach ($partitionedStores as $st) {
                        $this->client->importDocuments("products_{$st}", $batch, 'upsert');
                    }
                }
            }

            $collection->clear();
        }

        $duration = round(microtime(true) - $startTime, 2);

        return [
            'total' => $totalProducts,
            'indexed' => $indexedCount,
            'failed' => $failedCount,
            'duration_seconds' => $duration
        ];
    }

    /**
     * Reindex a specific list of product IDs or SKUs across all or specific store collections.
     *
     * @param list<int|string> $ids
     * @param string|null $targetStore Optional store code (e.g. 'es', 'pt', 'default')
     * @return array{indexed: int, failed: int}
     */
    public function reindexList(array $ids, ?string $targetStore = null): array
    {
        if (empty($ids)) {
            return ['indexed' => 0, 'failed' => 0];
        }

        $this->ensureCollection(false);
        $collectionName = $this->config->getCollectionName();

        $partitionedStores = array_map(
            fn(\Magento\Store\Api\Data\StoreInterface $store) => (string) $store->getCode(),
            $this->storeManager->getStores(true)
        );
        if (!in_array('default', $partitionedStores, true)) {
            $partitionedStores[] = 'default';
        }

        if ($targetStore) {
            $this->ensureCollection(false, "products_{$targetStore}");
        } else {
            foreach ($partitionedStores as $st) {
                $this->ensureCollection(false, "products_{$st}");
            }
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('*')
            ->addMediaGalleryData()
            ->addTierPriceData()
            ->addIdFilter($ids);

        $batch = [];
        $failedCount = 0;

        foreach ($collection as $product) {
            try {
                $batch[] = $this->mapper->map($product);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[MageCopilot_EdgeSync] Error mapping product ID %s: %s', $product->getId(), $e->getMessage()));
                $failedCount++;
            }
        }

        $indexedCount = 0;
        if (!empty($batch)) {
            $res = $this->client->importDocuments($collectionName, $batch, 'upsert');
            $indexedCount = $res['success_count'];
            $failedCount += $res['failed_count'];

            // Propagate partial updates to partitioned store collections
            if ($targetStore) {
                $this->client->importDocuments("products_{$targetStore}", $batch, 'upsert');
            } else {
                foreach ($partitionedStores as $st) {
                    $this->client->importDocuments("products_{$st}", $batch, 'upsert');
                }
            }
        }

        return [
            'indexed' => $indexedCount,
            'failed' => $failedCount
        ];
    }
}
