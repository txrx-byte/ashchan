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


namespace App\Service;

use Hyperf\Redis\Redis;

/**
 * Lightweight HTTP client for proxying requests to backend services.
 * Supports mTLS (mutual TLS) for secure service-to-service communication.
 */
final class ProxyClient
{
    /** @var array<string, string> Service name → base URL */
    private array $services;

    /** @var string Path to client certificate for outbound mTLS */
    private string $clientCert;

    /** @var string Path to client key for outbound mTLS */
    private string $clientKey;

    /** @var string Path to CA certificate for peer verification */
    private string $caCert;

    public function __construct()
    {
        $this->services = [
            'auth'       => getenv('AUTH_SERVICE_URL')       ?: 'http://auth-accounts:9502',
            'boards'     => getenv('BOARDS_SERVICE_URL')     ?: 'http://boards-threads-posts:9503',
            'media'      => getenv('MEDIA_SERVICE_URL')      ?: 'http://media-uploads:9504',
            'search'     => getenv('SEARCH_SERVICE_URL')     ?: 'http://search-indexing:9505',
            'moderation' => getenv('MODERATION_SERVICE_URL') ?: 'http://moderation-anti-spam:9506',
        ];

        $this->clientCert = getenv('MTLS_CLIENT_CERT_FILE') ?: '/etc/mtls/gateway/gateway.crt';
        $this->clientKey  = getenv('MTLS_CLIENT_KEY_FILE')  ?: '/etc/mtls/gateway/gateway.key';
        $this->caCert     = getenv('MTLS_CA_FILE')          ?: '/etc/mtls/ca/ca.crt';
    }

        /**

         * Forward a request to a backend service.

         *

         * @param string $service  Name of the target service

         * @param string $method   HTTP method

         * @param string $path     Request path

         * @param array<string, mixed>  $headers  Headers to forward

         * @param string|array<string, mixed> $body Request body (string or array for multipart)

         * @return array{status: int, headers: array<string, string>, body: string|false}

         */

        public function forward(string $service, string $method, string $path, array $headers = [], string|array $body = ''): array

        {
        $baseUrl = $this->services[$service] ?? null;
        if (!$baseUrl) {
            return ['status' => 502, 'headers' => [], 'body' => json_encode(['error' => 'Unknown service'])];
        }

        if (empty($method)) {
            return ['status' => 400, 'headers' => [], 'body' => json_encode(['error' => 'HTTP method cannot be empty'])];
        }

        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 502, 'headers' => [], 'body' => json_encode(['error' => 'Failed to initialize cURL'])];
        }

        if (is_array($body)) {
            // When body is an array, cURL handles multipart/form-data.
            // We must NOT set Content-Type manually or it will lack the boundary.
            unset($headers['Content-Type']);
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => $this->formatHeaders($headers),
        ]);

        // Configure mTLS for HTTPS service-to-service calls
        if (str_starts_with($url, 'https://')) {
            if (is_file($this->caCert)) {
                curl_setopt($ch, CURLOPT_CAINFO, $this->caCert);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            }
            if (is_file($this->clientCert) && is_file($this->clientKey)) {
                curl_setopt($ch, CURLOPT_SSLCERT, $this->clientCert);
                curl_setopt($ch, CURLOPT_SSLKEY, $this->clientKey);
            }
        }

        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false) {
            return ['status' => 502, 'headers' => [], 'body' => json_encode(['error' => 'Backend unavailable'])];
        }

        $responseHeaders = substr((string) $response, 0, $headerSize);
        $responseBody    = substr((string) $response, $headerSize);

        return [
            'status'  => $httpCode,
            'headers' => $this->parseHeaders($responseHeaders),
            'body'    => $responseBody,
        ];
    }

    /**
     * @param array<string, mixed> $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            if (is_string($value)) {
                $result[] = "{$name}: {$value}";
            }
        }
        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }
        return $headers;
    }
}
