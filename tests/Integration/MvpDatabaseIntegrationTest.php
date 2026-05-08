<?php

declare(strict_types=1);

namespace DataVeil\Tests\Integration;

use DataVeil\Anonymizer\AnonymizationPreflight;
use DataVeil\Config\BitrixSettingsParser;
use DataVeil\Config\Configuration;
use DataVeil\Database\Connection;
use mysqli_result;
use PHPUnit\Framework\TestCase;

class MvpDatabaseIntegrationTest extends TestCase
{
    private Configuration $config;
    private Connection $connection;

    protected function setUp(): void
    {
        $configPath = getenv('DATAVEIL_INTEGRATION_CONFIG');

        if (!is_string($configPath) || $configPath === '') {
            self::markTestSkipped('Set DATAVEIL_INTEGRATION_CONFIG to run MVP database integration tests.');
        }

        $this->config = new Configuration($configPath);
        $dbConfig = $this->config->getDatabaseConfig();
        $connectionParams = (new BitrixSettingsParser(
            $dbConfig['settings_file'],
            $dbConfig['connection_name'],
        ))->parse();

        $this->connection = new Connection(
            $connectionParams['host'],
            $connectionParams['database'],
            $connectionParams['login'],
            $connectionParams['password'],
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    public function testMvpConfigurationPreflightPassesOnRealDatabase(): void
    {
        $report = (new AnonymizationPreflight())->check($this->config);

        $this->assertSame([], $report['errors']);
        $this->assertNotEmpty($report['rules']);
        $this->assertNotEmpty($report['consistency_groups']);
    }

    public function testCoreCrmTablesAreCovered(): void
    {
        $configuredTables = $this->configuredRuleTables();

        foreach ([
            'b_user',
            'b_crm_lead',
            'b_crm_contact',
            'b_crm_company',
            'b_crm_deal',
            'b_crm_requisite',
            'b_crm_bank_detail',
            'b_crm_addr',
            'b_crm_timeline',
        ] as $table) {
            $this->assertContains($table, $configuredTables);
        }
    }

    public function testDynamicItemsTablesFromDatabaseAreCovered(): void
    {
        $configuredTables = $this->configuredRuleTables();

        foreach ($this->getDynamicItemTables() as $table) {
            $this->assertContains($table, $configuredTables);
        }
    }

    public function testLinkLikeUserFieldsAreNotConfiguredForAnonymization(): void
    {
        $configuredFields = $this->configuredFieldsByTable();
        $linkLikePrefixes = [
            'UF_CRM',
            'UF_IBLOCK',
            'UF_EMPLOYEE',
            'UF_FILE',
            'UF_HL',
            'UF_LIST',
        ];

        foreach ($configuredFields as $table => $fields) {
            if (!str_starts_with($table, 'b_crm_dynamic_items_')) {
                continue;
            }

            foreach ($fields as $field) {
                foreach ($linkLikePrefixes as $prefix) {
                    $this->assertFalse(
                        str_starts_with($field, $prefix) && !str_starts_with($field, 'UF_STRING'),
                        "Link-like field {$table}.{$field} must not be anonymized",
                    );
                }
            }
        }
    }

    public function testJobTitleStrategyIsConfiguredForPostFields(): void
    {
        $fieldsByTable = $this->configuredFieldsByTable(true);

        $this->assertSame('job_title_fake', $fieldsByTable['b_crm_lead']['POST'] ?? null);
        $this->assertSame('job_title_fake', $fieldsByTable['b_crm_contact']['POST'] ?? null);
    }

    /**
     * @return array<int, string>
     */
    private function configuredRuleTables(): array
    {
        return array_map(
            static fn (array $rule): string => (string) $rule['name'],
            $this->config->getRules(),
        );
    }

    /**
     * @return array<string, array<int|string, string>>
     */
    private function configuredFieldsByTable(bool $strategyByColumn = false): array
    {
        $fieldsByTable = [];

        foreach ($this->config->getRules() as $rule) {
            $table = (string) $rule['name'];
            $fieldsByTable[$table] = [];

            foreach (($rule['fields'] ?? []) as $field) {
                if (!is_array($field) || !isset($field['column'])) {
                    continue;
                }

                if ($strategyByColumn) {
                    $fieldsByTable[$table][(string) $field['column']] = (string) ($field['strategy'] ?? '');
                } else {
                    $fieldsByTable[$table][] = (string) $field['column'];
                }
            }
        }

        return $fieldsByTable;
    }

    /**
     * @return array<int, string>
     */
    private function getDynamicItemTables(): array
    {
        $result = $this->connection->query("SHOW TABLES LIKE 'b_crm_dynamic_items_%'");
        self::assertInstanceOf(mysqli_result::class, $result);
        $tables = [];

        while ($row = $result->fetch_row()) {
            if (isset($row[0]) && is_string($row[0]) && preg_match('/^b_crm_dynamic_items_\d+$/', $row[0]) === 1) {
                $tables[] = $row[0];
            }
        }

        sort($tables, SORT_NATURAL);

        return $tables;
    }
}
