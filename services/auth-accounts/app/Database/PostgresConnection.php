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

use Hyperf\Database\Connection;
use Hyperf\Database\Query\Grammars\Grammar as QueryGrammar;
use Hyperf\Database\Query\Processors\Processor;
use Hyperf\Database\Schema\Grammars\Grammar as SchemaGrammar;
use Exception;
use PDOStatement;

/**
 * Custom PostgreSQL connection with proper PDO parameter binding.
 *
 * Extends the base Hyperf connection to provide:
 *  - Correct PDO type mapping (bool → PARAM_BOOL, int → PARAM_INT)
 *  - PostgreSQL-specific unique constraint error detection
 *
 * The correct type mapping is critical for PostgreSQL's strict typing system.
 * Using PARAM_STR for boolean values would cause query failures or unexpected
 * type coercion behavior.
 *
 * @see https://www.postgresql.org/docs/current/libpq-exec.html#LIBPQ-PARAMS
 *      PostgreSQL parameter binding documentation
 */
final class PostgresConnection extends Connection
{
    /**
     * Bind values to their parameters in the given statement.
     *
     * Maps PHP types to the correct PDO parameter types for PostgreSQL.
     * This is critical for boolean and integer columns — using PARAM_STR
     * for booleans would cause query issues with PostgreSQL's strict typing.
     *
     * Type mapping:
     *   - int     → PDO::PARAM_INT  (32-bit signed integer)
     *   - bool    → PDO::PARAM_BOOL (boolean as 0/1)
     *   - null    → PDO::PARAM_NULL (SQL NULL)
     *   - others  → PDO::PARAM_STR  (string representation)
     *
     * @param PDOStatement             $statement  Prepared statement to bind values to
     * @param array<string|int, mixed> $bindings   Array of values to bind (indexed or named)
     */
    public function bindValues(PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $type = match (true) {
                is_int($value) => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_BOOL,
                is_null($value) => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            };
            $statement->bindValue(
                is_string($key) ? $key : $key + 1,
                $value,
                $type
            );
        }
    }

    /**
     * Determine if the given database exception was caused by a unique constraint violation.
     *
     * Matches PostgreSQL error messages for:
     *  - "duplicate key value violates unique constraint"
     *  - "unique_violation" (SQLSTATE 23505)
     *
     * This method is used by the query builder to convert database-specific
     * constraint violations into framework-level exceptions.
     *
     * @param Exception $exception The exception to analyze
     * @return bool True if the exception represents a unique constraint violation
     */
    protected function isUniqueConstraintError(Exception $exception): bool
    {
        return (bool) preg_match('#unique.*violation|duplicate\s+key#i', $exception->getMessage());
    }

    /**
     * Get the default post processor instance.
     *
     * The processor handles transforming database results into PHP arrays.
     * PostgreSQL uses the default processor since it returns results in
     * standard format.
     *
     * @return Processor The query result processor instance
     */
    protected function getDefaultPostProcessor(): Processor
    {
        return new Processor();
    }
}
