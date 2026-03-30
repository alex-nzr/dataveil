<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Command;

use DataVeil\Command\TestDbConnectionCommand;
use DataVeil\Config\Configuration;
use DataVeil\Database\ConnectionDiagnostics;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestDbConnectionCommandTest extends TestCase
{
    public function testValidConnectionOutput(): void
    {
        $command = new TestDbConnectionCommand(new class extends ConnectionDiagnostics {
            public function test(Configuration $config): array
            {
                return [
                    'host' => 'db-host',
                    'database' => 'test_database',
                    'login' => 'test_user',
                    'selected_database' => 'test_database',
                    'server_version' => '10.3.x-MariaDB',
                    'server_hostname' => 'test-server',
                    'charset' => 'utf8mb4',
                ];
            }
        });

        $tester = new CommandTester($command);
        $configPath = __DIR__ . '/../../../configuration.yaml';

        $exitCode = $tester->execute([
            'config' => $configPath,
        ]);

        $output = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Database connection is valid', $output);
        $this->assertStringContainsString('test_database', $output);
        $this->assertStringContainsString('utf8mb4', $output);
    }

    public function testInvalidConfigPath(): void
    {
        $command = new TestDbConnectionCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'config' => '/nonexistent/config.yaml',
        ]);

        $output = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Configuration file not found', $output);
    }

    public function testConnectionFailure(): void
    {
        $command = new TestDbConnectionCommand(new class extends ConnectionDiagnostics {
            public function test(Configuration $config): array
            {
                throw new \RuntimeException('Access denied');
            }
        });

        $tester = new CommandTester($command);
        $configPath = __DIR__ . '/../../../configuration.yaml';

        $exitCode = $tester->execute([
            'config' => $configPath,
        ]);

        $output = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Database connection test failed', $output);
        $this->assertStringContainsString('Access denied', $output);
    }
}
