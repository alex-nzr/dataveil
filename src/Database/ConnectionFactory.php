<?php

declare(strict_types=1);

namespace DataVeil\Database;

use DataVeil\Config\BitrixSettingsParser;
use DataVeil\Config\Configuration;

class ConnectionFactory
{
    public function create(Configuration $config): Connection
    {
        $params = $this->resolveParams($config);

        return new Connection(
            $params['host'],
            $params['database'],
            $params['login'],
            $params['password'],
        );
    }

    /**
     * @return array{host: string, database: string, login: string, password: string, port?: string}
     */
    public function resolveParams(Configuration $config): array
    {
        $dbConfig = $config->getDatabaseConfig();

        if (($dbConfig['source'] ?? '') === 'mysql') {
            $params = [
                'host' => (string) $dbConfig['host'],
                'database' => (string) $dbConfig['database'],
                'login' => (string) $dbConfig['login'],
                'password' => (string) ($dbConfig['password'] ?? ''),
            ];

            if (isset($dbConfig['port'])) {
                $params['port'] = (string) $dbConfig['port'];
            }

            return $params;
        }

        $parser = new BitrixSettingsParser(
            $dbConfig['settings_file'],
            $dbConfig['connection_name'],
        );
        $connectionParams = $parser->parse();

        return [
            'host' => $connectionParams['host'],
            'database' => $connectionParams['database'],
            'login' => $connectionParams['login'],
            'password' => $connectionParams['password'],
        ];
    }
}
