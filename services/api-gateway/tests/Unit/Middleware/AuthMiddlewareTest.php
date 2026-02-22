<?php
declare(strict_types=1);

namespace App\Tests\Unit\Middleware;

use App\Middleware\AuthMiddleware;
use App\Service\ProxyClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @covers \App\Middleware\AuthMiddleware
 */
final class AuthMiddlewareTest extends TestCase
{
    private ProxyClient $proxyClient;
    private AuthMiddleware $middleware;

    protected function setUp(): void
    {
        $this->proxyClient = $this->createMock(ProxyClient::class);
        $this->middleware = new AuthMiddleware($this->proxyClient);
    }

    private function createRequest(
        string $method,
        string $path,
        ?string $bearerToken = null,
        ?string $cookieToken = null,
    ): ServerRequestInterface {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);

        $request->method('getHeaderLine')
            ->willReturnCallback(function (string $name) use ($bearerToken) {
                if ($name === 'Authorization' && $bearerToken !== null) {
                    return "Bearer {$bearerToken}";
                }
                return '';
            });

        $cookies = [];
        if ($cookieToken !== null) {
            $cookies['session_token'] = $cookieToken;
        }
        $request->method('getCookieParams')->willReturn($cookies);
        $request->method('withAttribute')->willReturnSelf();

        return $request;
    }

    private function createHandler(): RequestHandlerInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);
        return $handler;
    }

    /* ──────────────────────────────────────
     * Public routes (no auth required)
     * ────────────────────────────────────── */

    public function testPublicRoutePassesWithoutToken(): void
    {
        $request = $this->createRequest('GET', '/api/v1/boards');
        $handler = $this->createHandler();

        // No proxyClient call expected
        $this->proxyClient->expects($this->never())->method('forward');

        $result = $this->middleware->process($request, $handler);
        $this->assertInstanceOf(ResponseInterface::class, $result);
    }

    public function testPublicRoutePassesWithValidToken(): void
    {
        $request = $this->createRequest('GET', '/api/v1/boards', 'valid-token');
        $handler = $this->createHandler();

        $this->proxyClient->method('forward')
            ->willReturn([
                'status' => 200,
                'headers' => [],
                'body' => json_encode(['user' => ['user_id' => 1, 'username' => 'test', 'role' => 'admin']]),
            ]);

        $result = $this->middleware->process($request, $handler);
        $this->assertInstanceOf(ResponseInterface::class, $result);
    }

    /* ──────────────────────────────────────
     * Protected routes
     * ────────────────────────────────────── */

    public function testProtectedRouteReturns401WithoutToken(): void
    {
        $request = $this->createRequest('GET', '/api/v1/reports');
        $handler = $this->createHandler();

        $result = $this->middleware->process($request, $handler);
        // Returns a 401 response (Hyperf\HttpMessage\Server\Response)
        $this->assertInstanceOf(ResponseInterface::class, $result);
    }

    public function testProtectedRouteReturns401WithInvalidToken(): void
    {
        $request = $this->createRequest('GET', '/api/v1/reports', 'bad-token');
        $handler = $this->createHandler();

        $this->proxyClient->method('forward')
            ->willReturn([
                'status' => 401,
                'headers' => [],
                'body' => '{"error": "invalid"}',
            ]);

        $result = $this->middleware->process($request, $handler);
        $this->assertInstanceOf(ResponseInterface::class, $result);
    }

    public function testProtectedRoutePassesWithValidBearerToken(): void
    {
        $request = $this->createRequest('POST', '/api/v1/auth/register', 'valid-token');
        $handler = $this->createHandler();

        $this->proxyClient->expects($this->once())
            ->method('forward')
            ->with('auth', 'GET', '/api/v1/auth/validate', $this->anything())
            ->willReturn([
                'status' => 200,
                'headers' => [],
                'body' => json_encode(['user' => ['user_id' => 1, 'username' => 'admin', 'role' => 'admin']]),
            ]);

        $result = $this->middleware->process($request, $handler);
        $this->assertInstanceOf(ResponseInterface::class, $result);
    }

    public function testProtectedRoutePassesWithCookieToken(): void
    {
        $request = $this->createRequest('GET', '/api/v1/reports', null, 'session-cookie-token');
        $handler = $this->createHandler();

        $this->proxyClient->expects($this->once())
            ->method('forward')
            ->willReturn([
                'status' => 200,
                'headers' => [],
                'body' => json_encode(['user' => ['user_id' => 1, 'username' => 'mod', 'role' => 'mod']]),
            ]);

        $result = $this->middleware->process($request, $handler);
        $this->assertInstanceOf(ResponseInterface::class, $result);
    }

    /* ──────────────────────────────────────
     * Auth service error
     * ────────────────────────────────────── */

    public function testProtectedRouteReturns500WhenAuthServiceFails(): void
    {
        $request = $this->createRequest('GET', '/api/v1/reports', 'token');
        $handler = $this->createHandler();

        $this->proxyClient->method('forward')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $result = $this->middleware->process($request, $handler);
        $this->assertInstanceOf(ResponseInterface::class, $result);
    }

    public function testPublicRouteUnaffectedByAuthServiceError(): void
    {
        $request = $this->createRequest('GET', '/api/v1/boards', 'token');
        $handler = $this->createHandler();

        $this->proxyClient->method('forward')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $result = $this->middleware->process($request, $handler);
        $this->assertInstanceOf(ResponseInterface::class, $result);
    }
}
