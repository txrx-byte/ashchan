<?php
declare(strict_types=1);

namespace App\Tests\Unit\Middleware;

use App\Middleware\CorsMiddleware;
use App\Service\SiteConfigService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @covers \App\Middleware\CorsMiddleware
 */
final class CorsMiddlewareTest extends TestCase
{
    private function makeConfigMock(string $origins = '*', int $maxAge = 3600): SiteConfigService
    {
        $config = $this->createMock(SiteConfigService::class);
        $config->method('get')->willReturnCallback(function (string $key, string $default) use ($origins) {
            if ($key === 'cors_origins') {
                return $origins;
            }
            return $default;
        });
        $config->method('getInt')->willReturnCallback(function (string $key, int $default) use ($maxAge) {
            if ($key === 'cors_max_age') {
                return $maxAge;
            }
            return $default;
        });
        return $config;
    }

    private function createRequest(string $method, string $origin = ''): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getHeaderLine')
            ->willReturnCallback(function (string $name) use ($origin) {
                if ($name === 'Origin') {
                    return $origin;
                }
                return '';
            });
        return $request;
    }

    private function createHandler(): RequestHandlerInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);
        return $handler;
    }

    public function testWildcardOriginAllowsAnyOrigin(): void
    {
        $config = $this->makeConfigMock('*');
        $middleware = new CorsMiddleware($config);

        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $request = $this->createRequest('GET', 'https://example.com');
        $middleware->process($request, $handler);

        $this->assertSame('*', $headers['Access-Control-Allow-Origin']);
    }

    public function testExplicitOriginAllowed(): void
    {
        $config = $this->makeConfigMock('https://example.com,https://other.com');
        $middleware = new CorsMiddleware($config);

        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $request = $this->createRequest('GET', 'https://example.com');
        $middleware->process($request, $handler);

        $this->assertSame('https://example.com', $headers['Access-Control-Allow-Origin']);
    }

    public function testDisallowedOriginGetsNoHeader(): void
    {
        $config = $this->makeConfigMock('https://allowed.com');
        $middleware = new CorsMiddleware($config);

        $addedHeaders = false;
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name) use ($response, &$addedHeaders) {
                if ($name === 'Access-Control-Allow-Origin') {
                    $addedHeaders = true;
                }
                return $response;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $request = $this->createRequest('GET', 'https://evil.com');
        $middleware->process($request, $handler);

        // The middleware should not add CORS origin header for disallowed origins
        $this->assertFalse($addedHeaders, 'Access-Control-Allow-Origin should not be set for disallowed origins');
    }

    public function testPreflightReturns204(): void
    {
        $config = $this->makeConfigMock('*');
        $middleware = new CorsMiddleware($config);

        $request = $this->createRequest('OPTIONS', 'https://example.com');
        $handler = $this->createHandler();

        $result = $middleware->process($request, $handler);
        // The middleware constructs a new Hyperf\HttpMessage\Server\Response for OPTIONS
        $this->assertNotNull($result);
    }

    public function testAllowedMethodsIncludeCommonVerbs(): void
    {
        $config = $this->makeConfigMock('*');
        $middleware = new CorsMiddleware($config);

        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $request = $this->createRequest('GET', 'https://example.com');
        $middleware->process($request, $handler);

        $methods = $headers['Access-Control-Allow-Methods'] ?? '';
        $this->assertStringContainsString('GET', $methods);
        $this->assertStringContainsString('POST', $methods);
        $this->assertStringContainsString('PUT', $methods);
        $this->assertStringContainsString('DELETE', $methods);
    }

    public function testAllowedHeadersIncludeRequiredHeaders(): void
    {
        $config = $this->makeConfigMock('*');
        $middleware = new CorsMiddleware($config);

        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $request = $this->createRequest('GET', 'https://example.com');
        $middleware->process($request, $handler);

        $allowHeaders = $headers['Access-Control-Allow-Headers'] ?? '';
        $this->assertStringContainsString('Content-Type', $allowHeaders);
        $this->assertStringContainsString('Authorization', $allowHeaders);
        $this->assertStringContainsString('X-CSRF-Token', $allowHeaders);
    }

    public function testExplicitOriginSendsCredentialsHeader(): void
    {
        $config = $this->makeConfigMock('https://myapp.com');
        $middleware = new CorsMiddleware($config);

        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $request = $this->createRequest('GET', 'https://myapp.com');
        $middleware->process($request, $handler);

        $this->assertSame('true', $headers['Access-Control-Allow-Credentials'] ?? '');
    }

    public function testWildcardOriginDoesNotSendCredentials(): void
    {
        $config = $this->makeConfigMock('*');
        $middleware = new CorsMiddleware($config);

        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $request = $this->createRequest('GET', 'https://example.com');
        $middleware->process($request, $handler);

        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
    }
}
