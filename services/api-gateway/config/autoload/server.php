<?php
declare(strict_types=1);

use Hyperf\Server\Event;

return [
    'servers' => [
        [
            'name' => 'http',
            'type' => Hyperf\Server\Server::SERVER_HTTP,
            'host' => '0.0.0.0',
            'port' => (int) (getenv('PORT') ?: 9501),
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                Event::ON_REQUEST => [Hyperf\HttpServer\Server::class, 'onRequest'],
            ],
        ],
    ],
];
