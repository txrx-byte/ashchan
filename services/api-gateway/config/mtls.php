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


use App\Middleware\MtlsMiddleware;

return [
    // mTLS Configuration for ServiceMesh
    'mtls' => [
        // Enable mTLS validation
        'enabled' => env('MTLS_ENABLED', false),

        // Certificate Authority file for verifying client certificates
        'ca_file' => env('MTLS_CA_FILE', '/etc/mtls/ca/ca.crt'),

        // Server certificate file
        'cert_file' => env('MTLS_CERT_FILE', '/etc/mtls/gateway/gateway.crt'),

        // Server private key file
        'key_file' => env('MTLS_KEY_FILE', '/etc/mtls/gateway/gateway.key'),

        // Require client certificate verification
        'verify_peer' => env('MTLS_VERIFY_PEER', true),

        // Minimum TLS version allowed
        'min_tls_version' => env('MTLS_MIN_TLS_VERSION', 'TLSv1.3'),

        // Client certificate for outbound requests
        'client_cert_file' => env('MTLS_CLIENT_CERT_FILE', '/etc/mtls/gateway/gateway.crt'),
        'client_key_file' => env('MTLS_CLIENT_KEY_FILE', '/etc/mtls/gateway/gateway.key'),
    ],

    // Middleware configuration
    'middleware' => [
        // Global middleware (applied to all routes)
        'global' => [
            // MtlsMiddleware::class, // Enable for mTLS enforcement
        ],

        // Route-specific middleware groups
        'mtls' => [
            MtlsMiddleware::class,
        ],
    ],

    // HTTP Client configuration for outbound mTLS requests
    'http_client' => [
        'default_options' => [
            // Verify server certificate against CA
            'verify' => env('MTLS_CA_FILE', '/etc/mtls/ca/ca.crt'),

            // Client certificate for mutual authentication
            'cert' => env('MTLS_CLIENT_CERT_FILE', '/etc/mtls/gateway/gateway.crt'),
            'key' => env('MTLS_CLIENT_KEY_FILE', '/etc/mtls/gateway/gateway.key'),
        ],
    ],

    // Server configuration for mTLS port
    'server' => [
        'mtls' => [
            'type' => Hyperf\Server\Server::SERVER_HTTP,
            'host' => '0.0.0.0',
            'port' => (int) env('MTLS_PORT', 8443),
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                Hyperf\Server\Event::ON_REQUEST => [App\Controller\IndexController::class, 'index'],
            ],
            'options' => [
                // Enable SSL/TLS
                'open_ssl' => true,

                // Server certificate and key
                'ssl_cert_file' => env('MTLS_CERT_FILE', '/etc/mtls/gateway/gateway.crt'),
                'ssl_key_file' => env('MTLS_KEY_FILE', '/etc/mtls/gateway/gateway.key'),

                // Client verification (mTLS)
                'ssl_verify_peer' => env('MTLS_VERIFY_PEER', true),
                'ssl_verify_depth' => 3,
                'ssl_ca_file' => env('MTLS_CA_FILE', '/etc/mtls/ca/ca.crt'),

                // TLS version restrictions
                'ssl_protocols' => TLSv1_3_METHOD | TLSv1_2_METHOD,

                // Cipher suites (strong ciphers only)
                'ssl_ciphers' => implode(':', [
                    'TLS_AES_256_GCM_SHA384',
                    'TLS_CHACHA20_POLY1305_SHA256',
                    'TLS_AES_128_GCM_SHA256',
                    'ECDHE-RSA-AES256-GCM-SHA384',
                    'ECDHE-RSA-AES128-GCM-SHA256',
                ]),

                // Prefer server ciphers
                'ssl_prefer_server_ciphers' => true,

                // Session settings
                'ssl_session_cache' => true,
                'ssl_session_timeout' => 300,
            ],
        ],
    ],
];
