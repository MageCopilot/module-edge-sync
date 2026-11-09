<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Console\Command;

use MageCopilot\EdgeSync\Model\Client\TypesenseClient;
use MageCopilot\EdgeSync\Service\IndexerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IndexCommand extends Command
{
    private const OPTION_RECREATE = 'recreate';
    private const OPTION_HEALTH = 'health';

    public function __construct(
        private IndexerService $indexerService,
        private TypesenseClient $client,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('magecopilot:typesense:index')
            ->setDescription('Index catalog products into Typesense memory cluster')
            ->addOption(
                self::OPTION_RECREATE,
                'r',
                InputOption::VALUE_NONE,
                'Recreate collection schema before indexing'
            )
            ->addOption(
                self::OPTION_HEALTH,
                null,
                InputOption::VALUE_NONE,
                'Check Typesense cluster health'
            )
            ->addOption(
                'store',
                's',
                InputOption::VALUE_OPTIONAL,
                'Index specific store view partition (e.g. es, pt, default)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption(self::OPTION_HEALTH)) {
            $output->writeln('<info>Checking Typesense connection...</info>');
            if ($this->client->isHealthy()) {
                $output->writeln('<info>[OK] Typesense is healthy and connected.</info>');
                return Command::SUCCESS;
            } else {
                $output->writeln('<error>[ERROR] Typesense is unreachable or reported an error.</error>');
                return Command::FAILURE;
            }
        }

        $recreate = (bool) $input->getOption(self::OPTION_RECREATE);
        $targetStore = $input->getOption('store') ? (string)$input->getOption('store') : null;

        $output->writeln(sprintf(
            '<info>[MageCopilot Storefront Engine] Starting Typesense indexation (recreate: %s, store: %s)...</info>',
            $recreate ? 'yes' : 'no',
            $targetStore ?: 'all stores'
        ));

        try {
            $stats = $this->indexerService->reindexAll($recreate, $targetStore);
            $output->writeln(sprintf(
                '<info>[OK] Indexation complete! Total: %d, Indexed: %d, Failed: %d in %s seconds.</info>',
                $stats['total'],
                $stats['indexed'],
                $stats['failed'],
                $stats['duration_seconds']
            ));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Indexation failed: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
