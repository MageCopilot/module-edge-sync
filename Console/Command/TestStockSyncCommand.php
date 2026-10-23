<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Console\Command;

use MageCopilot\EdgeSync\Model\Client\TypesenseClient;
use MageCopilot\EdgeSync\Model\Config;
use MageCopilot\EdgeSync\Model\Queue\Consumer;
use MageCopilot\EdgeSync\Model\Queue\Publisher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TestStockSyncCommand extends Command
{
    private const ARG_SKU = 'sku';
    private const OPTION_DIRECT = 'direct';

    public function __construct(
        private Publisher $publisher,
        private Consumer $consumer,
        private TypesenseClient $client,
        private Config $config,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('magecopilot:test:stock-sync')
            ->setDescription('Test real-time stock sync pipeline with RabbitMQ and Typesense')
            ->addArgument(self::ARG_SKU, InputArgument::REQUIRED, 'SKU to trigger stock update for')
            ->addOption(self::OPTION_DIRECT, 'd', InputOption::VALUE_NONE, 'Run consumer directly without waiting for queue worker')
            ->addOption('publish-only', 'p', InputOption::VALUE_NONE, 'Publish message to RabbitMQ without invoking consumer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sku = (string) $input->getArgument(self::ARG_SKU);
        $direct = (bool) $input->getOption(self::OPTION_DIRECT);
        $publishOnly = (bool) $input->getOption('publish-only');

        $output->writeln(sprintf('<info>[MageCopilot Storefront Engine] Testing stock sync for SKU: %s</info>', $sku));

        // 1. Check current stock in Typesense
        $collection = $this->config->getCollectionName();
        $searchRes = $this->client->search($collection, [
            'q' => $sku,
            'query_by' => 'sku',
            'filter_by' => sprintf('sku:=%s', $sku)
        ]);

        if (empty($searchRes['hits'])) {
            $output->writeln(sprintf('<error>SKU %s not found in Typesense collection "%s"!</error>', $sku, $collection));
            return Command::FAILURE;
        }

        $currentDoc = $searchRes['hits'][0]['document'];
        $output->writeln(sprintf(
            '<comment>Current Typesense state -> is_in_stock: %s, salable_qty: %s</comment>',
            $currentDoc['is_in_stock'] ? 'true' : 'false',
            $currentDoc['salable_qty']
        ));

        $payload = [
            'type' => 'stock_delta_update',
            'skus' => [$sku],
            'timestamp' => time()
        ];

        if ($publishOnly) {
            $output->writeln('<info>Publishing event to RabbitMQ topic "magecopilot.inventory.stock_update"...</info>');
            $this->publisher->publish($payload);
            $output->writeln('<info>✓ Event successfully queued in RabbitMQ.</info>');
            return Command::SUCCESS;
        }

        $encodedMessage = (string) json_encode($payload);
        if ($direct) {
            $output->writeln('<info>Executing Consumer directly...</info>');
            $this->consumer->processMessage($encodedMessage);
        } else {
            $output->writeln('<info>Publishing event to RabbitMQ topic "magecopilot.inventory.stock_update"...</info>');
            $this->publisher->publish($payload);
            $output->writeln('<info>✓ Event published to RabbitMQ. Now processing message with consumer...</info>');
            $this->consumer->processMessage($encodedMessage);
        }

        // Verify updated state in Typesense
        $updatedRes = $this->client->search($collection, [
            'q' => $sku,
            'query_by' => 'sku',
            'filter_by' => sprintf('sku:=%s', $sku)
        ]);
        $updatedDoc = $updatedRes['hits'][0]['document'];

        $output->writeln(sprintf(
            '<info>✓ Updated Typesense state -> is_in_stock: %s, salable_qty: %s</info>',
            $updatedDoc['is_in_stock'] ? 'true' : 'false',
            $updatedDoc['salable_qty']
        ));

        return Command::SUCCESS;
    }
}
