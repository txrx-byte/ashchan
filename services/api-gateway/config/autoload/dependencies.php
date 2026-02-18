<?php
declare(strict_types=1);

use App\Database\PostgresConnector;
use App\Database\PostgresConnection;

// Register the pgsql connection resolver at load time
\Hyperf\Database\Connection::resolverFor('pgsql', function ($connection, string $database, string $prefix, array $config) {
    if ($connection instanceof \Closure) {
        $connection = $connection();
    }
    if (!$connection instanceof \PDO) {
        throw new \InvalidArgumentException('Invalid PDO connection.');
    }
    return new PostgresConnection($connection, $database, $prefix, $config);
});

return [
    'db.connector.pgsql' => PostgresConnector::class,
];
