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


namespace App\Controller;

use App\Service\ProxyClient;
use App\Service\SiteConfigService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

use function Hyperf\Support\env;

/**
 * Gateway controller that proxies API requests to the correct backend service.
 *
 * Route mapping:
 *   /api/v1/auth/**     → auth-accounts
 *   /api/v1/boards/**   → boards-threads-posts
 *   /api/v1/media/**    → media-uploads
 *   /api/v1/search/**   → search-indexing
 *   /api/v1/reports/**  → moderation-anti-spam
 *   /api/v1/spam/**     → moderation-anti-spam
 *   /api/v1/captcha/**  → moderation-anti-spam
 *   /api/v1/consent     → auth-accounts
 */
final class GatewayController
{
    private const ROUTE_MAP = [
        'auth'       => ['auth', 'consent'],
        'boards'     => ['boards', 'posts', 'blotter'],
        'media'      => ['media'],
        'search'     => ['search'],
        'moderation' => ['reports', 'spam', 'captcha'],
    ];

    private string $storageEndpoint;
    private string $storageBucket;
    private string $localStoragePath;

    public function __construct(
        private ProxyClient $proxyClient,
        private HttpResponse $response,
        SiteConfigService $config,
    ) {
        $this->storageEndpoint = $config->get('object_storage_endpoint', 'http://localhost:9000');
        $this->storageBucket   = $config->get('object_storage_bucket', 'ashchan');
        $this->localStoragePath = $config->get('local_storage_path', '/workspaces/ashchan/data/media');
    }

    /** Proxy media requests to MinIO, with local disk fallback */
    public function proxyMedia(RequestInterface $request, string $path): ResponseInterface
    {
        // Sanitize path to prevent directory traversal attacks
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0") || preg_match('#[^a-zA-Z0-9._/\-]#', $path)) {
            return $this->response->raw('Invalid path')->withStatus(400);
        }

        $minioUrl  = $this->storageEndpoint;
        $bucket    = $this->storageBucket;
        $accessKey = (string) env('OBJECT_STORAGE_ACCESS_KEY', 'minioadmin');
        $secretKey = (string) env('OBJECT_STORAGE_SECRET_KEY', 'minioadmin');
        
        $url = rtrim($minioUrl, '/') . '/' . $bucket . '/' . $path;
        $date = gmdate('D, d M Y H:i:s T');
        
        // S3v2 signature for GET
        $stringToSign = "GET\n\n\n{$date}\n/{$bucket}/{$path}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => [
                "Date: {$date}",
                "Authorization: AWS {$accessKey}:{$signature}",
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($response !== false && $httpCode >= 200 && $httpCode < 400) {
            $body = substr((string) $response, $headerSize);
            return $this->response->raw($body)
                ->withStatus($httpCode)
                ->withHeader('Content-Type', $contentType ?: 'application/octet-stream')
                ->withHeader('Cache-Control', 'public, max-age=86400');
        }

        // Fallback: try local disk (with realpath validation to prevent traversal)
        $baseDir = $this->localStoragePath;
        $localPath = $baseDir . '/' . $path;
        $realLocal = realpath($localPath);
        $realBase  = realpath($baseDir);
        if ($realLocal && $realBase && str_starts_with($realLocal, $realBase . '/') && is_file($realLocal)) {
            $localPath = $realLocal;
            $ext = pathinfo($localPath, PATHINFO_EXTENSION);
            $mimeTypes = [
                'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png', 'gif' => 'image/gif',
                'webp' => 'image/webp',
            ];
            $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
            return $this->response->raw((string) file_get_contents($localPath))
                ->withStatus(200)
                ->withHeader('Content-Type', $mime)
                ->withHeader('Cache-Control', 'public, max-age=86400');
        }

        return $this->response->raw('File not found')->withStatus(404);
    }

    /** Catch-all proxy for /api/v1/* routes */
    public function proxy(RequestInterface $request, string $path): ResponseInterface
    {
        $service = $this->resolveService($path);
        if (!$service) {
            return $this->response->json(['error' => 'Route not found'])->withStatus(404);
        }

        $method  = $request->getMethod();
        $uri     = '/api/v1/' . $path;
        $query   = $request->getQueryString();
        if ($query) {
            $uri .= '?' . $query;
        }

        // Forward headers
        $headers = [
            'Content-Type'    => $request->getHeaderLine('Content-Type') ?: 'application/json',
            'Authorization'   => $request->getHeaderLine('Authorization'),
            'X-Forwarded-For' => $request->getHeaderLine('X-Forwarded-For') ?: $request->server('remote_addr', ''),
            'X-Request-Id'    => $request->getHeaderLine('X-Request-Id') ?: bin2hex(random_bytes(8)),
            'User-Agent'      => $request->getHeaderLine('User-Agent'),
        ];

        // Forward staff level from authenticated session (set by StaffAuthMiddleware)
        $staffInfo = \Hyperf\Context\Context::get('staff_info', []);
        if (!empty($staffInfo['level'])) {
            $headers['X-Staff-Level'] = (string) $staffInfo['level'];
        }

        // Forward body
        $body = $request->getBody()->getContents();

        $result = $this->proxyClient->forward($service, $method, $uri, $headers, $body);

        // Build response
        $resp = $this->response->raw($result['body']);
        $resp = $resp->withStatus($result['status']);

        // Forward relevant response headers
        $forwardHeaders = ['Content-Type', 'X-Captcha-Token', 'X-RateLimit-Limit',
                           'X-RateLimit-Remaining', 'X-RateLimit-Reset'];
        foreach ($forwardHeaders as $h) {
            if (isset($result['headers'][$h])) {
                $resp = $resp->withHeader($h, (string) $result['headers'][$h]);
            }
        }

        return $resp;
    }

    /** Resolve which backend service handles a given path segment. */
    private function resolveService(string $path): ?string
    {
        $segment = explode('/', $path)[0];
        foreach (self::ROUTE_MAP as $service => $prefixes) {
            if (in_array($segment, $prefixes, true)) {
                return $service;
            }
        }
        return null;
    }
}
