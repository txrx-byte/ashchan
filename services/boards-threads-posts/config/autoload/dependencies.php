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
