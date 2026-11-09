<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves active catalog rule prices from the catalogrule_product_price indexed table.
 */
class CatalogRulePriceResolver
{
    private const TABLE_NAME = 'catalogrule_product_price';

    /** @var array<string, float|null> */
    private array $priceCache = [];

    /**
     * @param ResourceConnection $resourceConnection
     * @param TimezoneInterface $timezone
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get active catalog rule price for a product, website, and customer group.
     *
     * @param int $productId
     * @param int $websiteId
     * @param int $customerGroupId
     * @param string|null $ruleDate YYYY-MM-DD format (defaults to current store date)
     * @return float|null
     */
    public function getRulePrice(
        int $productId,
        int $websiteId,
        int $customerGroupId,
        ?string $ruleDate = null
    ): ?float {
        if ($ruleDate === null) {
            $ruleDate = $this->timezone->date()->format('Y-m-d');
        }

        $cacheKey = "{$productId}_{$websiteId}_{$customerGroupId}_{$ruleDate}";
        if (array_key_exists($cacheKey, $this->priceCache)) {
            return $this->priceCache[$cacheKey];
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

            $select = $connection->select()
                ->from($tableName, ['rule_price'])
                ->where('product_id = ?', $productId)
                ->where('website_id = ?', $websiteId)
                ->where('customer_group_id = ?', $customerGroupId)
                ->where('rule_date = ?', $ruleDate)
                ->order('rule_price ASC')
                ->limit(1);

            $rulePrice = $connection->fetchOne($select);

            $result = is_numeric($rulePrice) ? (float) $rulePrice : null;
            return $this->priceCache[$cacheKey] = $result;
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[MageCopilot_EdgeSync] Failed resolving catalog rule price: ' . $e->getMessage(),
                [
                    'product_id' => $productId,
                    'website_id' => $websiteId,
                    'group_id' => $customerGroupId
                ]
            );
            return $this->priceCache[$cacheKey] = null;
        }
    }
}
