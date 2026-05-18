<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Command;

use DataVeil\Backup\BackupAnonymizationService;
use DataVeil\Command\BackupAnonymizeCommand;
use DataVeil\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class BackupAnonymizeCommandTest extends TestCase
{
    public function testPassesReuseTempDatabaseOptionToService(): void
    {
        $service = new class extends BackupAnonymizationService {
            /**
             * @var array<string, mixed>
             */
            public array $lastOptions = [];

            /**
             * @param array<string, mixed> $options
             * @return array<string, mixed>
             */
            public function run(Configuration $config, array $options): array
            {
                $this->lastOptions = $options;

                return [
                    'input' => (string) $options['input'],
                    'output' => null,
                    'temp_db' => (string) $options['temp_db'],
                    'dry_run' => true,
                    'rules' => 0,
                    'consistency_groups' => 0,
                ];
            }
        };

        $tester = new CommandTester(new BackupAnonymizeCommand($service));
        $exitCode = $tester->execute([
            'config' => __DIR__ . '/../../configuration.yaml',
            '--input' => 'backup.sql',
            '--temp-db' => 'dataveil_tmp_reuse',
            '--dry-run' => true,
            '--reuse-temp-db' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertTrue($service->lastOptions['reuse_temp_db']);
    }
}
