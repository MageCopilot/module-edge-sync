<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Console\Command;

use MageCopilot\EdgeSync\Service\CatalogSeederService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SeedCatalogCommand extends Command
{
    public function __construct(
        private CatalogSeederService $seederService,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('magecopilot:seed:catalog')
            ->setDescription('Seed sample categories, B2B customers, and rich catalog products (up to 50,000 SKUs)')
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Total products to seed (e.g. 50 or 50000)', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = (int) ($input->getOption('count') ?: 50);
        $output->writeln(sprintf('<info>[MageCopilot Storefront Engine] Starting catalog generation (%d products)...</info>', $count));

        $logCallback = function (string $message) use ($output) {
            $output->writeln($message);
        };

        try {
            $results = $this->seederService->seed($logCallback, $count);
            $output->writeln(sprintf(
                '<info>[MageCopilot] Process completed successfully: %d categories and %d products created.</info>',
                $results['categories'],
                $results['products']
            ));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Error in seeder: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
