<?php

declare(strict_types=1);

namespace DataVeil\Config;

use DataVeil\Exception\ConfigValidationException;
use Symfony\Component\Yaml\Yaml;

class Configuration
{
    /**
     * @var array<string, mixed>
     */
    private array $config;
    private string $source;
    private string $settingsFile = '/home/bitrix/www/bitrix/.settings.php';
    private string $connectionName = 'default';
    /**
     * @var array<string, string>
     */
    private array $mysqlConfig = [];
    private string $configPath;

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(string $configPath, ?array $config = null)
    {
        if ($config === null && !file_exists($configPath)) {
            throw new ConfigValidationException(
                "Configuration file not found: {$configPath}"
            );
        }

        $this->configPath = $configPath;
        $this->config = $config ?? Yaml::parseFile($configPath);
        $this->validate();
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config, string $configPath = '<array>'): self
    {
        return new self($configPath, $config);
    }

    private function validate(): void
    {
        // Validate source type
        if (!isset($this->config['database'])) {
            throw new ConfigValidationException(
                "Missing 'database' section in configuration"
            );
        }

        if (!isset($this->config['database']['source'])) {
            throw new ConfigValidationException(
                "Missing 'database.source' section in configuration"
            );
        }

        if (
            !is_string($this->config['database']['source']) ||
            !in_array($this->config['database']['source'], ['bitrix_settings', 'mysql'], true)
        ) {
            throw new ConfigValidationException(
                "Undefined 'database.source' type. Expected: ['bitrix_settings', 'mysql']"
            );
        }

        $this->source = $this->config['database']['source'];

        if ($this->config['database']['source'] === 'bitrix_settings') {
            if (!isset($this->config['database']['bitrix'])) {
                throw new ConfigValidationException(
                    "Missing 'database.bitrix' section in configuration"
                );
            }

            $bitrixConfig = $this->config['database']['bitrix'];

            if (!isset($bitrixConfig['settings_file'])) {
                throw new ConfigValidationException(
                    "Missing 'settings_file' in database.bitrix configuration"
                );
            }

            if (!isset($bitrixConfig['connection_name'])) {
                $bitrixConfig['connection_name'] = 'default';
            }

            $this->settingsFile = $bitrixConfig['settings_file'];
            $this->connectionName = $bitrixConfig['connection_name'];
        }

        if ($this->config['database']['source'] === 'mysql') {
            if (!isset($this->config['database']['mysql']) || !is_array($this->config['database']['mysql'])) {
                throw new ConfigValidationException(
                    "Missing 'database.mysql' section in configuration"
                );
            }

            $mysqlConfig = $this->config['database']['mysql'];
            foreach (['host', 'database', 'login'] as $requiredField) {
                if (!isset($mysqlConfig[$requiredField]) || !is_scalar($mysqlConfig[$requiredField])) {
                    throw new ConfigValidationException(
                        "Missing '{$requiredField}' in database.mysql configuration"
                    );
                }
            }

            $this->mysqlConfig = [
                'host' => (string) $mysqlConfig['host'],
                'database' => (string) $mysqlConfig['database'],
                'login' => (string) $mysqlConfig['login'],
                'password' => isset($mysqlConfig['password']) && is_scalar($mysqlConfig['password'])
                    ? (string) $mysqlConfig['password']
                    : '',
            ];
        }

        if (isset($this->config['consistency_groups']) && \is_array($this->config['consistency_groups'])) {
            foreach ($this->config['consistency_groups'] as $index => $group) {
                $this->validateConsistencyGroup($group, $index);
            }
        }
    }

    /**
     * @param      array<string, mixed> $group
     * @param      int     $index
     * @throws     \DataVeil\Exception\ConfigValidationException
     */
    private function validateConsistencyGroup(array $group, int $index): void
    {
        if (!isset($group['anchor'])) {
            throw new ConfigValidationException(
                "Consistency group {$index} missing 'anchor'"
            );
        }

        if (!isset($group['generator'])) {
            throw new ConfigValidationException(
                "Consistency group {$index} missing 'generator'"
            );
        }

        if (!isset($group['targets'])) {
            throw new ConfigValidationException(
                "Consistency group {$index} missing 'targets'"
            );
        }
    }

    /**
     * @todo Return mysql connection data instead of sessings and connection
     * @return     array<string, string>
     */
    public function getDatabaseConfig(): array
    {
        if ($this->source === 'mysql') {
            return ['source' => 'mysql'] + $this->mysqlConfig;
        }

        return [
            'source' => 'bitrix_settings',
            'settings_file' => $this->settingsFile,
            'connection_name' => $this->connectionName,
        ];
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * @return     array<mixed>
     */
    public function getRules(): array
    {
        return $this->config['rules']['tables'] ?? [];
    }

    /**
     * @return     array<mixed>
     */
    public function getConsistencyGroups(): array
    {
        return $this->config['consistency_groups'] ?? [];
    }
}
