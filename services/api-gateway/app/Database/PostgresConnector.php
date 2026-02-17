<?php
declare(strict_types=1);

namespace App\Database;

use Hyperf\Database\Connectors\Connector;
use Hyperf\Database\Connectors\ConnectorInterface;
use PDO;

class PostgresConnector extends Connector implements ConnectorInterface
{
    /**
     * Establish a database connection.
     */
    public function connect(array $config): PDO
    {
        $dsn = $this->getDsn($config);
        $options = $this->getOptions($config);
        $connection = $this->createConnection($dsn, $config, $options);

        if (isset($config['charset'])) {
            $connection->prepare("set names '{$config['charset']}'")->execute();
        }

        if (isset($config['timezone'])) {
            $connection->prepare("set timezone to '{$config['timezone']}'")->execute();
        }

        if (isset($config['schema'])) {
            $schema = $this->formatSchema($config['schema']);
            $connection->prepare("set search_path to {$schema}")->execute();
        }

        if (isset($config['application_name'])) {
            $connection->prepare("set application_name to '{$config['application_name']}'")->execute();
        }

        return $connection;
    }

    /**
     * Create a DSN string from a configuration.
     */
    protected function getDsn(array $config): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5432;
        $database = $config['database'] ?? 'forge';
        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        if (isset($config['sslmode'])) {
            $dsn .= ";sslmode={$config['sslmode']}";
        }

        return $dsn;
    }

    /**
     * Format the schema for the DSN.
     */
    protected function formatSchema(string|array $schema): string
    {
        if (is_array($schema)) {
            return '"' . implode('", "', $schema) . '"';
        }
        return '"' . $schema . '"';
    }
}
