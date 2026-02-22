<?php
declare(strict_types=1);

namespace App\Tests\Unit\Middleware;

use App\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @covers \App\Middleware\SecurityHeadersMiddleware
 */
final class SecurityHeadersMiddlewareTest extends TestCase
{
    private SecurityHeadersMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new SecurityHeadersMiddleware();
    }

    private function createRequest(string $path): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        return $request;
    }

    private function createHandler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);
        return $handler;
    }

    private function createChainableResponse(): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        return $response;
    }

    public function testAddsXContentTypeOptions(): void
    {
        $response = $this->createChainableResponse();
        $response->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response) {
                if ($name === 'X-Content-Type-Options') {
                    $this->assertSame('nosniff', $value);
                }
                return $response;
            });

        $request = $this->createRequest('/api/v1/boards');
        $handler = $this->createHandler($response);

        $this->middleware->process($request, $handler);
    }

    public function testAddsXFrameOptions(): void
    {
        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $request = $this->createRequest('/');
        $handler = $this->createHandler($response);

        $this->middleware->process($request, $handler);

        $this->assertSame('DENY', $headers['X-Frame-Options']);
    }

    public function testAddsStrictTransportSecurity(): void
    {
        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $request = $this->createRequest('/');
        $handler = $this->createHandler($response);

        $this->middleware->process($request, $handler);

        $this->assertStringContainsString('max-age=', $headers['Strict-Transport-Security']);
        $this->assertStringContainsString('includeSubDomains', $headers['Strict-Transport-Security']);
    }

    public function testAddsReferrerPolicy(): void
    {
        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $request = $this->createRequest('/');
        $handler = $this->createHandler($response);

        $this->middleware->process($request, $handler);

        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
    }

    public function testCspAllowsUnsafeInlineAndBlobWorkers(): void
    {
        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $request = $this->createRequest('/api/v1/boards');
        $handler = $this->createHandler($response);

        $this->middleware->process($request, $handler);

        $csp = $headers['Content-Security-Policy'];
        // All pages need 'unsafe-inline' for inline event handlers (onclick, onsubmit)
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline'", $csp);
        // ALTCHA proof-of-work requires blob: Web Workers
        $this->assertStringContainsString("worker-src 'self' blob:", $csp);
    }

    public function testStaffPageCspMatchesPublicCsp(): void
    {
        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $request = $this->createRequest('/staff/dashboard');
        $handler = $this->createHandler($response);

        $this->middleware->process($request, $handler);

        $csp = $headers['Content-Security-Policy'];
        $this->assertStringContainsString("'unsafe-inline'", $csp);
    }

    public function testCspDeniesFrameAncestors(): void
    {
        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $request = $this->createRequest('/');
        $handler = $this->createHandler($response);

        $this->middleware->process($request, $handler);

        $this->assertStringContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy']);
    }

    public function testAddsPermissionsPolicy(): void
    {
        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $request = $this->createRequest('/');
        $handler = $this->createHandler($response);

        $this->middleware->process($request, $handler);

        $this->assertStringContainsString('camera=()', $headers['Permissions-Policy']);
        $this->assertStringContainsString('microphone=()', $headers['Permissions-Policy']);
        $this->assertStringContainsString('geolocation=()', $headers['Permissions-Policy']);
    }

    public function testAddsCrossOriginHeaders(): void
    {
        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $request = $this->createRequest('/');
        $handler = $this->createHandler($response);

        $this->middleware->process($request, $handler);

        $this->assertSame('same-origin', $headers['Cross-Origin-Opener-Policy']);
        $this->assertSame('same-origin', $headers['Cross-Origin-Resource-Policy']);
    }

    public function testXssProtectionDisabled(): void
    {
        $headers = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use ($response, &$headers) {
                $headers[$name] = $value;
                return $response;
            });

        $request = $this->createRequest('/');
        $handler = $this->createHandler($response);

        $this->middleware->process($request, $handler);

        // X-XSS-Protection should be disabled (set to '0') per modern security best practices
        $this->assertSame('0', $headers['X-XSS-Protection']);
    }
}
