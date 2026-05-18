<?php

declare(strict_types=1);

namespace DataVeil\Backup;

use DataVeil\Anonymizer\AnonymizationPreflight;
use DataVeil\Anonymizer\AnonymizerService;
use DataVeil\Config\Configuration;
use DataVeil\Database\ConnectionFactory;
use DataVeil\Exception\DataVeilException;

class BackupAnonymizationService
{
    public function __construct(
        private readonly BackupArchiveHandler $archiveHandler = new BackupArchiveHandler(),
        private readonly BackupNameResolver $nameResolver = new BackupNameResolver(),
        private readonly TemporaryDatabaseGuard $guard = new TemporaryDatabaseGuard(),
        private readonly ConnectionFactory $connectionFactory = new ConnectionFactory(),
    ) {
    }

    /**
     * @param array{
     *     input: string,
     *     output?: string|null,
     *     temp_db: string,
     *     reuse_temp_db?: bool,
     *     dry_run: bool,
     *     keep_temp: bool,
     *     mysql_bin: string,
     *     mysqldump_bin: string
     * } $options
     * @return array<string, mixed>
     */
    public function run(Configuration $config, array $options): array
    {
        $inputPath = $this->normalizePath($options['input']);
        $outputPath = $this->nameResolver->resolveOutputPath($inputPath, $options['output'] ?? null);
        $tempDatabase = $options['temp_db'];
        $reuseTempDatabase = (bool) ($options['reuse_temp_db'] ?? false);
        $connectionParams = $this->connectionFactory->resolveParams($config);

        $this->validatePaths($inputPath, $outputPath, (bool) $options['dry_run']);
        $this->guard->assertSafe($tempDatabase, $connectionParams['database'], $reuseTempDatabase);

        $workDirectory = $this->createWorkDirectory();
        $mysqlParams = [
            'host' => $connectionParams['host'],
            'login' => $connectionParams['login'],
            'password' => $connectionParams['password'],
        ];
        if (isset($connectionParams['port'])) {
            $mysqlParams['port'] = $connectionParams['port'];
        }

        $mysql = new MysqlClient(
            $mysqlParams,
            $options['mysql_bin'],
            $options['mysqldump_bin'],
        );

        $createdDatabase = false;

        try {
            if ($mysql->databaseExists($tempDatabase)) {
                if (!$reuseTempDatabase) {
                    throw new DataVeilException("Temporary database already exists: {$tempDatabase}");
                }

                $mysql->clearDatabase($tempDatabase);
            } elseif ($reuseTempDatabase) {
                throw new DataVeilException("Temporary database does not exist and --reuse-temp-db is enabled: {$tempDatabase}");
            } else {
                $mysql->createDatabase($tempDatabase);
                $createdDatabase = true;
            }

            $archive = $this->archiveHandler->unpack($inputPath, $workDirectory);
            $mysql->importSql($tempDatabase, $archive->mainSqlPath);

            $runtimeConfig = $this->createRuntimeConfig($config, $connectionParams, $tempDatabase);
            $preflightReport = (new AnonymizationPreflight())->check($runtimeConfig);

            if ($preflightReport['errors'] !== []) {
                throw new DataVeilException('Backup preflight failed: ' . implode('; ', $preflightReport['errors']));
            }

            if (!$options['dry_run']) {
                (new AnonymizerService($runtimeConfig))->anonymize();
                $exportPath = $workDirectory . DIRECTORY_SEPARATOR . 'anonymized.sql';
                $mysql->exportSql($tempDatabase, $exportPath);
                $this->archiveHandler->pack($archive, $exportPath, $outputPath);
            }

            return [
                'input' => $inputPath,
                'output' => $options['dry_run'] ? null : $outputPath,
                'temp_db' => $tempDatabase,
                'dry_run' => $options['dry_run'],
                'rules' => count($preflightReport['rules']),
                'consistency_groups' => count($preflightReport['consistency_groups']),
            ];
        } finally {
            if ($reuseTempDatabase && !$options['keep_temp']) {
                $mysql->clearDatabase($tempDatabase);
            } elseif ($createdDatabase && !$options['keep_temp']) {
                $mysql->dropDatabase($tempDatabase);
            }

            if (!$options['keep_temp']) {
                $this->removeDirectory($workDirectory);
            }
        }
    }

    /**
     * @param array{host: string, database: string, login: string, password: string, port?: string} $connectionParams
     */
    private function createRuntimeConfig(Configuration $config, array $connectionParams, string $tempDatabase): Configuration
    {
        $runtimeConfig = $config->toArray();
        $runtimeConfig['database'] = [
            'source' => 'mysql',
            'mysql' => [
                'host' => $connectionParams['host'],
                'database' => $tempDatabase,
                'login' => $connectionParams['login'],
                'password' => $connectionParams['password'],
            ],
        ];

        if (isset($connectionParams['port'])) {
            $runtimeConfig['database']['mysql']['port'] = $connectionParams['port'];
        }

        return Configuration::fromArray($runtimeConfig, 'backup-runtime');
    }

    private function normalizePath(string $path): string
    {
        $realPath = realpath($path);

        return $realPath === false ? $path : $realPath;
    }

    private function validatePaths(string $inputPath, string $outputPath, bool $dryRun): void
    {
        if (!is_file($inputPath)) {
            throw new DataVeilException("Input backup not found: {$inputPath}");
        }

        if (!$dryRun && realpath($inputPath) === realpath($outputPath)) {
            throw new DataVeilException('Output path must not be the same as input path');
        }

        if (!$dryRun && file_exists($outputPath)) {
            throw new DataVeilException("Output file already exists: {$outputPath}");
        }
    }

    private function createWorkDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dataveil_backup_' . bin2hex(random_bytes(6));

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new DataVeilException("Failed to create work directory: {$directory}");
        }

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
