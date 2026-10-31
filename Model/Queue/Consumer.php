<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Model\Queue;

use MageCopilot\EdgeSync\Model\Client\TypesenseClient;
use MageCopilot\EdgeSync\Model\Config;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Psr\Log\LoggerInterface;

class Consumer
{
    public function __construct(
        private TypesenseClient $typesenseClient,
        private Config $config,
        private ProductResource $productResource,
        private ?IsProductSalableInterface $isSalable,
        private ?GetProductSalableQtyInterface $salableQty,
        private LoggerInterface $logger,
        private ?\MageCopilot\EdgeSync\Service\IndexerService $indexerService = null
    ) {
    }

    public function processMessage(string $rawMessage): void
    {
        $this->logger->info('[MageCopilot_EdgeSync] Consumer received message: ' . $rawMessage);

        $payload = json_decode($rawMessage, true);
        if (!isset($payload['skus']) || !is_array($payload['skus']) || empty($payload['skus'])) {
            return;
        }

        $stockId = 1; // Default MSI Stock
        $collectionName = $this->config->getCollectionName();
        $documentsToUpdate = [];
        $connection = $this->productResource->getConnection();
        $superLinkTable = $this->productResource->getTable('catalog_product_super_link');

        foreach ($payload['skus'] as $sku) {
            try {
                $productId = $this->productResource->getIdBySku($sku);
                if (!$productId) {
                    $this->logger->warning("[MageCopilot_EdgeSync] Product ID not found for SKU: {$sku}");
                    continue;
                }

                $isAvailable = true;
                $salableQuantity = 0.0;

                if ($this->isSalable && $this->salableQty) {
                    $isAvailable = (bool) $this->isSalable->execute($sku, $stockId);
                    $salableQuantity = $isAvailable ? (float) $this->salableQty->execute($sku, $stockId) : 0.0;
                }

                $documentsToUpdate[] = [
                    'id' => (string) $productId,
                    'sku' => (string) $sku,
                    'is_in_stock' => (bool) $isAvailable,
                    'salable_qty' => (float) $salableQuantity
                ];

                // Check if this SKU is a child variant of a configurable product
                $parentId = $connection->fetchOne(
                    "SELECT parent_id FROM {$superLinkTable} WHERE product_id = :pid",
                    ['pid' => $productId]
                );

                if ($parentId) {
                    $this->updateParentConfigurableStock(
                        $collectionName,
                        (string) $parentId,
                        (string) $sku,
                        (string) $productId,
                        (bool) $isAvailable,
                        (float) $salableQuantity
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[MageCopilot_EdgeSync] Error resolving stock for SKU %s: %s', $sku, $e->getMessage()));
            }
        }

        if (!empty($documentsToUpdate)) {
            try {
                $res = $this->typesenseClient->updateDocuments($collectionName, $documentsToUpdate);
                $this->logger->info(sprintf(
                    '[MageCopilot_EdgeSync] Typesense stock updated for %d documents (success: %d, failed: %d)',
                    count($documentsToUpdate),
                    $res['success_count'] ?? 0,
                    $res['failed_count'] ?? 0
                ));
            } catch (\Throwable $e) {
                $this->logger->error('[MageCopilot_EdgeSync] Failed to update Typesense stock: ' . $e->getMessage());
            }
        }
    }

    /**
     * Propagate child variant stock update directly to parent configurable product document in Typesense.
     */
    private function updateParentConfigurableStock(
        string $collectionName,
        string $parentId,
        string $childSku,
        string $childId,
        bool $isAvailable,
        float $salableQuantity
    ): void {
        try {
            $parentDoc = $this->typesenseClient->getDocument($collectionName, $parentId);
            if (!$parentDoc || !isset($parentDoc['variants']) || !is_array($parentDoc['variants'])) {
                return;
            }

            $variants = $parentDoc['variants'];
            $variantFound = false;

            foreach ($variants as &$v) {
                if (($v['sku'] ?? '') === $childSku || ($v['id'] ?? '') === $childId) {
                    $v['is_in_stock'] = $isAvailable;
                    $v['salable_qty'] = $salableQuantity;
                    $variantFound = true;
                    break;
                }
            }
            unset($v);

            if (!$variantFound) {
                return;
            }

            // Recalculate parent overall stock availability
            $parentHasStock = false;
            $parentSalableQty = 0.0;
            foreach ($variants as $v) {
                if (!empty($v['is_in_stock']) && ($v['salable_qty'] ?? 0) > 0) {
                    $parentHasStock = true;
                    $parentSalableQty += (float) ($v['salable_qty'] ?? 0);
                }
            }

            $updatePayload = [
                'variants' => $variants,
                'is_in_stock' => $parentHasStock,
                'salable_qty' => $parentSalableQty
            ];

            $this->typesenseClient->updateDocument($collectionName, $parentId, $updatePayload);
            $this->logger->info(sprintf(
                '[MageCopilot_EdgeSync] Successfully updated parent configurable ID %s in Typesense for variant %s (in_stock: %s, qty: %.2f)',
                $parentId,
                $childSku,
                $isAvailable ? 'true' : 'false',
                $salableQuantity
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                '[MageCopilot_EdgeSync] Failed to update parent configurable ID %s in Typesense: %s',
                $parentId,
                $e->getMessage()
            ));
        }
    }

    /**
     * Process product delta message for reindexing into Typesense.
     *
     * @param string $rawMessage
     */
    public function processProductMessage(string $rawMessage): void
    {
         $this->logger->info('[MageCopilot_EdgeSync] Product consumer received message: ' . $rawMessage);

         $payload = json_decode($rawMessage, true);
        if (!is_array($payload)) {
            return;
        }

         $ids = $payload['ids'] ?? [];
         $skus = $payload['skus'] ?? [];

        if (empty($ids) && !empty($skus)) {
            foreach ($skus as $sku) {
                $id = $this->productResource->getIdBySku($sku);
                if ($id) {
                    $ids[] = (int) $id;
                }
            }
        }

        if (empty($ids)) {
            $this->logger->warning('[MageCopilot_EdgeSync] No product IDs resolved for reindexing');
            return;
        }

         $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($this->indexerService !== null) {
            $res = $this->indexerService->reindexIds($ids);
            $this->logger->info(sprintf(
                '[MageCopilot_EdgeSync] Consumer reindexed %d products (%d failed)',
                $res['indexed'] ?? 0,
                $res['failed'] ?? 0
            ));
        } else {
            $this->logger->warning('[MageCopilot_EdgeSync] IndexerService unavailable in consumer for product delta sync');
        }
    }
}
