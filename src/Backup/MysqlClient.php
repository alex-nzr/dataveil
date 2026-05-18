<?php

declare(strict_types=1);

namespace DataVeil\Backup;

use DataVeil\Exception\DataVeilException;

class MysqlClient
{
    /**
     * @param array{host: string, login: string, password: string, port?: string} $params
     */
    public function __construct(
        private readonly array $params,
        string $mysqlBinary = 'mysql',
        string $mysqldumpBinary = 'mysqldump',
        private readonly SqlDumpNormalizer $dumpNormalizer = new SqlDumpNormalizer(),
    ) {
        $this->mysqlBinary = $this->resolveBinary($mysqlBinary);
        $this->mysqldumpBinary = $this->resolveBinary($mysqldumpBinary);
    }

    private string $mysqlBinary;
    private string $mysqldumpBinary;

    public function createDatabase(string $database): void
    {
        $this->runMysqlStatement("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    public function dropDatabase(string $database): void
    {
        $this->runMysqlStatement("DROP DATABASE `{$database}`");
    }

    public function clearDatabase(string $database): void
    {
        $objects = $this->databaseObjects($database);

        if ($objects === []) {
            return;
        }

        $statements = ['SET FOREIGN_KEY_CHECKS=0'];

        foreach ($objects as $object) {
            if ($object['type'] === 'VIEW') {
                $statements[] = sprintf(
                    'DROP VIEW IF EXISTS `%s`.`%s`',
                    $this->escapeIdentifier($database),
                    $this->escapeIdentifier($object['name']),
                );
            }
        }

        foreach ($objects as $object) {
            if ($object['type'] !== 'VIEW') {
                $statements[] = sprintf(
                    'DROP TABLE IF EXISTS `%s`.`%s`',
                    $this->escapeIdentifier($database),
                    $this->escapeIdentifier($object['name']),
                );
            }
        }

        $statements[] = 'SET FOREIGN_KEY_CHECKS=1';
        $this->runMysqlStatement(implode(';', $statements));
    }

    public function databaseExists(string $database): bool
    {
        $output = $this->runMysqlStatement(
            "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$database}'",
        );

        return trim($output) !== '';
    }

    /**
     * @return array<int, array{type: string, name: string}>
     */
    private function databaseObjects(string $database): array
    {
        $output = $this->runMysqlStatement(
            "SELECT TABLE_TYPE, TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$database}'",
        );
        $objects = [];

        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line), 2);

            if ($parts === false || count($parts) !== 2 || !in_array($parts[0], ['BASE', 'VIEW', 'SYSTEM'], true)) {
                continue;
            }

            $type = $parts[0] === 'VIEW' ? 'VIEW' : 'TABLE';
            $name = $parts[0] === 'BASE' && str_starts_with($parts[1], 'TABLE ')
                ? substr($parts[1], 6)
                : $parts[1];

            if ($name !== '') {
                $objects[] = ['type' => $type, 'name' => $name];
            }
        }

        return $objects;
    }

    public function importSql(string $database, string $sqlPath): void
    {
        $this->runProcess(
            $this->mysqlCommand($this->mysqlBinary, $database),
            [['file', $sqlPath, 'r'], ['pipe', 'w'], ['pipe', 'w']],
        );
    }

    public function exportSql(string $database, string $sqlPath): void
    {
        $this->runProcess(
            $this->mysqlCommand($this->mysqldumpBinary, $database),
            [['pipe', 'r'], ['file', $sqlPath, 'w'], ['pipe', 'w']],
        );
        $this->dumpNormalizer->normalizeFile($sqlPath);
    }

    private function runMysqlStatement(string $sql): string
    {
        return $this->runProcess(
            $this->mysqlStatementCommand(),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $sql . ';',
        );
    }

    /**
     * @return array<int, string>
     */
    private function mysqlCommand(string $binary, ?string $database): array
    {
        $command = [
            $binary,
            '--user=' . $this->params['login'],
            '--default-character-set=utf8mb4',
            '--host=' . $this->params['host'],
            '--protocol=TCP',
        ];

        if (isset($this->params['port'])) {
            $command[] = '--port=' . $this->params['port'];
        }

        if ($database !== null) {
            $command[] = $database;
        }

        return $command;
    }

    /**
     * @return array<int, string>
     */
    private function mysqlStatementCommand(): array
    {
        $command = $this->mysqlCommand($this->mysqlBinary, null);
        $command[] = '--batch';
        $command[] = '--skip-column-names';
        $command[] = '--raw';

        return $command;
    }

    private function resolveBinary(string $binary): string
    {
        if (is_file($binary)) {
            return $binary;
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR) || str_contains($binary, '/') || str_contains($binary, '\\')) {
            return $binary;
        }

        return $binary;
    }

    private function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * @param array<int, string> $command
     * @param array<int, mixed> $descriptors
     */
    private function runProcess(array $command, array $descriptors, ?string $stdin = null): string
    {
        $env = ['MYSQL_PWD' => $this->params['password']];
        $process = proc_open($command, $descriptors, $pipes, null, $env);

        if (!is_resource($process)) {
            throw new DataVeilException('Failed to start MySQL process: ' . implode(' ', $command));
        }

        if ($stdin !== null && isset($pipes[0]) && is_resource($pipes[0])) {
            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
        } elseif (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $stdout = '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $stdout = stream_get_contents($pipes[1]) ?: '';
            fclose($pipes[1]);
        }

        $stderr = '';
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[2]);
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new DataVeilException(trim($stderr) !== '' ? trim($stderr) : 'MySQL process failed');
        }

        return $stdout;
    }
}
