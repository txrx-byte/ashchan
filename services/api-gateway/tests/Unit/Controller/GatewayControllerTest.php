<?php
declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\GatewayController;
use App\Service\ProxyClient;
use App\Service\SiteConfigService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @covers \App\Controller\GatewayController
 */
final class GatewayControllerTest extends TestCase
{
    private ProxyClient $proxyClient;
    private HttpResponse $httpResponse;
    private SiteConfigService $config;
    private GatewayController $controller;

    protected function setUp(): void
    {
        $this->proxyClient = $this->createMock(ProxyClient::class);
        $this->httpResponse = $this->createMock(HttpResponse::class);
        $this->config = $this->createMock(SiteConfigService::class);
        $this->config->method('get')->willReturnCallback(function (string $key, string $default) {
            return $default;
        });

        $this->controller = new GatewayController(
            $this->proxyClient,
            $this->httpResponse,
            $this->config,
        );
    }

    /* ──────────────────────────────────────
     * resolveService() via proxy()
     * ────────────────────────────────────── */

    public function testProxyResolvesAuthRoute(): void
    {
        $this->assertProxiedTo('auth', 'auth/login');
    }

    public function testProxyResolvesConsentRoute(): void
    {
        $this->assertProxiedTo('auth', 'consent');
    }

    public function testProxyResolvesBoardsRoute(): void
    {
        $this->assertProxiedTo('boards', 'boards/a/threads');
    }

    public function testProxyResolvesPostsRoute(): void
    {
        $this->assertProxiedTo('boards', 'posts/123');
    }

    public function testProxyResolvesBlotterRoute(): void
    {
        $this->assertProxiedTo('boards', 'blotter');
    }

    public function testProxyResolvesMediaRoute(): void
    {
        $this->assertProxiedTo('media', 'media/upload');
    }

    public function testProxyResolvesSearchRoute(): void
    {
        $this->assertProxiedTo('search', 'search');
    }

    public function testProxyResolvesReportsRoute(): void
    {
        $this->assertProxiedTo('moderation', 'reports');
    }

    public function testProxyResolvesSpamRoute(): void
    {
        $this->assertProxiedTo('moderation', 'spam/check');
    }

    public function testProxyResolvesCaptchaRoute(): void
    {
        $this->assertProxiedTo('moderation', 'captcha');
    }

    public function testProxyReturns404ForUnknownRoute(): void
    {
        $request = $this->createProxyRequest('GET', 'unknown/endpoint');

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('withStatus')->willReturnSelf();

        $this->httpResponse->expects($this->once())
            ->method('json')
            ->with(['error' => 'Route not found'])
            ->willReturn($mockResponse);

        $this->controller->proxy($request, 'unknown/endpoint');
    }

    /* ──────────────────────────────────────
     * proxy() – forwarding behavior
     * ────────────────────────────────────── */

    public function testProxyForwardsHeaders(): void
    {
        $request = $this->createProxyRequest('POST', 'boards/create');

        $this->proxyClient->expects($this->once())
            ->method('forward')
            ->with(
                'boards',
                'POST',
                $this->stringStartsWith('/api/v1/boards/create'),
                $this->callback(function (array $headers) {
                    return isset($headers['Content-Type'])
                        && isset($headers['X-Request-Id'])
                        && isset($headers['User-Agent']);
                }),
                $this->anything()
            )
            ->willReturn([
                'status' => 201,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{"id": 1}',
            ]);

        $rawResponse = $this->createMock(ResponseInterface::class);
        $rawResponse->method('withStatus')->willReturnSelf();
        $rawResponse->method('withHeader')->willReturnSelf();

        $this->httpResponse->method('raw')->willReturn($rawResponse);

        $this->controller->proxy($request, 'boards/create');
    }

    public function testProxyForwardsQueryString(): void
    {
        $request = $this->createProxyRequest('GET', 'search', 'q=test&page=2');

        $this->proxyClient->expects($this->once())
            ->method('forward')
            ->with(
                'search',
                'GET',
                '/api/v1/search?q=test&page=2',
                $this->anything(),
                $this->anything()
            )
            ->willReturn([
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '[]',
            ]);

        $rawResponse = $this->createMock(ResponseInterface::class);
        $rawResponse->method('withStatus')->willReturnSelf();
        $rawResponse->method('withHeader')->willReturnSelf();

        $this->httpResponse->method('raw')->willReturn($rawResponse);

        $this->controller->proxy($request, 'search');
    }

    /* ──────────────────────────────────────
     * proxyMedia() – path validation
     * ────────────────────────────────────── */

    public function testProxyMediaRejectsDirectoryTraversal(): void
    {
        $request = $this->createMock(RequestInterface::class);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('withStatus')->willReturnSelf();

        $this->httpResponse->expects($this->once())
            ->method('raw')
            ->with('Invalid path')
            ->willReturn($mockResponse);

        $this->controller->proxyMedia($request, '../../etc/passwd');
    }

    public function testProxyMediaRejectsNullBytes(): void
    {
        $request = $this->createMock(RequestInterface::class);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('withStatus')->willReturnSelf();

        $this->httpResponse->expects($this->once())
            ->method('raw')
            ->with('Invalid path')
            ->willReturn($mockResponse);

        $this->controller->proxyMedia($request, "file\0.jpg");
    }

    public function testProxyMediaRejectsEmptyPath(): void
    {
        $request = $this->createMock(RequestInterface::class);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('withStatus')->willReturnSelf();

        $this->httpResponse->expects($this->once())
            ->method('raw')
            ->with('Invalid path')
            ->willReturn($mockResponse);

        $this->controller->proxyMedia($request, '');
    }

    public function testProxyMediaRejectsSpecialCharacters(): void
    {
        $request = $this->createMock(RequestInterface::class);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('withStatus')->willReturnSelf();

        $this->httpResponse->expects($this->once())
            ->method('raw')
            ->with('Invalid path')
            ->willReturn($mockResponse);

        $this->controller->proxyMedia($request, 'file;rm -rf /.jpg');
    }

    /* ──────────────────────────────────────
     * Helpers
     * ────────────────────────────────────── */

    private function assertProxiedTo(string $expectedService, string $path): void
    {
        $request = $this->createProxyRequest('GET', $path);

        $this->proxyClient->expects($this->once())
            ->method('forward')
            ->with(
                $expectedService,
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{}',
            ]);

        $rawResponse = $this->createMock(ResponseInterface::class);
        $rawResponse->method('withStatus')->willReturnSelf();
        $rawResponse->method('withHeader')->willReturnSelf();

        $this->httpResponse->method('raw')->willReturn($rawResponse);

        $this->controller->proxy($request, $path);
    }

    private function createProxyRequest(string $method, string $path, string $queryString = ''): RequestInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn('');

        $request = $this->createMock(RequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getQueryString')->willReturn($queryString);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('server')->willReturn('127.0.0.1');
        $request->method('getBody')->willReturn($stream);

        return $request;
    }
}
