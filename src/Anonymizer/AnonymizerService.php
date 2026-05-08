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

    public function __construct(Configuration $config)
    {
        $this->config = $config;

        $this->connection = (new ConnectionFactory())->create($config);

        $strategyManager = new StrategyManager();
        $serializerHandler = new SerializerHandler();
        $this->consistencyProcessor = new ConsistencyProcessor(
            $this->connection,
            $strategyManager,
            $serializerHandler,
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

        foreach ($rules as $rule) {
            $table = $rule["name"];
            $action = $rule["action"];

            if ($action === "truncate") {
                $this->truncateTable($table);
            } elseif ($action === "update") {
                $this->updateTableFields($rule);
            }
        }
    }

    private function truncateTable(string $table): void
    {
        $escapedTable = $this->connection->escape($table);
        $this->connection->query("TRUNCATE TABLE {$escapedTable}");
    }

    /**
     * @param    array<string, mixed>  $rule
     */
    private function updateTableFields(array $rule): void
    {
        $table = $rule["name"];
        $fields = $rule["fields"] ?? [];
        $where = $rule["where"] ?? "1=1";

        foreach ($fields as $field) {
            $column = $field["column"];
            $strategy = $field["strategy"];
            $options = $field["options"] ?? [];
            $saltSource = $field["salt_source"] ?? null;

            $strategyManager = new StrategyManager();
            if ($strategyManager->getStrategy($strategy, $options) === null) {
                throw new DataVeilException("Strategy {$strategy} not found");
            }

            $stmt = $this->connection->prepare(
                "SELECT ID, {$column} FROM {$table} WHERE {$where}",
            );
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result === false) {
                $stmt->close();
                continue;
            }

            while ($row = $result->fetch_assoc()) {
                $id = $row["ID"];
                $originalValue = $row[$column];

                $salt = null;
                if ($saltSource === "id") {
                    $salt = (string) $id;
                } elseif ($saltSource === "value") {
                    $salt = $originalValue;
                }

                $newValue = $strategyManager->generate(
                    $strategy,
                    $originalValue,
                    is_null($salt) ? $salt : strval($salt),
                    $options,
                );

                $updateStmt = $this->connection->prepare(
                    "UPDATE {$table} SET {$column} = ? WHERE ID = ?",
                );
                $updateStmt->bind_param("si", $newValue, $id);
                $updateStmt->execute();
                $updateStmt->close();
            }

            $stmt->close();
        }
    }

    private function processConsistencyGroups(): void
    {
        $groups = $this->config->getConsistencyGroups();
        $this->consistencyProcessor->processConsistencyGroups($groups);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
