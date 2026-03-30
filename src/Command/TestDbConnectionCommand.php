<?php

declare(strict_types=1);

namespace DataVeil\Command;

use DataVeil\Config\Configuration;
use DataVeil\Database\ConnectionDiagnostics;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'test:db-connection',
    description: 'Check database connection from YAML configuration'
)]
class TestDbConnectionCommand extends Command
{
    public function __construct(
        private readonly ?ConnectionDiagnostics $diagnostics = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'config',
            InputArgument::REQUIRED,
            'YAML config'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $configPath = $input->getArgument('config');

        if (!is_string($configPath) || !file_exists($configPath)) {
            $io->error(sprintf('Configuration file not found: %s', (string) $configPath));
            return Command::FAILURE;
        }

        try {
            $config = new Configuration($configPath);
            $diagnostics = ($this->diagnostics ?? new ConnectionDiagnostics())->test($config);
        } catch (\Throwable $e) {
            $io->error(sprintf('Database connection test failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $io->success('Database connection is valid');
        $io->definitionList(
            ['Host' => (string) ($diagnostics['host'] ?? '')],
            ['Database' => (string) ($diagnostics['database'] ?? '')],
            ['Login' => (string) ($diagnostics['login'] ?? '')],
            ['Selected DB' => (string) ($diagnostics['selected_database'] ?? '')],
            ['Server version' => (string) ($diagnostics['server_version'] ?? '')],
            ['Server host' => (string) ($diagnostics['server_hostname'] ?? '')],
            ['Charset' => (string) ($diagnostics['charset'] ?? '')],
        );

        return Command::SUCCESS;
    }
}
