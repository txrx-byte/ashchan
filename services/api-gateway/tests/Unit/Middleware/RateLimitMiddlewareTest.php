<?php
declare(strict_types=1);

namespace App\Tests\Unit\Middleware;

use App\Middleware\RateLimitMiddleware;
use App\Service\SiteConfigService;
use App\Tests\Stub\RedisStub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @covers \App\Middleware\RateLimitMiddleware
 */
final class RateLimitMiddlewareTest extends TestCase
{
    private RedisStub $redis;
    private RateLimitMiddleware $middleware;

    protected function setUp(): void
    {
        $this->redis = new RedisStub();
        $config = $this->createMock(SiteConfigService::class);
        $config->method('getInt')->willReturnCallback(function (string $key, int $default) {
            return $default;
        });

        $this->middleware = new RateLimitMiddleware($this->redis, $config);
    }

    private function createRequest(string $path, string $ip = '127.0.0.1'): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getServerParams')->willReturn(['remote_addr' => $ip]);

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

    /* ──────────────────────────────────────
     * Health check bypass
     * ────────────────────────────────────── */

    public function testHealthCheckBypassesRateLimit(): void
    {
        $request = $this->createRequest('/health');
        $handler = $this->createHandler();

        // Should pass through without touching Redis
        $result = $this->middleware->process($request, $handler);
        $this->assertInstanceOf(ResponseInterface::class, $result);
    }

    /* ──────────────────────────────────────
     * Rate limiting
     * ────────────────────────────────────── */

    public function testAllowedRequestPassesThrough(): void
    {
        // Default eval handler returns [0, 1] (allowed) — no override needed
        $request = $this->createRequest('/api/v1/boards', '10.0.0.1');
        $handler = $this->createHandler();

        $result = $this->middleware->process($request, $handler);
        $this->assertInstanceOf(ResponseInterface::class, $result);
    }

    public function testBlockedRequestReturns429(): void
    {
        $this->redis->setEvalHandler(function (): array {
            return [120, 0]; // count=120, allowed=0
        });

        $request = $this->createRequest('/api/v1/boards', '10.0.0.1');
        $handler = $this->createHandler();

        $result = $this->middleware->process($request, $handler);
        $this->assertInstanceOf(ResponseInterface::class, $result);
    }

    /* ──────────────────────────────────────
     * Redis failure → fail open
     * ────────────────────────────────────── */

    public function testRedisExceptionFailsOpen(): void
    {
        $this->redis->setEvalThrows(new \RuntimeException('Redis down'));

        $request = $this->createRequest('/api/v1/boards', '10.0.0.1');
        $handler = $this->createHandler();

        $result = $this->middleware->process($request, $handler);
        $this->assertInstanceOf(ResponseInterface::class, $result);
    }

    public function testUnexpectedRedisResponseFailsOpen(): void
    {
        $this->redis->setEvalHandler(fn () => false);

        $request = $this->createRequest('/api/v1/boards', '10.0.0.1');
        $handler = $this->createHandler();

        $result = $this->middleware->process($request, $handler);
        $this->assertInstanceOf(ResponseInterface::class, $result);
    }

    /* ──────────────────────────────────────
     * Rate limit headers
     * ────────────────────────────────────── */

    public function testRateLimitHeadersAdded(): void
    {
        $this->redis->setEvalHandler(fn () => [10, 1]);

        $request = $this->createRequest('/api/v1/boards', '10.0.0.1');

        $addedHeaders = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$addedHeaders) {
                $addedHeaders[$name] = $value;
                return $response;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $this->middleware->process($request, $handler);

        $this->assertArrayHasKey('X-RateLimit-Limit', $addedHeaders);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $addedHeaders);
        $this->assertArrayHasKey('X-RateLimit-Reset', $addedHeaders);
    }

    /* ──────────────────────────────────────
     * Client IP extraction
     * ────────────────────────────────────── */

    public function testUsesXRealIpHeader(): void
    {
        $capturedArgs = null;
        $this->redis->setEvalHandler(function (string $script, array $args) use (&$capturedArgs) {
            $capturedArgs = $args;
            return [0, 1];
        });

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/api/v1/boards');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')
            ->willReturnCallback(function (string $name) {
                if ($name === 'X-Real-IP') {
                    return '203.0.113.50';
                }
                return '';
            });
        $request->method('getServerParams')->willReturn(['remote_addr' => '127.0.0.1']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $this->middleware->process($request, $handler);

        $this->assertNotNull($capturedArgs, 'Eval should have been called');
        $expectedKeyPrefix = 'ratelimit:gateway:' . hash('sha256', '203.0.113.50');
        $this->assertSame($expectedKeyPrefix, $capturedArgs[0]);
    }
}
