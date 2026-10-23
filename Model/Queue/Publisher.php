<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;

class Publisher
{
    private const TOPIC_NAME = 'magecopilot.inventory.stock_update';

    public function __construct(
        private PublisherInterface $publisher,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function publish(array $payload): void
    {
        try {
            $message = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->publisher->publish(self::TOPIC_NAME, $message);
            $this->logger->info(sprintf('[MageCopilot_EdgeSync] Published stock update message for SKUs: %s', implode(', ', $payload['skus'] ?? [])));
        } catch (\Throwable $e) {
            $this->logger->error('[MageCopilot_EdgeSync] Failed to publish message: ' . $e->getMessage());
        }
    }
}
