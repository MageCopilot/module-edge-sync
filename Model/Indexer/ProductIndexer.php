<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Model\Indexer;

use MageCopilot\EdgeSync\Service\IndexerService;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;

class ProductIndexer implements IndexerActionInterface, MviewActionInterface
{
    public function __construct(
        private IndexerService $indexerService
    ) {
    }

    /**
     * Execute from mview
     */
    public function execute($ids): void
    {
        $this->executeList(is_array($ids) ? $ids : [$ids]);
    }

    /**
     * Execute full indexation: bin/magento indexer:reindex typesense_products
     */
    public function executeFull(): void
    {
        $this->indexerService->reindexAll(false);
    }

    /**
     * Execute list of product IDs
     */
    public function executeList(array $ids): void
    {
        $this->indexerService->reindexList($ids);
    }

    /**
     * Execute single product ID
     */
    public function executeRow($id): void
    {
        $this->executeList([$id]);
    }
}
