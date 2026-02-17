<?php
declare(strict_types=1);

use Hyperf\Server\Event;

return [
    'mode' => SWOOLE_PROCESS,
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
    'settings' => [
        'enable_coroutine' => true,
        'hook_flags' => SWOOLE_HOOK_ALL,
        'worker_num' => (int) (getenv('WORKER_NUM') ?: 2),
        'pid_file' => BASE_PATH . '/runtime/hyperf.pid',
        'open_tcp_nodelay' => true,
        'max_coroutine' => 100000,
        'open_http2_protocol' => true,
        'max_request' => 100000,
        'socket_buffer_size' => 2 * 1024 * 1024,
        'buffer_output_size' => 2 * 1024 * 1024,
    ],
];
