<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Console\Command;

use MageCopilot\EdgeSync\Service\Feed\TypesenseFeedExporterService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportFeedCommand extends Command
{
    private const OPTION_CHANNEL = 'channel';
    private const OPTION_STORE = 'store';

    public function __construct(
        private TypesenseFeedExporterService $feedExporterService,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('magecopilot:edgesync:feed:export')
            ->setDescription('Export product feeds (Google Shopping, Meta, TikTok) directly from Typesense memory cluster')
            ->addOption(
                self::OPTION_CHANNEL,
                'c',
                InputOption::VALUE_OPTIONAL,
                'Feed channel to export: google, meta, tiktok, or all',
                'all'
            )
            ->addOption(
                self::OPTION_STORE,
                's',
                InputOption::VALUE_OPTIONAL,
                'Store view code (e.g., default, es, pt)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = strtolower((string) $input->getOption(self::OPTION_CHANNEL));
        $store = $input->getOption(self::OPTION_STORE);
        $storeCode = is_string($store) ? $store : null;

        $channelsToExport = ($channel === 'all')
            ? TypesenseFeedExporterService::SUPPORTED_CHANNELS
            : [$channel];

        $output->writeln('<info>Starting product feed export from Typesense Memory Grid...</info>');

        $hasError = false;
        foreach ($channelsToExport as $chan) {
            $output->writeln(sprintf('<comment>Exporting channel: %s</comment>', $chan));
            try {
                $result = $this->feedExporterService->export($chan, $storeCode);
                $output->writeln(sprintf(
                    '<info>[SUCCESS] Channel %s: %d products exported to %s (%s KB) in %.3fs</info>',
                    $result['channel'],
                    $result['count'],
                    $result['relative_path'],
                    number_format($result['file_size'] / 1024, 2),
                    $result['execution_time']
                ));
            } catch (\Throwable $e) {
                $hasError = true;
                $output->writeln(sprintf(
                    '<error>[ERROR] Channel %s failed: %s</error>',
                    $chan,
                    $e->getMessage()
                ));
            }
        }

        return $hasError ? Command::FAILURE : Command::SUCCESS;
    }
}
