<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Anonymizer;

use DataVeil\Anonymizer\AnonymizationPreflight;
use DataVeil\Config\Configuration;
use DataVeil\Database\Connection;
use PHPUnit\Framework\TestCase;

class AnonymizationPreflightTest extends TestCase
{
    public function testReportsRulesAndConsistencyGroups(): void
    {
        $configPath = __DIR__ . '/../../configuration.yaml';
        $preflight = new class extends AnonymizationPreflight {
            protected function createConnection(Configuration $config): Connection
            {
                return new class extends Connection {
                    public function __construct()
                    {
                        parent::__construct('localhost', 'test', 'root', '');
                    }

                    public function getTables(): array
                    {
                        return ['b_search_content', 'b_user', 'b_crm_field_multi', 'b_crm_act_comm'];
                    }

                    public function getTableColumns(string $table): array
                    {
                        $columns = [
                            'b_search_content' => ['ID'],
                            'b_user' => ['ID', 'EMAIL', 'LOGIN', 'NAME', 'LAST_NAME', 'PERSONAL_PHONE'],
                            'b_crm_field_multi' => ['ID', 'ENTITY_ID', 'VALUE', 'TYPE_ID'],
                            'b_crm_act_comm' => ['ID', 'OWNER_ID', 'ENTITY_SETTINGS'],
                        ];

                        return array_map(
                            static fn (string $column): array => ['Field' => $column],
                            $columns[$table] ?? [],
                        );
                    }

                    public function close(): void
                    {
                    }

                    public function escape(string $value): string
                    {
                        return addslashes($value);
                    }
                };
            }

            protected function countRows(Connection $connection, string $table, string $where): int
            {
                return 5;
            }
        };

        $report = $preflight->check(new Configuration($configPath));

        $this->assertSame([], $report['errors']);
        $this->assertCount(2, $report['rules']);
        $this->assertCount(2, $report['consistency_groups']);
        $this->assertSame(5, $report['rules'][0]['rows']);
    }

    public function testUpdateRuleCanUseCustomIdColumn(): void
    {
        $configPath = tempnam(sys_get_temp_dir(), 'dataveil-preflight-');
        $this->assertIsString($configPath);

        file_put_contents(
            $configPath,
            <<<'YAML'
version: 1.0
database:
    source: mysql
    mysql:
        host: localhost
        database: test
        login: root
        password: ""
rules:
    tables:
        - name: b_uts_crm_contact
          action: update
          id_column: VALUE_ID
          fields:
              - column: UF_CRM_COMMENT
                strategy: string_random
YAML,
        );

        $preflight = new class extends AnonymizationPreflight {
            protected function createConnection(Configuration $config): Connection
            {
                return new class extends Connection {
                    public function __construct()
                    {
                        parent::__construct('localhost', 'test', 'root', '');
                    }

                    public function getTables(): array
                    {
                        return ['b_uts_crm_contact'];
                    }

                    public function getTableColumns(string $table): array
                    {
                        return array_map(
                            static fn (string $column): array => ['Field' => $column],
                            ['VALUE_ID', 'UF_CRM_COMMENT'],
                        );
                    }

                    public function close(): void
                    {
                    }
                };
            }

            protected function countRows(Connection $connection, string $table, string $where): int
            {
                return 3;
            }
        };

        try {
            $report = $preflight->check(new Configuration($configPath));
        } finally {
            unlink($configPath);
        }

        $this->assertSame([], $report['errors']);
        $this->assertSame(3, $report['rules'][0]['rows']);
    }
}
