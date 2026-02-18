<?php
declare(strict_types=1);

namespace App\Database;

use Hyperf\Database\Connection;
use Hyperf\Database\PgSQL\Query\Grammars\PostgresGrammar as QueryGrammar;
use Hyperf\Database\PgSQL\Query\Processors\PostgresProcessor;
use Hyperf\Database\PgSQL\Schema\Grammars\PostgresGrammar as SchemaGrammar;
use Exception;
use PDOStatement;

class PostgresConnection extends Connection
{
    /**
     * Bind values to their parameters in the given statement.
     *
     * @param array<int|string, mixed> $bindings
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
     */
    protected function isUniqueConstraintError(Exception $exception): bool
    {
        return (bool) preg_match('#unique.*violation|duplicate\s+key#i', $exception->getMessage());
    }

    /**
     * Get the default query grammar instance.
     */
    protected function getDefaultQueryGrammar(): QueryGrammar
    {
        /** @var QueryGrammar $grammar */
        $grammar = $this->withTablePrefix(new QueryGrammar());
        return $grammar;
    }

    /**
     * Get the default schema grammar instance.
     */
    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        /** @var SchemaGrammar $grammar */
        $grammar = $this->withTablePrefix(new SchemaGrammar());
        return $grammar;
    }

    /**
     * Get the default post processor instance.
     */
    protected function getDefaultPostProcessor(): PostgresProcessor
    {
        return new PostgresProcessor();
    }
}
