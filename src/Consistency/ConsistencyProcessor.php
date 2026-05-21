<?php

declare(strict_types=1);

namespace DataVeil\Consistency;

use DataVeil\Database\Connection;
use DataVeil\Serializer\SerializerHandler;
use DataVeil\Strategy\StrategyManager;

class ConsistencyProcessor
{
    private Connection $connection;
    private StrategyManager $strategyManager;
    private SerializerHandler $serializerHandler;
    /**
     * @var callable(string, array<string, mixed>): void|null
     */
    private $progressCallback;

    public function __construct(
        Connection $connection,
        StrategyManager $strategyManager,
        SerializerHandler $serializerHandler,
        ?callable $progressCallback = null
    ) {
        $this->connection = $connection;
        $this->strategyManager = $strategyManager;
        $this->serializerHandler = $serializerHandler;
        $this->progressCallback = $progressCallback;
    }

    /**
     * @param      array<mixed>  $groups
     */
    public function processConsistencyGroups(array $groups): void
    {
        $totalGroups = count($groups);

        foreach ($groups as $index => $group) {
            $this->processConsistencyGroup($group, $index + 1, $totalGroups);
        }
    }

    /**
     * @param      array<mixed>  $group
     */
    private function processConsistencyGroup(array $group, int $groupNumber, int $totalGroups): void
    {
        $startedAt = microtime(true);
        $anchor = $group['anchor'];
        $generator = $group['generator'];
        $targets = $group['targets'];
        $groupId = (string) ($group['id'] ?? '');

        $anchorTable = $anchor['table'];
        $idColumn = $anchor['id_column'];
        $contextColumns = $anchor['context_columns'] ?? [];

        $filters = $anchor['filters'] ?? [];

        $saltSource = $generator['salt_source'] ?? null;
        $strategyName = $generator['strategy'];
        $deterministic = $generator['deterministic'] ?? false;

        $anchorData = $this->fetchAnchorData($anchorTable, $idColumn, $contextColumns, $filters);
        $rowsTotal = count($anchorData);
        $processed = 0;
        $progressInterval = $this->progressInterval($rowsTotal);

        $this->emitProgress('consistency_group_start', [
            'group' => $groupNumber,
            'groups' => $totalGroups,
            'id' => $groupId,
            'anchor_table' => $anchorTable,
            'targets' => count($targets),
            'rows' => $rowsTotal,
        ]);

        foreach ($anchorData as $anchorRow) {
            $anchorRow['_table'] = $anchorTable;
            $anchorId = $anchorRow[$idColumn];
            $salt = $saltSource === 'row_id' ? (string) $anchorId : null;

            $generatedValue = $this->strategyManager->generate(
                $strategyName,
                $anchorId,
                $deterministic ? $salt : null
            );

            foreach ($targets as $target) {
                $this->updateTarget(
                    $target,
                    $anchorRow,
                    $generatedValue,
                    $anchorId
                );
            }

            $processed++;
            if ($this->shouldReportProgress($processed, $rowsTotal, $progressInterval)) {
                $this->emitProgress('consistency_group_progress', [
                    'group' => $groupNumber,
                    'groups' => $totalGroups,
                    'id' => $groupId,
                    'anchor_table' => $anchorTable,
                    'processed' => $processed,
                    'rows' => $rowsTotal,
                    'remaining' => max(0, $rowsTotal - $processed),
                    'seconds' => microtime(true) - $startedAt,
                ]);
            }
        }

        $this->emitProgress('consistency_group_done', [
            'group' => $groupNumber,
            'groups' => $totalGroups,
            'id' => $groupId,
            'anchor_table' => $anchorTable,
            'processed' => $processed,
            'rows' => $rowsTotal,
            'remaining' => 0,
            'seconds' => microtime(true) - $startedAt,
        ]);
    }

    private function progressInterval(int $rowsTotal): int
    {
        if ($rowsTotal >= 100000) {
            return 10000;
        }

        if ($rowsTotal >= 10000) {
            return 1000;
        }

        return 100;
    }

    private function shouldReportProgress(int $processed, int $rowsTotal, int $progressInterval): bool
    {
        return $processed === 1
            || $processed === $rowsTotal
            || ($progressInterval > 0 && $processed % $progressInterval === 0);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emitProgress(string $event, array $payload = []): void
    {
        if ($this->progressCallback === null) {
            return;
        }

        ($this->progressCallback)($event, $payload);
    }

    /**
     * @param      string  $table           The table
     * @param      string  $idColumn        The identifier column
     * @param      array<mixed>   $contextColumns  The context columns
     * @param      array<mixed>   $filters         The filters
     *
     * @return     array<mixed>   The anchor data.
     */
    private function fetchAnchorData(
        string $table,
        string $idColumn,
        array $contextColumns,
        array $filters
    ): array {
        $params = [];
        $selectColumns = array_values(array_unique(array_merge([$idColumn], $contextColumns)));
        $sql = 'SELECT ' . implode(', ', $selectColumns);
        $sql .= ' FROM ' . $table;

        if (!empty($filters)) {
            $whereClauses = [];
            foreach ($filters as $filter) {
                $column = $filter['column'];
                $value = $filter['value'];
                $whereClauses[] = "{$column} = ?";
                $params[] = $value;
            }
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        $stmt = $this->connection->prepare($sql);

        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        assert($result instanceof \mysqli_result);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();

        return $rows;
    }

    /**
     * @param      array<mixed>   $target     The target
     * @param      array<mixed>   $anchorRow  The anchor row
     * @param      string  $newValue   The new value
     * @param      int     $anchorId   The anchor identifier
     */
    private function updateTarget(
        array $target,
        array $anchorRow,
        string $newValue,
        int $anchorId
    ): void {
        $targetTable = $target['table'];
        $targetColumn = $target['column'];

        $join = $target['join'] ?? [];

        if (($join['type'] ?? null) === 'serialized_value_match') {
            $serializationConfig = $target['serialization'] ?? [];
            $this->updateSerializedField(
                $targetTable,
                $targetColumn,
                $anchorRow,
                $newValue,
                $join,
                is_array($serializationConfig) ? $serializationConfig : [],
            );
        } else {
            $this->updatePlainField($targetTable, $targetColumn, $anchorRow, $newValue, $join, $anchorId);
        }
    }

    /**
     * @param      string  $table      The table
     * @param      string  $column     The column
     * @param      array<mixed>   $anchorRow  The anchor row
     * @param      string  $newValue   The new value
     * @param      array<mixed>   $join       The join
     * @param      int     $anchorId   The anchor identifier
     */
    private function updatePlainField(
        string $table,
        string $column,
        array $anchorRow,
        string $newValue,
        array $join,
        int $anchorId
    ): void {
        if ($table === ($anchorRow['_table'] ?? null)) {
            $idColumn = $join['key_column'] ?? 'ID';
            $this->updateById($table, $column, $newValue, $anchorId, $idColumn);
        } elseif (isset($join['key_column'], $join['ref_column'])) {
            $keyColumn = $join['key_column'];
            $refColumn = $join['ref_column'];
            $anchorValue = $anchorRow[$refColumn] ?? null;

            if ($anchorValue !== null) {
                $this->updateByValue($table, $column, $newValue, $keyColumn, $anchorValue);
            }
        } else {
            $idColumn = 'ID';
            $this->updateById($table, $column, $newValue, $anchorId, $idColumn);
        }
    }

    /**
     * @param      string  $table      The table
     * @param      string  $column     The column
     * @param      array<mixed>   $anchorRow  The anchor row
     * @param      string  $newValue   The new value
     * @param      array<mixed>   $join       The join
     * @param      array<string, mixed> $serializationConfig The serialization config
     */
    private function updateSerializedField(
        string $table,
        string $column,
        array $anchorRow,
        string $newValue,
        array $join,
        array $serializationConfig
    ): void {
        $rows = $this->getSerializedRows($table, $column, $anchorRow, $join);
        $matchKey = (string) ($serializationConfig['match_key'] ?? '');
        $matchValueSource = $serializationConfig['match_value_source'] ?? '';
        $matchValue = $this->extractAnchorValue($matchValueSource, $anchorRow);

        if ($matchKey === '' || $matchValue === null) {
            return;
        }

        foreach ($rows as $row) {
            $serializedData = $row[$column] ?? null;

            if (!is_string($serializedData)) {
                continue;
            }

            $newSerialized = $this->serializerHandler->findAndReplaceInSerialized(
                $serializedData,
                $matchKey,
                strval($matchValue),
                $newValue
            );

            $this->updateSerializedInTable($table, $column, $newSerialized, (int) $row['ID']);
        }
    }

    /**
     * @param      string       $table      The table
     * @param      string       $column     The column
     * @param      array<mixed>        $anchorRow  The anchor row
     * @param      array<mixed>        $join       The join
     *
     * @return     array<int, array<string, mixed>>
     */
    private function getSerializedRows(
        string $table,
        string $column,
        array $anchorRow,
        array $join
    ): array {
        $entityIdColumn = (string) ($join['entity_id_column'] ?? 'OWNER_ID');
        $refEntityColumn = (string) ($join['ref_entity_column'] ?? 'ENTITY_ID');
        $entityId = $anchorRow[$refEntityColumn] ?? null;

        if ($entityId === null) {
            return [];
        }

        $where = ["{$entityIdColumn} = ?"];
        $params = [$entityId];
        $types = is_int($entityId) || ctype_digit((string) $entityId) ? 'i' : 's';

        if (isset($join['filters']) && \is_array($join['filters'])) {
            foreach ($join['filters'] as $filter) {
                if (!is_array($filter) || !isset($filter['column'])) {
                    continue;
                }

                $where[] = "{$filter['column']} = ?";
                $filterValue = $this->resolveFilterValue($filter, $anchorRow);
                $params[] = $filterValue;
                $types .= is_int($filterValue) || ctype_digit((string) $filterValue) ? 'i' : 's';
            }
        }

        $sql = sprintf(
            'SELECT ID, %s FROM %s WHERE %s',
            $column,
            $table,
            implode(' AND ', $where),
        );
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        assert($result instanceof \mysqli_result);
        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();

        return $rows;
    }

    /**
     * @param      string          $source
     * @param      array<mixed>    $anchorRow
     *
     * @return     string|null
     */
    private function extractAnchorValue(string $source, array $anchorRow): ?string
    {
        if ($source === 'anchor.VALUE' || $source === 'anchor.VALUE') {
            return $anchorRow['VALUE'] ?? null;
        }

        if (str_starts_with($source, 'anchor.')) {
            $key = substr($source, 7);
            return $anchorRow[$key] ?? null;
        }

        return null;
    }

    /**
     * @param      string  $table          The table
     * @param      string  $column         The column
     * @param      string  $newSerialized  The new serialized
     * @param      int     $id             The row identifier
     */
    private function updateSerializedInTable(
        string $table,
        string $column,
        string $newSerialized,
        int $id
    ): void {
        $stmt = $this->connection->prepare(
            "UPDATE {$table} SET {$column} = ? WHERE ID = ?"
        );
        $stmt->bind_param('si', $newSerialized, $id);
        $stmt->execute();
        $stmt->close();
    }

    private function updateById(string $table, string $column, string $value, int $id, string $idColumn): void
    {
        $stmt = $this->connection->prepare(
            "UPDATE {$table} SET {$column} = ? WHERE {$idColumn} = ?"
        );
        $stmt->bind_param('si', $value, $id);
        $stmt->execute();
        $stmt->close();
    }

    private function updateByValue(
        string $table,
        string $column,
        string $value,
        string $keyColumn,
        mixed $anchorValue
    ): void {
        $stmt = $this->connection->prepare(
            "UPDATE {$table} SET {$column} = ? WHERE {$keyColumn} = ?"
        );
        $stmt->bind_param('ss', $value, $anchorValue);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $anchorRow
     */
    private function resolveFilterValue(array $filter, array $anchorRow): mixed
    {
        if (isset($filter['value_source']) && is_string($filter['value_source'])) {
            return $this->extractAnchorValue($filter['value_source'], $anchorRow);
        }

        return $filter['value'] ?? '';
    }
}
