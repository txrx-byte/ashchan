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
            $parts = explode(' ', $prefix, 2);
            if (count($parts) === 2) {
                [$reqMethod, $reqPath] = $parts;
                if ($method === $reqMethod && str_starts_with($path, $reqPath)) {
                    $requiresAuth = true;
                    break;
                }
            }
        }

        // Extract token
        $auth = $request->getHeaderLine('Authorization');
        $token = null;
        if (str_starts_with($auth, 'Bearer ')) {
            $token = substr($auth, 7);
        } else {
            $cookies = $request->getCookieParams();
            $token = $cookies['session_token'] ?? null;
        }

        // If no token and not required, pass
        if (!$token && !$requiresAuth) {
            return $handler->handle($request);
        }

        // If no token, it must be required at this point
        if (!$token) {
            $response = new \Hyperf\HttpMessage\Server\Response();
            $json = json_encode(['error' => 'Authentication required']);
            return $response->withStatus(401)
                ->withHeader('Content-Type', 'application/json')
                ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream($json ?: ''));
        }

        // Validate with auth service
        $tokenStr = is_string($token) ? $token : '';
        try {
            $result = $this->proxyClient->forward('auth', 'GET', '/api/v1/auth/validate', [
                'Authorization' => "Bearer {$tokenStr}",
            ]);

            if ($result['status'] === 200) {
                $body = $result['body'];
                $decoded = is_string($body) ? json_decode($body, true) : [];
                $userData = is_array($decoded) && isset($decoded['user']) && is_array($decoded['user']) ? $decoded['user'] : [];

                // Inject user info into request
                $request = $request
                    ->withAttribute('user_id', $userData['user_id'] ?? null)
                    ->withAttribute('username', $userData['username'] ?? null)
                    ->withAttribute('role', $userData['role'] ?? null);
            } elseif ($requiresAuth) {
                // Failed and required
                $response = new \Hyperf\HttpMessage\Server\Response();
                $json = json_encode(['error' => 'Invalid or expired token']);
                return $response->withStatus(401)
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream($json ?: ''));
            }
        } catch (\Throwable $e) {
             if ($requiresAuth) {
                $response = new \Hyperf\HttpMessage\Server\Response();
                return $response->withStatus(500)->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream('Auth service error'));
             }
        }

        return $handler->handle($request);
    }
}
