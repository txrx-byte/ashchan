<?php
declare(strict_types=1);

use App\Database\PostgresConnector;

// Register the pgsql connection resolver at load time
\Hyperf\Database\Connection::resolverFor('pgsql', function ($connection, string $database, string $prefix, array $config) {
    if (!$connection instanceof \PDO && !$connection instanceof \Closure) {
        throw new \RuntimeException('Invalid connection type');
    }
    return new \App\Database\PostgresConnection($connection, $database, $prefix, $config);
});

return [
    'db.connector.pgsql' => PostgresConnector::class,
];
