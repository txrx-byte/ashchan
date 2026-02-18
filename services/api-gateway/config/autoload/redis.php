<?php
declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'default' => [
        'host' => env('REDIS_HOST', 'redis'),
        'auth' => env('REDIS_AUTH', null),
        'port' => is_numeric(env('REDIS_PORT')) ? (int) env('REDIS_PORT') : 6379,
        'db' => is_numeric(env('REDIS_DB')) ? (int) env('REDIS_DB') : 0,
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
