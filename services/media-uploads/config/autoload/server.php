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

$servers = [
    [
        'name' => 'http',
        'type' => Hyperf\Server\Server::SERVER_HTTP,
        'host' => '0.0.0.0',
        'port' => (int) (getenv('PORT') ?: 9504),
        'sock_type' => SWOOLE_SOCK_TCP,
        'callbacks' => [
            Event::ON_REQUEST => [Hyperf\HttpServer\Server::class, 'onRequest'],
        ],
    ],
];

if (filter_var(getenv('MTLS_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
    $servers[] = [
        'name' => 'mtls',
        'type' => Hyperf\Server\Server::SERVER_HTTP,
        'host' => '0.0.0.0',
        'port' => (int) (getenv('MTLS_PORT') ?: 8443),
        'sock_type' => SWOOLE_SOCK_TCP | SWOOLE_SSL,
        'callbacks' => [
            Event::ON_REQUEST => [Hyperf\HttpServer\Server::class, 'onRequest'],
        ],
        'settings' => [
            'ssl_cert_file' => getenv('MTLS_CERT_FILE'),
            'ssl_key_file' => getenv('MTLS_KEY_FILE'),
            'ssl_verify_peer' => filter_var(getenv('MTLS_VERIFY_PEER'), FILTER_VALIDATE_BOOLEAN),
            'ssl_client_cert_file' => getenv('MTLS_CA_FILE'),
        ],
    ];
}

return [
    'mode' => SWOOLE_PROCESS,
    'servers' => $servers,
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
