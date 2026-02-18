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
        'boards'     => ['boards', 'posts'],
        'media'      => ['media'],
        'search'     => ['search'],
        'moderation' => ['reports', 'spam', 'captcha'],
    ];

    public function __construct(
        private ProxyClient $proxyClient,
        private HttpResponse $response,
    ) {}

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
