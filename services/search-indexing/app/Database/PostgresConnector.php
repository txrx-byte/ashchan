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
     * @param array<string, mixed> $config
     */
    public function connect(array $config): PDO
    {
        $dsn = $this->getDsn($config);
        $options = $this->getOptions($config);
        $connection = $this->createConnection($dsn, $config, $options);

        if (isset($config['charset']) && is_string($config['charset'])) {
            $connection->prepare("set names '{$config['charset']}'")->execute();
        }

        if (isset($config['timezone']) && is_string($config['timezone'])) {
            $connection->prepare("set timezone to '{$config['timezone']}'")->execute();
        }

        if (isset($config['schema'])) {
            $schema = $config['schema'];
            if (is_string($schema) || is_array($schema)) {
                if (is_array($schema)) {
                    $schema = array_filter($schema, 'is_string');
                }
                /** @var string|array<string> $schema */
                $formattedSchema = $this->formatSchema($schema);
                $connection->prepare("set search_path to {$formattedSchema}")->execute();
            }
        }

        if (isset($config['application_name']) && is_string($config['application_name'])) {
            $connection->prepare("set application_name to '{$config['application_name']}'")->execute();
        }

        return $connection;
    }

    /**
     * Create a DSN string from a configuration.
     * @param array<string, mixed> $config
     */
    protected function getDsn(array $config): string
    {
        // @phpstan-ignore-next-line
        $host = (string) ($config['host'] ?? 'localhost');
        // @phpstan-ignore-next-line
        $port = (string) ($config['port'] ?? 5432);
        // @phpstan-ignore-next-line
        $database = (string) ($config['database'] ?? 'forge');
        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        if (isset($config['sslmode']) && is_string($config['sslmode'])) {
            $dsn .= ";sslmode={$config['sslmode']}";
        }

        return $dsn;
    }

    /**
     * Format the schema for the DSN.
     * @param array<string>|string $schema
     */
    protected function formatSchema(string|array $schema): string
    {
        if (is_array($schema)) {
            return '"' . implode('", "', $schema) . '"';
        }
        return '"' . $schema . '"';
    }
}
