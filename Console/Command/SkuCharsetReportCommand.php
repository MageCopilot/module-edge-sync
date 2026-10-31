<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to audit catalog product SKUs charset and generate optimal Zod regex constraint.
 */
class SkuCharsetReportCommand extends Command
{
    /**
     * @param ResourceConnection $resourceConnection
     * @param string|null $name
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('magecopilot:edgesync:sku-charset-report')
            ->setDescription('Audit catalog product SKUs charset and generate optimal Zod regex constraint');
    }

    /**
     * Execute SKU charset inspection.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Auditing catalog product SKUs charset...</info>');

        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('catalog_product_entity');

        $select = $connection->select()
            ->from($tableName, ['sku'])
            ->where('sku IS NOT NULL');

        $rows = $connection->fetchAll($select);
        $totalSkus = count($rows);

        if ($totalSkus === 0) {
            $output->writeln('<comment>No product SKUs found in catalog.</comment>');
            return Command::SUCCESS;
        }

        $minLen = PHP_INT_MAX;
        $maxLen = 0;
        $totalLen = 0;

        $specialCharsMap = [];
        $hasUnicode = false;
        $hasControlChars = false;
        $controlCharExamples = [];

        foreach ($rows as $row) {
            $sku = (string)($row['sku'] ?? '');
            $len = mb_strlen($sku, 'UTF-8');

            if ($len < $minLen) {
                $minLen = $len;
            }
            if ($len > $maxLen) {
                $maxLen = $len;
            }
            $totalLen += $len;

            // Check for control characters
            if (preg_match('/[\x00-\x1F\x7F]/', $sku)) {
                $hasControlChars = true;
                if (count($controlCharExamples) < 5) {
                    $controlCharExamples[] = (string)json_encode($sku);
                }
            }

            // Check for non-ASCII / Unicode characters
            if (preg_match('/[^\x00-\x7F]/u', $sku)) {
                $hasUnicode = true;
            }

            // Extract all characters outside standard alphanumeric [a-zA-Z0-9]
            $lenRaw = strlen($sku);
            for ($i = 0; $i < $lenRaw; $i++) {
                $char = $sku[$i];
                if (!ctype_alnum($char)) {
                    $specialCharsMap[$char] = ($specialCharsMap[$char] ?? 0) + 1;
                }
            }
        }

        $avgLen = $totalSkus > 0 ? round($totalLen / $totalSkus, 2) : 0;

        // Render Summary Table
        $table = new Table($output);
        $table->setHeaders(['Metric', 'Analysis Result']);
        $table->addRows([
            ['Total SKUs Inspected', (string)$totalSkus],
            ['Shortest SKU Length', (string)$minLen],
            ['Longest SKU Length', (string)$maxLen],
            ['Average SKU Length', (string)$avgLen],
            ['Unicode / Non-ASCII Characters', $hasUnicode ? 'YES (Present in catalog)' : 'NO (Pure ASCII)'],
            ['ASCII Control Characters', $hasControlChars ? 'ALERT: Control chars detected!' : 'CLEAN (None detected)'],
        ]);
        $table->render();

        // Print Special Characters breakdown
        if (!empty($specialCharsMap)) {
            $uniqueCharsList = array_keys($specialCharsMap);
            $formattedChars = implode(' ', array_map(function ($c) {
                return $c === ' ' ? '[SPACE]' : $c;
            }, $uniqueCharsList));

            $output->writeln('');
            $output->writeln(sprintf(
                '<info>Unique Non-Alphanumeric Characters Found (%d):</info>',
                count($uniqueCharsList)
            ));
            $output->writeln('  ' . $formattedChars);
        } else {
            $output->writeln('');
            $output->writeln('<info>No special characters found. Catalog uses strictly alphanumeric SKUs.</info>');
        }

        if ($hasControlChars) {
            $output->writeln('');
            $output->writeln('<error>WARNING: Catalog contains SKUs with control characters. Examples:</error>');
            foreach ($controlCharExamples as $example) {
                $output->writeln('  - ' . $example);
            }
        }

        // Recommend Zod Regex
        $output->writeln('');
        $output->writeln('<info>Recommended Storefront Zod Regex Constraint:</info>');
        $recommendedRegex = '/^[^\u0000-\u001F\u007F]+$/u';
        $output->writeln('  ' . $recommendedRegex);
        $output->writeln('  Explanation: Rejects dangerous ASCII control and null bytes while fully supporting');
        $output->writeln('  catalog spaces, symbols, and Unicode characters without false-positive rejects.');

        return Command::SUCCESS;
    }
}
