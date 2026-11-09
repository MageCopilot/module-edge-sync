<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service;

use MageCopilot\EdgeSync\Model\Client\TypesenseClient;
use MageCopilot\EdgeSync\Model\Config;
use MageCopilot\EdgeSync\Model\Schema\ProductSchema;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
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
        private StoreManagerInterface $storeManager,
        private ?ResourceConnection $resourceConnection = null
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
            $this->storeManager->getStores(false)
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
     * Resolve parent configurable, bundle, and grouped product IDs for given child product IDs.
     *
     * @param int[] $childIds
     * @return int[]
     */
    public function resolveParentProductIds(array $childIds): array
    {
        if (empty($childIds) || $this->resourceConnection === null) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $parentIds = [];

        try {
            // 1. Configurable Parents
            $superLinkTable = $this->resourceConnection->getTableName('catalog_product_super_link');
            $selectSuper = $connection->select()
                ->from($superLinkTable, ['parent_id'])
                ->where('product_id IN (?)', $childIds);
            $parentsSuper = $connection->fetchCol($selectSuper);
            foreach ($parentsSuper as $pId) {
                $parentIds[] = (int) $pId;
            }

            // 2. Bundle Parents
            $bundleTable = $this->resourceConnection->getTableName('catalog_product_bundle_selection');
            $selectBundle = $connection->select()
                ->from($bundleTable, ['parent_product_id'])
                ->where('product_id IN (?)', $childIds);
            $parentsBundle = $connection->fetchCol($selectBundle);
            foreach ($parentsBundle as $pId) {
                $parentIds[] = (int) $pId;
            }

            // 3. Grouped Parents
            $linkTable = $this->resourceConnection->getTableName('catalog_product_link');
            $selectGrouped = $connection->select()
                ->from($linkTable, ['product_id'])
                ->where('linked_product_id IN (?)', $childIds)
                ->where('link_type_id = ?', 3);
            $parentsGrouped = $connection->fetchCol($selectGrouped);
            foreach ($parentsGrouped as $pId) {
                $parentIds[] = (int) $pId;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[MageCopilot_EdgeSync] Error resolving parent product IDs: ' . $e->getMessage());
        }

        return array_values(array_unique(array_filter($parentIds)));
    }

    /**
     * Reindex product IDs (alias for reindexList).
     *
     * @param list<int|string> $ids
     * @param string|null $targetStore
     * @return array{indexed: int, deleted: int, failed: int}
     */
    public function reindexIds(array $ids, ?string $targetStore = null): array
    {
        return $this->reindexList($ids, $targetStore);
    }

    /**
     * Reindex a specific list of product IDs or SKUs across all or specific store collections.
     * Deletes obsolete, disabled, or missing products from Typesense collections automatically.
     *
     * @param list<int|string> $ids
     * @param string|null $targetStore Optional store code (e.g. 'es', 'pt', 'default')
     * @return array{indexed: int, deleted: int, failed: int}
     */
    public function reindexList(array $ids, ?string $targetStore = null): array
    {
        if (empty($ids)) {
            return ['indexed' => 0, 'deleted' => 0, 'failed' => 0];
        }

        $numericIds = array_values(array_unique(array_map('intval', $ids)));

        // Resolve parent product IDs for configurables, bundles, and grouped products
        $parentIds = $this->resolveParentProductIds($numericIds);
        $candidateIds = array_values(array_unique(array_merge($numericIds, $parentIds)));

        $this->ensureCollection(false);
        $collectionName = $this->config->getCollectionName();

        $partitionedStores = array_map(
            fn(\Magento\Store\Api\Data\StoreInterface $store) => (string) $store->getCode(),
            $this->storeManager->getStores(false)
        );
        if (!in_array('default', $partitionedStores, true)) {
            $partitionedStores[] = 'default';
        }

        $targetCollections = $targetStore ? ["products_{$targetStore}"] : array_map(fn($st) => "products_{$st}", $partitionedStores);
        foreach ($targetCollections as $col) {
            $this->ensureCollection(false, $col);
        }

        // Fetch products that are enabled and visible
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('*');
        $collection->addFieldToFilter('entity_id', ['in' => $candidateIds]);
        $collection->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED]);
        $collection->addAttributeToFilter('visibility', ['in' => [
            Visibility::VISIBILITY_BOTH,
            Visibility::VISIBILITY_IN_CATALOG,
            Visibility::VISIBILITY_IN_SEARCH
        ]]);
        $collection->addMediaGalleryData();
        $collection->addTierPriceData();

        $batch = [];
        $failedCount = 0;
        $foundActiveIds = [];

        foreach ($collection as $product) {
            try {
                $batch[] = $this->mapper->map($product);
                $foundActiveIds[] = (int) $product->getId();
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
            foreach ($targetCollections as $col) {
                $this->client->importDocuments($col, $batch, 'upsert');
            }
        }

        // Handle Deletions: any candidate ID that is not currently active in catalog must be purged from Typesense
        $deletedCount = 0;
        $idsToDelete = array_diff($candidateIds, $foundActiveIds);
        foreach ($idsToDelete as $delId) {
            $this->client->deleteDocument($collectionName, (string) $delId);
            foreach ($targetCollections as $col) {
                $this->client->deleteDocument($col, (string) $delId);
            }
            $deletedCount++;
        }

        if ($deletedCount > 0) {
            $this->logger->info(sprintf(
                '[MageCopilot_EdgeSync] Purged %d obsolete/deleted/disabled product documents from Typesense RAM (IDs: %s)',
                $deletedCount,
                implode(', ', $idsToDelete)
            ));
        }

        return [
            'indexed' => $indexedCount,
            'deleted' => $deletedCount,
            'failed' => $failedCount
        ];
    }
}
