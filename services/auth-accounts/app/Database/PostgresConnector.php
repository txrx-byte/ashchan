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
 *
 * Security considerations:
 *  - Charset validation prevents injection through malformed character set names
 *  - Timezone validation uses regex to allow only safe identifiers
 *  - Schema names are validated as PostgreSQL identifiers before use
 *  - Application name is validated to prevent injection via connection metadata
 *
 * @see https://www.postgresql.org/docs/current/sql-set.html
 *      PostgreSQL SET command documentation
 */
final class PostgresConnector extends Connector implements ConnectorInterface
{
    /**
     * Allowed charsets for PostgreSQL connections.
     *
     * This allowlist prevents SQL injection through malformed charset names.
     * Only PostgreSQL-supported encodings are permitted.
     *
     * @see https://www.postgresql.org/docs/16/multibyte.html
     *      PostgreSQL character set encodings
     *
     * @var string[]
     */
    private const ALLOWED_CHARSETS = [
        'utf8', 'utf-8', 'latin1', 'latin2', 'latin9',
        'sql_ascii', 'win1250', 'win1251', 'win1252',
    ];

    /**
     * Regex pattern for valid PostgreSQL timezone identifiers.
     * Allows region/city format (e.g. "America/New_York") and abbreviations (e.g. "UTC", "EST").
     *
     * Pattern breakdown:
     *   ^            - Start of string
     *   [A-Za-z_\/\+\-0-9]{1,64} - Alphanumeric, underscore, slash, plus, hyphen (1-64 chars)
     *   $            - End of string
     *
     * @var string
     */
    private const TIMEZONE_PATTERN = '/^[A-Za-z_\/\+\-0-9]{1,64}$/';

    /**
     * Regex pattern for valid PostgreSQL identifiers (schema names, application names).
     * Alphanumeric plus underscore/hyphen/dot, 1-63 chars (PostgreSQL NAMEDATALEN - 1).
     *
     * Pattern breakdown:
     *   ^            - Start of string
     *   [A-Za-z_]    - Must start with letter or underscore
     *   [A-Za-z0-9_\-\.]{0,62} - Followed by 0-62 alphanumeric/underscore/hyphen/dot
     *   $            - End of string
     *
     * @see https://www.postgresql.org/docs/current/sql-syntax-lexical.html#SQL-SYNTAX-IDENTIFIERS
     *      PostgreSQL identifier naming rules
     *
     * @var string
     */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_\-\.]{0,62}$/';

    /**
     * Establish a database connection with secure parameter configuration.
     *
     * Creates a PDO connection and configures PostgreSQL session parameters:
     *  1. Character encoding (client charset)
     *  2. Session timezone
     *  3. Search path (schema resolution order)
     *  4. Application name (for pg_stat_activity monitoring)
     *
     * Each configuration step validates input to prevent SQL injection.
     *
     * @param array<string, mixed> $config Connection configuration from databases.php
     *                                     Expected keys: host, port, database, username,
     *                                     password, charset, timezone, schema, application_name
     * @return PDO The configured PDO connection instance
     * @throws \InvalidArgumentException If configuration contains invalid values
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
     * The charset is normalized to lowercase and trimmed before validation
     * to handle common input variations.
     *
     * @param PDO                $connection The PDO connection to configure
     * @param array<string, mixed> $config   Connection configuration array
     * @throws \InvalidArgumentException If charset is not in the allowed list
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
     * PostgreSQL accepts various timezone formats including:
     *  - Region/City (e.g., "America/New_York", "Europe/London")
     *  - Abbreviations (e.g., "UTC", "EST", "PST")
     *  - Offsets (e.g., "+05:30", "-08:00")
     *
     * @param PDO                $connection The PDO connection to configure
     * @param array<string, mixed> $config   Connection configuration array
     * @throws \InvalidArgumentException If timezone format is invalid
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
     * The search_path determines which schemas are searched when resolving
     * unqualified table names. Multiple schemas can be specified in order.
     *
     * Example: search_path = ["public", "app"] results in tables being
     * resolved as "public.table" first, then "app.table".
     *
     * @param PDO                $connection The PDO connection to configure
     * @param array<string, mixed> $config   Connection configuration array
     * @throws \InvalidArgumentException If any schema name is invalid
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
     * The application name appears in PostgreSQL's pg_stat_activity view
     * and can be used for monitoring, debugging, and connection tracking.
     *
     * @param PDO                $connection The PDO connection to configure
     * @param array<string, mixed> $config   Connection configuration array
     * @throws \InvalidArgumentException If application name format is invalid
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
     * Creates a PostgreSQL DSN (Data Source Name) string for PDO connection.
     * Validates all components to prevent injection and ensure valid connection parameters.
     *
     * Validation rules:
     *  - Host: Alphanumeric, dots, hyphens, colons (IPv6), brackets only
     *  - Port: Numeric, 1-65535 range
     *  - Database: Alphanumeric, underscores, hyphens only
     *  - SSL mode: Must be one of PostgreSQL's supported values
     *
     * @param array<string, mixed> $config Connection configuration array
     *                                      Expected: host, port, database, sslmode
     * @return string The PostgreSQL DSN string (e.g., "pgsql:host=localhost;port=5432;dbname=mydb")
     * @throws \InvalidArgumentException If any configuration value is invalid
     */
    protected function getDsn(array $config): string
    {
        // @phpstan-ignore-next-line
        $host = (string) $config['host'];
        // @phpstan-ignore-next-line
        $port = (string) $config['port'];
        // @phpstan-ignore-next-line
        $database = (string) $config['database'];

        // Validate host: letters, digits, dots, hyphens, colons (IPv6), brackets only
        if ($host === '' || !preg_match('/^[\w.\-:\[\]]+$/u', $host)) {
            throw new \InvalidArgumentException('Invalid database host: ' . $host);
        }

        // Validate port: numeric, 1–65535
        $portInt = (int) $port;
        if (!ctype_digit($port) || $portInt < 1 || $portInt > 65535) {
            throw new \InvalidArgumentException('Invalid database port: ' . $port);
        }

        // Validate database name: letters, digits, underscores, hyphens only
        if ($database === '' || !preg_match('/^[\w\-]+$/u', $database)) {
            throw new \InvalidArgumentException('Invalid database name: ' . $database);
        }

        $dsn = "pgsql:host={$host};port={$portInt};dbname={$database}";

        // Validate sslmode against allowed values
        $sslmode = $config['sslmode'] ?? null;
        if (is_string($sslmode)) {
            $allowedSslModes = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
            if (!in_array($sslmode, $allowedSslModes, true)) {
                throw new \InvalidArgumentException('Invalid sslmode: ' . $sslmode);
            }
            $dsn .= ";sslmode={$sslmode}";
        }

        return $dsn;
    }

    /**
     * Format schema names as a comma-separated, double-quoted list for SET search_path.
     *
     * Double-quotes schema names to handle reserved words and special characters.
     * Embedded double-quotes are escaped by doubling them (PostgreSQL standard).
     *
     * @param string[] $schemas Pre-validated schema names
     * @return string Comma-separated, double-quoted schema list (e.g., '"public", "app"')
     */
    private function formatSchema(array $schemas): string
    {
        return implode(', ', array_map(
            static fn(string $s): string => '"' . str_replace('"', '""', $s) . '"',
            $schemas
        ));
    }
}
