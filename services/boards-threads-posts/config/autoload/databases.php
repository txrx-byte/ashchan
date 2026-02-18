<?php
declare(strict_types=1);

use function Hyperf\Support\env;

$port = env('DB_PORT', 5432);
$port = is_numeric($port) ? (int) $port : 5432;

return [
    'default' => [
        'driver' => env('DB_DRIVER', 'pgsql'),
        'host' => env('DB_HOST', 'postgres'),
        'port' => $port,
        'database' => env('DB_DATABASE', 'ashchan'),
        'username' => env('DB_USER', 'ashchan'),
        'password' => env('DB_PASSWORD', 'ashchan'),
        'charset' => env('DB_CHARSET', 'utf8'),
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => 60.0,
        ],
        'commands' => [
            'gen:model' => [
                'path' => 'app/Model',
                'force_casts' => true,
                'inheritance' => 'Model',
            ],
        ],
    ],
];
