<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Config;

use DataVeil\Config\Configuration;
use DataVeil\Exception\ConfigValidationException;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    public function testLoadValidConfiguration(): void
    {
        $configPath = __DIR__ . '/../../configuration.yaml';

        $this->assertFileExists($configPath);

        $config = new Configuration($configPath);

        $dbConfig = $config->getDatabaseConfig();

        $this->assertArrayHasKey('settings_file', $dbConfig);
        $this->assertArrayHasKey('connection_name', $dbConfig);
    }

    public function testMissingConfigurationFile(): void
    {
        $this->expectException(ConfigValidationException::class);

        new Configuration('/nonexistent/config.yaml');
    }

    public function testGetConsistencyGroups(): void
    {
        $configPath = __DIR__ . '/../../configuration.yaml';
        $config = new Configuration($configPath);

        $groups = $config->getConsistencyGroups();

        $this->assertIsArray($groups);
    }

    public function testGetRules(): void
    {
        $configPath = __DIR__ . '/../../configuration.yaml';
        $config = new Configuration($configPath);

        $rules = $config->getRules();

        $this->assertIsArray($rules);
    }

}
