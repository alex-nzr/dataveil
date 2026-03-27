<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Command;

use DataVeil\Command\AnonymizeCommand;
use DataVeil\Command\TestConfigurationCommand;
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\TestCase;

class TestConfigurationCommandTest extends TestCase
{
    public function testValidConfiguration(): void
    {
        $command = new TestConfigurationCommand();
        $tester = new CommandTester($command);

        $configPath = __DIR__ . '/../../../configuration.yaml';

        $tester->execute([
            'config' => $configPath,
        ]);

        $output = $tester->getDisplay();
        
        $this->assertStringContainsString('Configuration valid', $output);
    }

    public function testInvalidConfigPath(): void
    {
        $command = new TestConfigurationCommand();
        $tester = new CommandTester($command);

        $tester->execute([
            'config' => '/nonexistent/config.yaml',
        ]);

        $output = $tester->getDisplay();
        
        $this->assertStringContainsString('Configuration file not found', $output);
    }
}
