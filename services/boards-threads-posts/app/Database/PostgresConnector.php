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

/**
 * Custom PostgreSQL connector with secure parameter handling.
 *
 * Configures charset, timezone, search_path, and application_name on new connections.
 * All SET parameters are validated against allowlists to prevent SQL injection.
 */
final class PostgresConnector extends Connector implements ConnectorInterface
{
    /**
     * Allowed charsets for PostgreSQL connections.
     *
     * @see https://www.postgresql.org/docs/16/multibyte.html
     */
    private const ALLOWED_CHARSETS = [
        'utf8', 'utf-8', 'latin1', 'latin2', 'latin9',
        'sql_ascii', 'win1250', 'win1251', 'win1252',
    ];

    /**
     * Regex pattern for valid PostgreSQL timezone identifiers.
     * Allows region/city format (e.g. "America/New_York") and abbreviations (e.g. "UTC", "EST").
     */
    private const TIMEZONE_PATTERN = '/^[A-Za-z_\/\+\-0-9]{1,64}$/';

    /**
     * Regex pattern for valid PostgreSQL identifiers (schema names, application names).
     * Alphanumeric plus underscore/hyphen/dot, 1-63 chars (PostgreSQL NAMEDATALEN - 1).
     */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_\-\.]{0,62}$/';

    /**
     * Establish a database connection with secure parameter configuration.
     *
     * @param array<string, mixed> $config Connection configuration from databases.php
     * @return PDO The configured PDO connection
     */
    public function connect(array $config): PDO
    {
        $dsn = $this->getDsn($config);
        $options = $this->getOptions($config);
        $connection = $this->createConnection($dsn, $config, $options);

        $this->configureEncoding($connection, $config);
        $this->configureTimezone($connection, $config);
        $this->configureSearchPath($connection, $config);
        $this->configureApplicationName($connection, $config);

        return $connection;
    }

    /**
     * Set the client encoding using a parameterized approach.
     *
     * SECURITY: Validates charset against allowlist to prevent SQL injection.
     * Previous implementation used string interpolation in prepare(), which was
     * vulnerable to injection via malicious charset values.
     *
     * @param array<string, mixed> $config
     */
    private function configureEncoding(PDO $connection, array $config): void
    {
        $charset = $config['charset'] ?? null;
        if (!is_string($charset) || $charset === '') {
            return;
        }

        $normalized = strtolower(trim($charset));
        if (!in_array($normalized, self::ALLOWED_CHARSETS, true)) {
            throw new \InvalidArgumentException(
                "Unsupported charset '{$charset}'. Allowed: " . implode(', ', self::ALLOWED_CHARSETS)
            );
        }

        // Use pg_escape_identifier equivalent: double-quote the validated value
        $connection->exec("SET NAMES '{$normalized}'");
    }

    /**
     * Set the session timezone.
     *
     * SECURITY: Validates timezone against a safe regex pattern.
     *
     * @param array<string, mixed> $config
     */
    private function configureTimezone(PDO $connection, array $config): void
    {
        $timezone = $config['timezone'] ?? null;
        if (!is_string($timezone) || $timezone === '') {
            return;
        }

        if (!preg_match(self::TIMEZONE_PATTERN, $timezone)) {
            throw new \InvalidArgumentException("Invalid timezone identifier: '{$timezone}'");
        }

        $connection->exec("SET timezone TO " . $connection->quote($timezone));
    }

    /**
     * Configure the search_path for schema resolution.
     *
     * SECURITY: Each schema name is validated against IDENTIFIER_PATTERN to
     * prevent injection through crafted schema names.
     *
     * @param array<string, mixed> $config
     */
    private function configureSearchPath(PDO $connection, array $config): void
    {
        $schema = $config['schema'] ?? null;
        if ($schema === null) {
            return;
        }

        /** @var string[] $schemas */
        $schemas = is_array($schema)
            ? array_values(array_filter($schema, 'is_string'))
            : (is_string($schema) ? [$schema] : []);
        if ($schemas === []) {
            return;
        }

        foreach ($schemas as $s) {
            if (!preg_match(self::IDENTIFIER_PATTERN, $s)) {
                throw new \InvalidArgumentException("Invalid schema name: '{$s}'");
            }
        }

        $formattedSchema = $this->formatSchema($schemas);
        $connection->exec("SET search_path TO {$formattedSchema}");
    }

    /**
     * Set the application name for connection identification in pg_stat_activity.
     *
     * SECURITY: Validated against IDENTIFIER_PATTERN to prevent injection.
     *
     * @param array<string, mixed> $config
     */
    private function configureApplicationName(PDO $connection, array $config): void
    {
        $appName = $config['application_name'] ?? null;
        if (!is_string($appName) || $appName === '') {
            return;
        }

        if (!preg_match(self::IDENTIFIER_PATTERN, $appName)) {
            throw new \InvalidArgumentException("Invalid application_name: '{$appName}'");
        }

        $connection->exec("SET application_name TO " . $connection->quote($appName));
    }

    /**
     * Build the DSN string from configuration.
     *
     * @param array<string, mixed> $config
     */
    protected function getDsn(array $config): string
    {
        // @phpstan-ignore-next-line
        $host = (string) $config['host'];
        // @phpstan-ignore-next-line
        $port = (string) $config['port'];
        // @phpstan-ignore-next-line
        $database = (string) $config['database'];
        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        $sslmode = $config['sslmode'] ?? null;
        if (is_string($sslmode)) {
            $dsn .= ";sslmode={$sslmode}";
        }

        return $dsn;
    }

    /**
     * Format schema names as a comma-separated, double-quoted list for SET search_path.
     *
     * @param string[] $schemas Pre-validated schema names
     */
    private function formatSchema(array $schemas): string
    {
        return implode(', ', array_map(
            static fn(string $s): string => '"' . str_replace('"', '""', $s) . '"',
            $schemas
        ));
    }
}
