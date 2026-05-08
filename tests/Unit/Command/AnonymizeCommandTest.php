<?php

declare(strict_types=1);

use Symfony\Component\Console\Tester\CommandTester;
use DataVeil\Anonymizer\AnonymizationPreflight;
use DataVeil\Command\AnonymizeCommand;
use DataVeil\Config\Configuration;
use PHPUnit\Framework\TestCase;

class AnonymizeCommandTest extends TestCase
{
    public function testCommandRuns(): void
    {
        $command = new AnonymizeCommand();
        $tester = new CommandTester($command);

        $configPath = __DIR__ . '/../../configuration.yaml';

        $tester->execute([
            'config' => $configPath,
        ]);

        $output = $tester->getDisplay();
        
        $this->assertStringContainsString('Anonymization', $output);
    }

    public function testCommandFailsOnInvalidConfig(): void
    {
        $command = new AnonymizeCommand();
        $tester = new CommandTester($command);

        $tester->execute([
            'config' => '/nonexistent/config.yaml',
        ]);

        $output = $tester->getDisplay();
        
        $this->assertStringContainsString('not found', $output);
    }

    public function testDryRunMode(): void
    {
        $command = new AnonymizeCommand(new class extends AnonymizationPreflight {
            public function check(Configuration $config): array
            {
                return [
                    'rules' => [
                        [
                            'table' => 'b_user',
                            'action' => 'update',
                            'fields' => 5,
                            'rows' => 10,
                            'errors' => [],
                            'warnings' => [],
                        ],
                    ],
                    'consistency_groups' => [
                        [
                            'id' => 'crm_phone_unique',
                            'anchor_table' => 'b_crm_field_multi',
                            'targets' => 2,
                            'rows' => 10,
                            'errors' => [],
                            'warnings' => [],
                        ],
                    ],
                    'errors' => [],
                    'warnings' => [],
                ];
            }
        });
        $tester = new CommandTester($command);

        $configPath = __DIR__ . '/../../configuration.yaml';

        $tester->execute([
            'config' => $configPath,
            '--dry-run' => true,
        ]);

        $output = $tester->getDisplay();
        
        $this->assertStringContainsString('Dry run', $output);
        $this->assertStringContainsString('Dry run completed successfully', $output);
        $this->assertStringContainsString('rows: 10', $output);
    }

    public function testDryRunFailsOnPreflightErrors(): void
    {
        $command = new AnonymizeCommand(new class extends AnonymizationPreflight {
            public function check(Configuration $config): array
            {
                return [
                    'rules' => [],
                    'consistency_groups' => [],
                    'errors' => ["Table 'missing' does not exist"],
                    'warnings' => [],
                ];
            }
        });
        $tester = new CommandTester($command);

        $configPath = __DIR__ . '/../../configuration.yaml';

        $exitCode = $tester->execute([
            'config' => $configPath,
            '--dry-run' => true,
        ]);

        $output = $tester->getDisplay();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString("Table 'missing' does not exist", $output);
    }
}
