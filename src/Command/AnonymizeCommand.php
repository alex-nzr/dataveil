<?php

declare(strict_types=1);

namespace DataVeil\Command;

use DataVeil\Config\Configuration;
use DataVeil\Anonymizer\AnonymizerService;
use DataVeil\Anonymizer\AnonymizationPreflight;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'anonymize',
    description: 'Anonymize Bitrix database data'
)]
class AnonymizeCommand extends Command
{
    //private Configuration $config;

    public function __construct(
        private readonly ?AnonymizationPreflight $preflight = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'config',
            InputArgument::REQUIRED,
            'Path to configuration YAML file'
        );

        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show what would be done without making changes'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $configPath = $input->getArgument('config');

        if (!file_exists($configPath)) {
            $io->error("Configuration file not found: {$configPath}");
            return Command::FAILURE;
        }

        try {
            $config = new Configuration($configPath);
        } catch (\Exception $e) {
            $io->error('Configuration error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry run mode - no changes will be made');
            return $this->showDryRunInfo($config, $io);
        }

        $io->info('Starting database anonymization...');

        try {
            $anonymizer = new AnonymizerService($config);
            $anonymizer->anonymize();
            $io->success('Anonymization completed successfully');
        } catch (\Exception $e) {
            $io->error('Anonymization failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function showDryRunInfo(Configuration $config, SymfonyStyle $io): int
    {
        try {
            $report = ($this->preflight ?? new AnonymizationPreflight())->check($config);
        } catch (\Exception $e) {
            $io->error('Dry run failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->section('Rules that will be applied');
        foreach ($report['rules'] as $rule) {
            $rows = $rule['rows'] === null ? 'n/a' : (string) $rule['rows'];
            $io->text(sprintf(
                "  - Table '%s': %s, fields: %d, rows: %s",
                (string) $rule['table'],
                (string) $rule['action'],
                (int) $rule['fields'],
                $rows,
            ));
        }

        $io->section('Consistency Groups');
        foreach ($report['consistency_groups'] as $group) {
            $rows = $group['rows'] === null ? 'n/a' : (string) $group['rows'];
            $io->text(sprintf(
                "  - %s: anchor %s, targets: %d, rows: %s",
                (string) $group['id'],
                (string) $group['anchor_table'],
                (int) $group['targets'],
                $rows,
            ));
        }

        foreach ($report['warnings'] as $warning) {
            $io->warning($warning);
        }

        if ($report['errors'] !== []) {
            foreach ($report['errors'] as $error) {
                $io->error($error);
            }

            return Command::FAILURE;
        }

        $io->success('Dry run completed successfully');

        return Command::SUCCESS;
    }
}
