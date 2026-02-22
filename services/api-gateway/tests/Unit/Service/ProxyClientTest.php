<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ProxyClient;
use Hyperf\Contract\ConfigInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Service\ProxyClient
 */
final class ProxyClientTest extends TestCase
{
    private function makeClient(array $services = []): ProxyClient
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('get')
            ->willReturnCallback(function (string $key) use ($services) {
                $map = [
                    'services.auth.url' => $services['auth'] ?? '',
                    'services.boards.url' => $services['boards'] ?? '',
                    'services.media.url' => $services['media'] ?? '',
                    'services.search.url' => $services['search'] ?? '',
                    'services.moderation.url' => $services['moderation'] ?? '',
                ];
                return $map[$key] ?? '';
            });

        return new ProxyClient($config);
    }

    /* ──────────────────────────────────────
     * Unknown service
     * ────────────────────────────────────── */

    public function testForwardUnknownServiceReturns502(): void
    {
        $client = $this->makeClient();
        $result = $client->forward('nonexistent', 'GET', '/health');

        $this->assertSame(502, $result['status']);
        $body = json_decode((string) $result['body'], true);
        $this->assertSame('Unknown service', $body['error']);
    }

    /* ──────────────────────────────────────
     * Empty method
     * ────────────────────────────────────── */

    public function testForwardEmptyMethodReturns400(): void
    {
        $client = $this->makeClient(['auth' => 'http://localhost:9502']);
        $result = $client->forward('auth', '', '/health');

        $this->assertSame(400, $result['status']);
        $body = json_decode((string) $result['body'], true);
        $this->assertSame('HTTP method cannot be empty', $body['error']);
    }

    /* ──────────────────────────────────────
     * Service URL defaults
     * ────────────────────────────────────── */

    public function testUsesConfigServiceUrl(): void
    {
        // When config provides a URL, it should be used
        $client = $this->makeClient(['auth' => 'http://custom-auth:1234']);

        // The forward call will try to actually make an HTTP request,
        // which will fail since custom-auth:1234 doesn't exist.
        // We're testing that it doesn't return "Unknown service".
        $result = $client->forward('auth', 'GET', '/health');

        // Should NOT be 502 with "Unknown service" — the service was found
        if ($result['status'] === 502) {
            $body = json_decode((string) $result['body'], true);
            $this->assertNotSame('Unknown service', $body['error'] ?? '');
        }
    }

    /* ──────────────────────────────────────
     * All service names are recognized
     * ────────────────────────────────────── */

    /**
     * @dataProvider serviceNameProvider
     */
    public function testAllServiceNamesRecognized(string $serviceName): void
    {
        $client = $this->makeClient([
            'auth' => 'http://localhost:9502',
            'boards' => 'http://localhost:9503',
            'media' => 'http://localhost:9504',
            'search' => 'http://localhost:9505',
            'moderation' => 'http://localhost:9506',
        ]);

        $result = $client->forward($serviceName, 'GET', '/health');

        // Should not return "Unknown service"
        if ($result['status'] === 502) {
            $body = json_decode((string) $result['body'], true);
            $this->assertNotSame('Unknown service', $body['error'] ?? '');
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function serviceNameProvider(): array
    {
        return [
            'auth' => ['auth'],
            'boards' => ['boards'],
            'media' => ['media'],
            'search' => ['search'],
            'moderation' => ['moderation'],
        ];
    }
}
