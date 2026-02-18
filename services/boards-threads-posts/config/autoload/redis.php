<?php
declare(strict_types=1);

use function Hyperf\Support\env;

$port = env('REDIS_PORT', 6379);
$port = is_numeric($port) ? (int) $port : 6379;
$db = env('REDIS_DB', 0);
$db = is_numeric($db) ? (int) $db : 0;

return [
    'default' => [
        'host' => env('REDIS_HOST', 'redis'),
        'auth' => env('REDIS_AUTH', null),
        'port' => $port,
        'db' => $db,
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => 60.0,
        ],
    ],
];
