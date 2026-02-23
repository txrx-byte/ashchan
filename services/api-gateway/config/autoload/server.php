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


use Hyperf\Server\Event;

return [
    'mode' => SWOOLE_PROCESS,
    // Server-level callbacks — registered on the main Swoole server, not on ports.
    // ON_PIPE_MESSAGE is required for cross-worker WebSocket broadcasting.
    // @see docs/LIVEPOSTING.md §11.4
    'callbacks' => [
        Event::ON_PIPE_MESSAGE => [App\WebSocket\WebSocketController::class, 'onPipeMessage'],
    ],
    'servers' => [
        [
            'name' => 'http',
            // SERVER_WEBSOCKET: Swoole handles both HTTP and WebSocket on the same port.
            // Regular HTTP requests still route through Hyperf's onRequest handler.
            // WebSocket upgrades to /api/socket are handled by WebSocketController.
            // @see docs/LIVEPOSTING.md §5.1
            'type' => Hyperf\Server\Server::SERVER_WEBSOCKET,
            'host' => '0.0.0.0',
            'port' => (int) (getenv('PORT') ?: 9501),
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                Event::ON_REQUEST    => [Hyperf\HttpServer\Server::class, 'onRequest'],
                Event::ON_HAND_SHAKE => [App\WebSocket\WebSocketController::class, 'onHandShake'],
                Event::ON_MESSAGE    => [App\WebSocket\WebSocketController::class, 'onMessage'],
                Event::ON_CLOSE      => [App\WebSocket\WebSocketController::class, 'onClose'],
            ],
        ],
        [
            'name' => 'mtls',
            'type' => Hyperf\Server\Server::SERVER_HTTP,
            'host' => '0.0.0.0',
            'port' => (int) (getenv('MTLS_PORT') ?: 8443),
            'sock_type' => SWOOLE_SOCK_TCP | SWOOLE_SSL,
            'callbacks' => [
                Event::ON_REQUEST => [Hyperf\HttpServer\Server::class, 'onRequest'],
            ],
            'settings' => [
                'ssl_cert_file' => getenv('MTLS_CERT_FILE') ?: (defined('BASE_PATH') ? dirname(BASE_PATH, 2) : __DIR__ . '/../../../..') . '/certs/services/gateway/gateway.crt',
                'ssl_key_file' => getenv('MTLS_KEY_FILE') ?: (defined('BASE_PATH') ? dirname(BASE_PATH, 2) : __DIR__ . '/../../../..') . '/certs/services/gateway/gateway.key',
                'ssl_verify_peer' => filter_var(getenv('MTLS_VERIFY_PEER') ?: 'true', FILTER_VALIDATE_BOOLEAN),
                'ssl_client_cert_file' => getenv('MTLS_CA_FILE') ?: (defined('BASE_PATH') ? dirname(BASE_PATH, 2) : __DIR__ . '/../../../..') . '/certs/ca/ca.crt',
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
        'max_request' => (int) (getenv('MAX_REQUEST') ?: 500000),
        'socket_buffer_size' => 2 * 1024 * 1024,
        'buffer_output_size' => 2 * 1024 * 1024,

        // --- WebSocket / Liveposting settings ---
        // @see docs/LIVEPOSTING.md §11.3
        'websocket_subprotocol'       => 'ashchan-v1',
        'open_websocket_ping_frame'   => true,
        'open_websocket_pong_frame'   => true,
        'open_websocket_close_frame'  => true,
        'websocket_compression'       => true,           // permessage-deflate
        'max_connection'              => (int) (getenv('MAX_CONNECTION') ?: 100000),
        'heartbeat_check_interval'    => 45,             // Ping every 45s (< CF 100s idle timeout)
        'heartbeat_idle_time'         => 300,            // Disconnect after 5min idle
    ],
];
