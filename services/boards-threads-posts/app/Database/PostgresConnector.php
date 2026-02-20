<?php
declare(strict_types=1);

/*
 * Copyright 2026 txrx-byte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


namespace App\Database;

use Hyperf\Database\Connectors\Connector;
use Hyperf\Database\Connectors\ConnectorInterface;
use PDO;

class PostgresConnector extends Connector implements ConnectorInterface
{
    /**
     * Establish a database connection.
     *
     * @param array<string, mixed> $config
     */
    public function connect(array $config): PDO
    {
        $dsn = $this->getDsn($config);
        $options = $this->getOptions($config);
        $connection = $this->createConnection($dsn, $config, $options);

        if (isset($config['charset']) && is_string($config['charset'])) {
            $charset = $config['charset'];
            $connection->prepare("set names '{$charset}'")->execute();
        }

        if (isset($config['timezone']) && is_string($config['timezone'])) {
            $timezone = $config['timezone'];
            $connection->prepare("set timezone to '{$timezone}'")->execute();
        }

        if (isset($config['schema'])) {
            $schema = $this->formatSchema($config['schema']);
            $connection->prepare("set search_path to {$schema}")->execute();
        }

        if (isset($config['application_name']) && is_string($config['application_name'])) {
            $applicationName = $config['application_name'];
            $connection->prepare("set application_name to '{$applicationName}'")->execute();
        }

        return $connection;
    }

    /**
     * Create a DSN string from a configuration.
     *
     * @param array<string, mixed> $config
     */
    protected function getDsn(array $config): string
    {
        $host = isset($config['host']) && is_string($config['host']) ? $config['host'] : 'localhost';
        $port = isset($config['port']) && (is_string($config['port']) || is_int($config['port'])) ? $config['port'] : 5432;
        $database = isset($config['database']) && is_string($config['database']) ? $config['database'] : 'forge';
        
        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        if (isset($config['sslmode']) && is_string($config['sslmode'])) {
            $dsn .= ";sslmode={$config['sslmode']}";
        }

        return $dsn;
    }

    /**
     * Format the schema for the DSN.
     *
     * @param string|array<string>|mixed $schema
     */
    protected function formatSchema(mixed $schema): string
    {
        if (is_array($schema)) {
            $strings = array_map(fn($v) => is_scalar($v) ? (string) $v : '', $schema);
            return '"' . implode('", "', $strings) . '"';
        }
        return '"' . (is_scalar($schema) ? (string) $schema : '') . '"';
    }
}
