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
    private string $settingsFile = '/home/bitrix/www/bitrix/.settings.php';
    private string $connectionName = 'default';

    public function __construct(string $configPath)
    {
        if (!file_exists($configPath)) {
            throw new ConfigValidationException(
                "Configuration file not found: {$configPath}"
            );
        }

        $this->config = Yaml::parseFile($configPath);
        $this->validate();
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
            !in_array($this->config['database']['source'], ['bitrix_settings'])
        ) {
            throw new ConfigValidationException(
                "Undefined 'database.source' type. Expected: ['bitrix_settings']"
            );
        }

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

        /**
         * @todo Add raw mysql config
         */

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
        return [
            'settings_file' => $this->settingsFile,
            'connection_name' => $this->connectionName,
        ];
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
