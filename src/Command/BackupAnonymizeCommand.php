<?php

declare(strict_types=1);

namespace DataVeil\Command;

use DataVeil\Backup\BackupAnonymizationService;
use DataVeil\Config\Configuration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'backup:anonymize',
    description: 'Anonymize an existing Bitrix24 SQL backup through a temporary database'
)]
class BackupAnonymizeCommand extends Command
{
    public function __construct(
        private readonly ?BackupAnonymizationService $service = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('config', InputArgument::REQUIRED, 'Path to configuration YAML file');
        $this->addOption('input', null, InputOption::VALUE_REQUIRED, 'Source .sql or .tar.gz Bitrix backup');
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output anonymized backup path');
        $this->addOption('temp-db', null, InputOption::VALUE_REQUIRED, 'Temporary database name');
        $this->addOption('reuse-temp-db', null, InputOption::VALUE_NONE, 'Reuse existing temporary database and clean its objects instead of creating/dropping it');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Import backup and run preflight without anonymization/export');
        $this->addOption('keep-temp', null, InputOption::VALUE_NONE, 'Keep temporary database and files after completion');
        $this->addOption('mysql-bin', null, InputOption::VALUE_REQUIRED, 'Path to mysql binary', 'mysql');
        $this->addOption('mysqldump-bin', null, InputOption::VALUE_REQUIRED, 'Path to mysqldump binary', 'mysqldump');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $configPath = $input->getArgument('config');

        if (!is_string($configPath) || !file_exists($configPath)) {
            $io->error(sprintf('Configuration file not found: %s', (string) $configPath));

            return Command::FAILURE;
        }

        $inputPath = $input->getOption('input');
        $tempDatabase = $input->getOption('temp-db');

        if (!is_string($inputPath) || $inputPath === '') {
            $io->error('Option --input is required');

            return Command::FAILURE;
        }

        if (!is_string($tempDatabase) || $tempDatabase === '') {
            $io->error('Option --temp-db is required');

            return Command::FAILURE;
        }

        try {
            $config = new Configuration($configPath);
            $result = ($this->service ?? new BackupAnonymizationService())->run($config, [
                'input' => $inputPath,
                'output' => is_string($input->getOption('output')) ? $input->getOption('output') : null,
                'temp_db' => $tempDatabase,
                'reuse_temp_db' => (bool) $input->getOption('reuse-temp-db'),
                'dry_run' => (bool) $input->getOption('dry-run'),
                'keep_temp' => (bool) $input->getOption('keep-temp'),
                'mysql_bin' => (string) $input->getOption('mysql-bin'),
                'mysqldump_bin' => (string) $input->getOption('mysqldump-bin'),
                'progress_callback' => $this->createProgressCallback($io),
            ]);
        } catch (\Throwable $e) {
            $io->error('Backup anonymization failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success((bool) $result['dry_run'] ? 'Backup dry run completed successfully' : 'Backup anonymization completed successfully');
        $io->definitionList(
            ['Input' => (string) $result['input']],
            ['Output' => (string) ($result['output'] ?? '')],
            ['Temporary DB' => (string) $result['temp_db']],
            ['Rules' => (string) $result['rules']],
            ['Consistency groups' => (string) $result['consistency_groups']],
        );

        return Command::SUCCESS;
    }

    /**
     * @return callable(string, array<string, mixed>): void
     */
    private function createProgressCallback(SymfonyStyle $io): callable
    {
        return function (string $event, array $payload) use ($io): void {
            if ($event === 'rule_start') {
                $io->writeln(sprintf(
                    '[%d/%d] %s table %s: rows=%d, fields=%d',
                    (int) ($payload['rule'] ?? 0),
                    (int) ($payload['rules'] ?? 0),
                    (string) ($payload['action'] ?? ''),
                    (string) ($payload['table'] ?? ''),
                    (int) ($payload['rows'] ?? 0),
                    (int) ($payload['fields'] ?? 0),
                ));

                return;
            }

            if ($event === 'rule_progress') {
                $io->writeln(sprintf(
                    '  %s: processed=%d, remaining=%d, elapsed=%s',
                    (string) ($payload['table'] ?? ''),
                    (int) ($payload['processed'] ?? 0),
                    (int) ($payload['remaining'] ?? 0),
                    $this->formatDuration((float) ($payload['seconds'] ?? 0.0)),
                ));

                return;
            }

            if ($event === 'rule_done') {
                $io->writeln(sprintf(
                    '  done %s: processed=%d/%d, elapsed=%s',
                    (string) ($payload['table'] ?? ''),
                    (int) ($payload['processed'] ?? 0),
                    (int) ($payload['rows'] ?? 0),
                    $this->formatDuration((float) ($payload['seconds'] ?? 0.0)),
                ));

                return;
            }

            if ($event === 'consistency_start') {
                $io->writeln(sprintf(
                    'Consistency groups: %d',
                    (int) ($payload['groups'] ?? 0),
                ));

                return;
            }

            if ($event === 'consistency_group_start') {
                $io->writeln(sprintf(
                    '[group %d/%d] %s anchor %s: rows=%d, targets=%d',
                    (int) ($payload['group'] ?? 0),
                    (int) ($payload['groups'] ?? 0),
                    (string) ($payload['id'] ?? ''),
                    (string) ($payload['anchor_table'] ?? ''),
                    (int) ($payload['rows'] ?? 0),
                    (int) ($payload['targets'] ?? 0),
                ));

                return;
            }

            if ($event === 'consistency_group_progress') {
                $io->writeln(sprintf(
                    '  group %s: processed=%d, remaining=%d, elapsed=%s',
                    (string) ($payload['id'] ?? ''),
                    (int) ($payload['processed'] ?? 0),
                    (int) ($payload['remaining'] ?? 0),
                    $this->formatDuration((float) ($payload['seconds'] ?? 0.0)),
                ));

                return;
            }

            if ($event === 'consistency_group_done') {
                $io->writeln(sprintf(
                    '  done group %s: processed=%d/%d, elapsed=%s',
                    (string) ($payload['id'] ?? ''),
                    (int) ($payload['processed'] ?? 0),
                    (int) ($payload['rows'] ?? 0),
                    $this->formatDuration((float) ($payload['seconds'] ?? 0.0)),
                ));
            }
        };
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        }

        return sprintf('%dm %02ds', (int) floor($seconds / 60), (int) floor($seconds) % 60);
    }
}
