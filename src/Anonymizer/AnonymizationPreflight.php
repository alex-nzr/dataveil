<?php

declare(strict_types=1);

namespace DataVeil\Anonymizer;

use DataVeil\Config\BitrixSettingsParser;
use DataVeil\Config\Configuration;
use DataVeil\Database\Connection;
use DataVeil\Exception\DataVeilException;
use DataVeil\Strategy\StrategyManager;
use mysqli_result;

class AnonymizationPreflight
{
    private StrategyManager $strategyManager;

    public function __construct(?StrategyManager $strategyManager = null)
    {
        $this->strategyManager = $strategyManager ?? new StrategyManager();
    }

    /**
     * @return array{
     *     rules: array<int, array<string, mixed>>,
     *     consistency_groups: array<int, array<string, mixed>>,
     *     errors: array<int, string>,
     *     warnings: array<int, string>
     * }
     */
    public function check(Configuration $config): array
    {
        $connection = $this->createConnection($config);
        $tables = $connection->getTables();
        $tableColumns = [];
        $errors = [];
        $warnings = [];
        $ruleReports = [];
        $groupReports = [];

        foreach ($config->getRules() as $rule) {
            $report = $this->checkRule($connection, $tables, $tableColumns, $rule);
            $ruleReports[] = $report;
            $errors = array_merge($errors, $report['errors']);
            $warnings = array_merge($warnings, $report['warnings']);
        }

        foreach ($config->getConsistencyGroups() as $group) {
            $report = $this->checkConsistencyGroup($connection, $tables, $tableColumns, $group);
            $groupReports[] = $report;
            $errors = array_merge($errors, $report['errors']);
            $warnings = array_merge($warnings, $report['warnings']);
        }

        $connection->close();

        return [
            'rules' => $ruleReports,
            'consistency_groups' => $groupReports,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    protected function createConnection(Configuration $config): Connection
    {
        $dbConfig = $config->getDatabaseConfig();
        $parser = new BitrixSettingsParser(
            $dbConfig['settings_file'],
            $dbConfig['connection_name'],
        );
        $connectionParams = $parser->parse();

        return new Connection(
            $connectionParams['host'],
            $connectionParams['database'],
            $connectionParams['login'],
            $connectionParams['password'],
        );
    }

    /**
     * @param array<int, string> $tables
     * @param array<string, array<int, string>> $tableColumns
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function checkRule(
        Connection $connection,
        array $tables,
        array &$tableColumns,
        array $rule
    ): array {
        $table = (string) ($rule['name'] ?? '');
        $action = (string) ($rule['action'] ?? '');
        $fields = is_array($rule['fields'] ?? null) ? $rule['fields'] : [];
        $where = isset($rule['where']) && is_string($rule['where']) ? $rule['where'] : '1=1';
        $errors = [];
        $warnings = [];
        $rows = null;

        if ($table === '') {
            $errors[] = 'Rule table name is empty';
        } elseif (!in_array($table, $tables, true)) {
            $errors[] = "Table '{$table}' does not exist";
        } else {
            $columns = $this->getColumns($connection, $tableColumns, $table);

            if ($action === 'update') {
                if (!in_array('ID', $columns, true)) {
                    $errors[] = "Table '{$table}' must have ID column for update rules";
                }

                foreach ($fields as $field) {
                    if (!is_array($field)) {
                        $errors[] = "Rule '{$table}' has invalid field definition";
                        continue;
                    }

                    $column = (string) ($field['column'] ?? '');
                    $strategy = (string) ($field['strategy'] ?? '');
                    $options = is_array($field['options'] ?? null) ? $field['options'] : [];

                    if ($column === '' || !in_array($column, $columns, true)) {
                        $errors[] = "Column '{$table}.{$column}' does not exist";
                    }

                    if ($strategy === '' || $this->strategyManager->getStrategy($strategy, $options) === null) {
                        $errors[] = "Strategy '{$strategy}' is not registered";
                    }
                }

                if ($errors === []) {
                    $rows = $this->countRows($connection, $table, $where);
                }
            } elseif ($action === 'truncate') {
                $rows = $this->countRows($connection, $table, '1=1');
            } else {
                $errors[] = "Action '{$action}' is not supported for table '{$table}'";
            }
        }

        if ($fields === [] && $action === 'update') {
            $warnings[] = "Update rule for table '{$table}' has no fields";
        }

        return [
            'table' => $table,
            'action' => $action,
            'fields' => count($fields),
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int, string> $tables
     * @param array<string, array<int, string>> $tableColumns
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function checkConsistencyGroup(
        Connection $connection,
        array $tables,
        array &$tableColumns,
        array $group
    ): array {
        $id = (string) ($group['id'] ?? '');
        $anchor = is_array($group['anchor'] ?? null) ? $group['anchor'] : [];
        $generator = is_array($group['generator'] ?? null) ? $group['generator'] : [];
        $targets = is_array($group['targets'] ?? null) ? $group['targets'] : [];
        $anchorTable = (string) ($anchor['table'] ?? '');
        $idColumn = (string) ($anchor['id_column'] ?? '');
        $contextColumns = is_array($anchor['context_columns'] ?? null) ? $anchor['context_columns'] : [];
        $filters = is_array($anchor['filters'] ?? null) ? $anchor['filters'] : [];
        $strategy = (string) ($generator['strategy'] ?? '');
        $errors = [];
        $warnings = [];
        $rows = null;

        if ($id === '') {
            $errors[] = 'Consistency group id is empty';
        }

        if ($anchorTable === '' || !in_array($anchorTable, $tables, true)) {
            $errors[] = "Consistency group '{$id}' anchor table '{$anchorTable}' does not exist";
        } else {
            $anchorColumns = $this->getColumns($connection, $tableColumns, $anchorTable);
            foreach (array_merge([$idColumn], $contextColumns, $this->filterColumns($filters)) as $column) {
                if (!is_string($column) || $column === '') {
                    continue;
                }

                if (!in_array($column, $anchorColumns, true)) {
                    $errors[] = "Consistency group '{$id}' anchor column '{$anchorTable}.{$column}' does not exist";
                }
            }

            if ($errors === []) {
                $rows = $this->countRows($connection, $anchorTable, $this->filtersToWhere($connection, $filters));
            }
        }

        if ($strategy === '' || $this->strategyManager->getStrategy($strategy) === null) {
            $errors[] = "Consistency group '{$id}' strategy '{$strategy}' is not registered";
        }

        foreach ($targets as $target) {
            if (!is_array($target)) {
                $errors[] = "Consistency group '{$id}' has invalid target definition";
                continue;
            }

            $targetTable = (string) ($target['table'] ?? '');
            $targetColumn = (string) ($target['column'] ?? '');
            if ($targetTable === '' || !in_array($targetTable, $tables, true)) {
                $errors[] = "Consistency group '{$id}' target table '{$targetTable}' does not exist";
                continue;
            }

            $targetColumns = $this->getColumns($connection, $tableColumns, $targetTable);
            if ($targetColumn === '' || !in_array($targetColumn, $targetColumns, true)) {
                $errors[] = "Consistency group '{$id}' target column '{$targetTable}.{$targetColumn}' does not exist";
            }
        }

        if ($targets === []) {
            $warnings[] = "Consistency group '{$id}' has no targets";
        }

        return [
            'id' => $id,
            'anchor_table' => $anchorTable,
            'targets' => count($targets),
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    protected function countRows(Connection $connection, string $table, string $where): int
    {
        $sql = sprintf(
            'SELECT COUNT(*) AS ROWS_COUNT FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            $where === '' ? '1=1' : $where,
        );
        $result = $connection->query($sql);

        if (!($result instanceof mysqli_result)) {
            throw new DataVeilException('Expected mysqli_result for preflight count query');
        }

        $row = $result->fetch_assoc();
        $result->free();

        return is_array($row) ? (int) ($row['ROWS_COUNT'] ?? 0) : 0;
    }

    /**
     * @param array<string, array<int, string>> $tableColumns
     * @return array<int, string>
     */
    private function getColumns(Connection $connection, array &$tableColumns, string $table): array
    {
        if (!isset($tableColumns[$table])) {
            $tableColumns[$table] = array_map(
                static fn (array $column): string => (string) ($column['Field'] ?? ''),
                $connection->getTableColumns($table),
            );
        }

        return $tableColumns[$table];
    }

    /**
     * @param array<int, mixed> $filters
     * @return array<int, string>
     */
    private function filterColumns(array $filters): array
    {
        $columns = [];

        foreach ($filters as $filter) {
            if (is_array($filter) && isset($filter['column']) && is_string($filter['column'])) {
                $columns[] = $filter['column'];
            }
        }

        return $columns;
    }

    /**
     * @param array<int, mixed> $filters
     */
    private function filtersToWhere(Connection $connection, array $filters): string
    {
        if ($filters === []) {
            return '1=1';
        }

        $where = [];

        foreach ($filters as $filter) {
            if (!is_array($filter) || !isset($filter['column'])) {
                continue;
            }

            $column = (string) $filter['column'];
            $value = (string) ($filter['value'] ?? '');
            $where[] = sprintf(
                '%s = \'%s\'',
                $this->quoteIdentifier($column),
                $connection->escape($value),
            );
        }

        return $where === [] ? '1=1' : implode(' AND ', $where);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
