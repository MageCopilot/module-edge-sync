<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Model\Indexer;

use MageCopilot\EdgeSync\Service\IndexerService;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;

/**
 * Product Indexer for Typesense EdgeSync
 */
class ProductIndexer implements IndexerActionInterface, MviewActionInterface
{
    /**
     * @param IndexerService $indexerService
     */
    public function __construct(
        private IndexerService $indexerService
    ) {
    }

    /**
     * Execute from mview
     *
     * @param int[] $ids
     * @return void
     */
    public function execute($ids): void
    {
        $this->executeList((array) $ids);
    }

    /**
     * Execute full indexation: bin/magento indexer:reindex typesense_products
     *
     * @return void
     */
    public function executeFull(): void
    {
        $this->indexerService->reindexAll(false);
    }

    /**
     * Execute list of product IDs
     *
     * @param int[] $ids
     * @return void
     */
    public function executeList(array $ids): void
    {
        $this->indexerService->reindexList($ids);
    }

    /**
     * Execute single product ID
     *
     * @param int|string $id
     * @return void
     */
    public function executeRow($id): void
    {
        $this->executeList([(int) $id]);
    }
}
