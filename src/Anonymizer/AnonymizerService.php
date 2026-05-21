<?php

declare(strict_types=1);

namespace DataVeil\Anonymizer;

use DataVeil\Config\Configuration;
use DataVeil\Database\Connection;
use DataVeil\Database\ConnectionFactory;
use DataVeil\Consistency\ConsistencyProcessor;
use DataVeil\Serializer\SerializerHandler;
use DataVeil\Strategy\StrategyManager;
use DataVeil\Exception\DataVeilException;

class AnonymizerService
{
    private Configuration $config;
    private Connection $connection;
    private ConsistencyProcessor $consistencyProcessor;
    /**
     * @var callable(string, array<string, mixed>): void|null
     */
    private $progressCallback;

    public function __construct(Configuration $config, ?callable $progressCallback = null)
    {
        $this->config = $config;
        $this->progressCallback = $progressCallback;

        $this->connection = (new ConnectionFactory())->create($config);

        $strategyManager = new StrategyManager();
        $serializerHandler = new SerializerHandler();
        $this->consistencyProcessor = new ConsistencyProcessor(
            $this->connection,
            $strategyManager,
            $serializerHandler,
            $progressCallback,
        );
    }

    public function anonymize(): void
    {
        $this->connection->executeInTransaction(function (): void {
            $this->processSimpleRules();
            $this->processConsistencyGroups();
        });
    }

    private function processSimpleRules(): void
    {
        $rules = $this->config->getRules();
        $totalRules = count($rules);

        foreach ($rules as $index => $rule) {
            $table = $rule["name"];
            $action = $rule["action"];

            if ($action === "truncate") {
                $this->truncateTable($table, $index + 1, $totalRules);
            } elseif ($action === "update") {
                $this->updateTableFields($rule, $index + 1, $totalRules);
            }
        }
    }

    private function truncateTable(string $table, int $ruleNumber, int $totalRules): void
    {
        $startedAt = microtime(true);
        $rows = $this->countRows($table, '1=1');
        $this->emitProgress('rule_start', [
            'rule' => $ruleNumber,
            'rules' => $totalRules,
            'table' => $table,
            'action' => 'truncate',
            'rows' => $rows,
            'fields' => 0,
        ]);

        $escapedTable = $this->connection->escape($table);
        $this->connection->query("TRUNCATE TABLE {$escapedTable}");

        $this->emitProgress('rule_done', [
            'rule' => $ruleNumber,
            'rules' => $totalRules,
            'table' => $table,
            'action' => 'truncate',
            'processed' => $rows,
            'rows' => $rows,
            'seconds' => microtime(true) - $startedAt,
        ]);
    }

    /**
     * @param    array<string, mixed>  $rule
     */
    private function updateTableFields(array $rule, int $ruleNumber, int $totalRules): void
    {
        $table = $rule["name"];
        $idColumn = $rule["id_column"] ?? "ID";
        $fields = $rule["fields"] ?? [];
        $where = $rule["where"] ?? "1=1";
        $strategyManager = new StrategyManager();
        $selectColumns = [];

        if ($fields === []) {
            return;
        }

        $startedAt = microtime(true);
        $rowsTotal = $this->countRows($table, $where);
        $this->emitProgress('rule_start', [
            'rule' => $ruleNumber,
            'rules' => $totalRules,
            'table' => $table,
            'action' => 'update',
            'rows' => $rowsTotal,
            'fields' => count($fields),
            'id_column' => $idColumn,
            'where' => $where,
        ]);

        foreach ($fields as $field) {
            $strategy = $field["strategy"];
            $options = $field["options"] ?? [];

            if ($strategyManager->getStrategy($strategy, $options) === null) {
                throw new DataVeilException("Strategy {$strategy} not found");
            }

            $selectColumns[] = $field["column"];
        }

        $selectColumns = array_values(array_unique($selectColumns));

        $stmt = $this->connection->prepare(
            sprintf(
                "SELECT %s AS __dataveil_row_id, %s FROM %s WHERE %s",
                $idColumn,
                implode(", ", $selectColumns),
                $table,
                $where,
            ),
        );
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result === false) {
            $stmt->close();
            return;
        }

        $updateSql = $this->buildUpdateSql($table, $idColumn, $fields);
        $updateStmt = $this->connection->prepare($updateSql);
        $processed = 0;
        $progressInterval = $this->progressInterval($rowsTotal);

        while ($row = $result->fetch_assoc()) {
            $params = [];
            $id = $row["__dataveil_row_id"];

            foreach ($fields as $field) {
                $column = $field["column"];
                $strategy = $field["strategy"];
                $options = $field["options"] ?? [];
                $saltSource = $field["salt_source"] ?? null;
                $originalValue = $row[$column];

                $salt = null;
                if ($saltSource === "id" || $saltSource === "row_id") {
                    $salt = (string) $id;
                } elseif ($saltSource === "value") {
                    $salt = $originalValue;
                }

                $params[] = $strategyManager->generate(
                    $strategy,
                    $originalValue,
                    is_null($salt) ? $salt : strval($salt),
                    $options,
                );
            }

            $params[] = (string) $id;
            $this->bindStringParams($updateStmt, $params);
            $updateStmt->execute();
            $processed++;

            if ($this->shouldReportProgress($processed, $rowsTotal, $progressInterval)) {
                $this->emitProgress('rule_progress', [
                    'rule' => $ruleNumber,
                    'rules' => $totalRules,
                    'table' => $table,
                    'action' => 'update',
                    'processed' => $processed,
                    'rows' => $rowsTotal,
                    'remaining' => max(0, $rowsTotal - $processed),
                    'fields' => count($fields),
                    'seconds' => microtime(true) - $startedAt,
                ]);
            }
        }

        $updateStmt->close();
        $stmt->close();

        $this->emitProgress('rule_done', [
            'rule' => $ruleNumber,
            'rules' => $totalRules,
            'table' => $table,
            'action' => 'update',
            'processed' => $processed,
            'rows' => $rowsTotal,
            'remaining' => 0,
            'fields' => count($fields),
            'seconds' => microtime(true) - $startedAt,
        ]);
    }

    private function countRows(string $table, string $where): int
    {
        $result = $this->connection->query("SELECT COUNT(*) AS CNT FROM {$table} WHERE {$where}");

        if (!($result instanceof \mysqli_result)) {
            return 0;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return (int) ($row['CNT'] ?? 0);
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
     * @param array<int, array<string, mixed>> $fields
     */
    private function buildUpdateSql(string $table, string $idColumn, array $fields): string
    {
        $setParts = [];

        foreach ($fields as $field) {
            $setParts[] = sprintf("%s = ?", (string) $field["column"]);
        }

        return sprintf(
            "UPDATE %s SET %s WHERE %s = ?",
            $table,
            implode(", ", $setParts),
            $idColumn,
        );
    }

    /**
     * @param array<int, string> $params
     */
    private function bindStringParams(\mysqli_stmt $stmt, array $params): void
    {
        $types = str_repeat("s", count($params));
        $bindValues = [$types];

        foreach ($params as $key => $value) {
            $bindValues[] = &$params[$key];
        }

        $stmt->bind_param(...$bindValues);
    }

    private function processConsistencyGroups(): void
    {
        $groups = $this->config->getConsistencyGroups();
        $this->emitProgress('consistency_start', [
            'groups' => count($groups),
        ]);
        $this->consistencyProcessor->processConsistencyGroups($groups);
        $this->emitProgress('consistency_done', [
            'groups' => count($groups),
        ]);
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

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
