<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\ProxyClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Validates auth tokens for protected routes.
 * Injects user info into request attributes.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    /** Routes that require authentication (staff routes). */
    private const PROTECTED_PREFIXES = [
        'POST /api/v1/auth/register',
        'POST /api/v1/auth/ban',
        'POST /api/v1/auth/unban',
        'GET /api/v1/reports',
        'POST /api/v1/reports/',
    ];

    public function __construct(
        private ProxyClient $proxyClient,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $path   = $request->getUri()->getPath();

        // Check if route requires auth
        $requiresAuth = false;
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            [$reqMethod, $reqPath] = explode(' ', $prefix, 2);
            if ($method === $reqMethod && str_starts_with($path, $reqPath)) {
                $requiresAuth = true;
                break;
            }
        }

        if (!$requiresAuth) {
            return $handler->handle($request);
        }

        // Extract token
        $auth = $request->getHeaderLine('Authorization');
        $token = null;
        if (str_starts_with($auth, 'Bearer ')) {
            $token = substr($auth, 7);
        }
        if (!$token) {
            $cookies = $request->getCookieParams();
            $token = $cookies['session_token'] ?? null;
        }

        if (!$token) {
            $response = new \Hyperf\HttpMessage\Server\Response();
            return $response->withStatus(401)
                ->withHeader('Content-Type', 'application/json')
                ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(
                    json_encode(['error' => 'Authentication required'])
                ));
        }

        // Validate with auth service
        $result = $this->proxyClient->forward('auth', 'GET', '/api/v1/auth/validate', [
            'Authorization' => "Bearer {$token}",
        ]);

        if ($result['status'] !== 200) {
            $response = new \Hyperf\HttpMessage\Server\Response();
            return $response->withStatus(401)
                ->withHeader('Content-Type', 'application/json')
                ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(
                    json_encode(['error' => 'Invalid or expired token'])
                ));
        }

        $userData = json_decode($result['body'], true)['user'] ?? [];

        // Inject user info into request
        $request = $request
            ->withAttribute('user_id', $userData['user_id'] ?? null)
            ->withAttribute('username', $userData['username'] ?? null)
            ->withAttribute('role', $userData['role'] ?? null);

        return $handler->handle($request);
    }
}
