<?php

declare(strict_types=1);

namespace DataVeil\Backup;

use DataVeil\Exception\DataVeilException;

class MysqlClient
{
    /**
     * @param array{host: string, login: string, password: string} $params
     */
    public function __construct(
        private readonly array $params,
        string $mysqlBinary = 'mysql',
        string $mysqldumpBinary = 'mysqldump',
    ) {
        $this->openServerSettings = $this->resolveOpenServerSettings((string) $params['host']);
        $this->mysqlBinary = $this->resolveBinary($mysqlBinary);
        $this->mysqldumpBinary = $this->resolveBinary($mysqldumpBinary);
    }

    private string $mysqlBinary;
    private string $mysqldumpBinary;
    /**
     * @var array{host: string, port: string, socket: string}|null
     */
    private ?array $openServerSettings = null;

    public function createDatabase(string $database): void
    {
        $this->runMysqlStatement("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    public function dropDatabase(string $database): void
    {
        $this->runMysqlStatement("DROP DATABASE `{$database}`");
    }

    public function databaseExists(string $database): bool
    {
        $output = $this->runMysqlStatement(
            "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$database}'",
        );

        return trim($output) !== '';
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
    }

    private function runMysqlStatement(string $sql): string
    {
        return $this->runProcess(
            $this->mysqlCommand($this->mysqlBinary, null),
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
        ];

        if ($this->openServerSettings !== null) {
            $command[] = '--protocol=PIPE';
            $command[] = '--socket=' . $this->openServerSettings['socket'];
            $command[] = '--host=';
        } else {
            $command[] = '--host=' . $this->params['host'];
            $command[] = '--port=3306';
            $command[] = '--protocol=TCP';
        }

        if ($database !== null) {
            $command[] = $database;
        }

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

        $module = (string) $this->params['host'];
        $moduleBinary = 'D:/OpenServer/modules/' . $module . '/bin/' . $binary . '.exe';
        if (is_file($moduleBinary)) {
            return $moduleBinary;
        }

        $openServerMatches = glob('D:/OpenServer/modules/*/bin/' . $binary . '.exe');
        if (is_array($openServerMatches) && $openServerMatches !== []) {
            rsort($openServerMatches, SORT_NATURAL);

            return $openServerMatches[0];
        }

        return $binary;
    }

    /**
     * @return array{host: string, port: string, socket: string}|null
     */
    private function resolveOpenServerSettings(string $moduleName): ?array
    {
        $settingsPath = 'D:/OpenServer/config/' . $moduleName . '/default/settings.ini';
        if (!is_file($settingsPath)) {
            return null;
        }

        $settings = parse_ini_file($settingsPath, false, INI_SCANNER_RAW);
        if (!is_array($settings)) {
            return null;
        }

        return [
            'host' => (string) ($settings['ip'] ?? '127.0.0.1'),
            'port' => (string) ($settings['port'] ?? '3306'),
            'socket' => $moduleName,
        ];
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
