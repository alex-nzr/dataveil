<?php

declare(strict_types=1);

namespace DataVeil\Database;

use DataVeil\Config\BitrixSettingsParser;
use DataVeil\Config\Configuration;
use DataVeil\Exception\DatabaseException;

class ConnectionDiagnostics
{
    /**
     * @return array<string, scalar|null>
     */
    public function test(Configuration $config): array
    {
        $dbConfig = $config->getDatabaseConfig();
        $parser = new BitrixSettingsParser(
            $dbConfig['settings_file'],
            $dbConfig['connection_name'],
        );

        $connectionParams = $parser->parse();
        $connection = new Connection(
            $connectionParams['host'],
            $connectionParams['database'],
            $connectionParams['login'],
            $connectionParams['password'],
        );

        $connection->query('SELECT 1');
        $result = $connection->query(
            'SELECT DATABASE() AS db, VERSION() AS version, @@hostname AS host_name'
        );

        if (!($result instanceof \mysqli_result)) {
            throw new DatabaseException('Expected mysqli_result for diagnostics query');
        }

        $row = $result->fetch_assoc();
        $result->free();

        return [
            'host' => $connectionParams['host'],
            'database' => $connectionParams['database'],
            'login' => $connectionParams['login'],
            'server_version' => is_array($row) ? ($row['version'] ?? null) : null,
            'server_hostname' => is_array($row) ? ($row['host_name'] ?? null) : null,
            'selected_database' => is_array($row) ? ($row['db'] ?? null) : null,
            'charset' => $connection->getConnection()->character_set_name(),
        ];
    }
}
