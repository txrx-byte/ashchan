<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ProxyClient;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

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

    public function __construct(
        private ProxyClient $proxyClient,
        private HttpResponse $response,
    ) {}

    /** Proxy media requests directly to MinIO */
    public function proxyMedia(RequestInterface $request, string $path): ResponseInterface
    {
        $minioUrl  = getenv('OBJECT_STORAGE_ENDPOINT') ?: 'http://minio:9000';
        $bucket    = getenv('OBJECT_STORAGE_BUCKET')   ?: 'ashchan';
        $accessKey = getenv('OBJECT_STORAGE_ACCESS_KEY') ?: 'ashchan';
        $secretKey = getenv('OBJECT_STORAGE_SECRET_KEY') ?: 'ashchan123';
        
        $url = rtrim($minioUrl, '/') . '/' . $bucket . '/' . $path;
        $date = gmdate('D, d M Y H:i:s T');
        
        // S3v2 signature for GET
        $stringToSign = "GET\n\n\n{$date}\n/{$bucket}/{$path}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 30,
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

        if ($response === false || $httpCode >= 400) {
            return $this->response->raw('File not found')->withStatus(404);
        }

        $body = substr((string) $response, $headerSize);

        return $this->response->raw($body)
            ->withStatus($httpCode)
            ->withHeader('Content-Type', $contentType ?: 'application/octet-stream')
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }

    /** Catch-all proxy for /api/v1/* routes */
    public function proxy(RequestInterface $request, string $path): ResponseInterface
    {
        $service = $this->resolveService($path);
        if (!$service) {
            return $this->response->json(['error' => 'Route not found']);
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
