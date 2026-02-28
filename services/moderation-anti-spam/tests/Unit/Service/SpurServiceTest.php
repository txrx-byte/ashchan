<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SpurService;
use App\Service\SiteSettingsService;
use App\Service\SiteConfigService;
use Hyperf\Guzzle\ClientFactory;
use Hyperf\Logger\LoggerFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Service\SpurService
 */
final class SpurServiceTest extends TestCase
{
    private MockInterface $clientFactoryMock;
    private MockInterface $settingsServiceMock;
    private MockInterface $loggerMock;
    private MockInterface $configMock;
    private MockInterface $loggerFactoryMock;
    private SpurService $spurService;

    protected function setUp(): void
    {
        $this->clientFactoryMock = m::mock(ClientFactory::class);
        $this->settingsServiceMock = m::mock(SiteSettingsService::class);
        $this->loggerMock = m::mock(\Psr\Log\LoggerInterface::class);
        $this->loggerFactoryMock = m::mock(LoggerFactory::class);
        $this->loggerFactoryMock
            ->shouldReceive('get')
            ->andReturn($this->loggerMock);
        $this->configMock = m::mock(SiteConfigService::class);

        // Default config
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(false);
        $this->configMock
            ->shouldReceive('getString')
            ->with('spur_api_token', '')
            ->andReturn('test_token');
        $this->configMock
            ->shouldReceive('getInt')
            ->with('spur_timeout', 3)
            ->andReturn(3);

        $this->spurService = new SpurService(
            $this->clientFactoryMock,
            $this->settingsServiceMock,
            $this->loggerFactoryMock,
            $this->configMock
        );
    }

    protected function tearDown(): void
    {
        m::close();
    }

    public function testIsEnabledReturnsFalseWithoutToken(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(false);
        $this->configMock
            ->shouldReceive('getString')
            ->with('spur_api_token', '')
            ->andReturn('');

        $service = new SpurService(
            $this->clientFactoryMock,
            $this->settingsServiceMock,
            $this->loggerFactoryMock,
            $this->configMock
        );

        $this->assertFalse($service->isEnabled());
    }

    public function testIsEnabledReturnsTrueWithTokenAndFeatureEnabled(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);
        $this->configMock
            ->shouldReceive('getString')
            ->with('spur_api_token', '')
            ->andReturn('valid_token');

        $service = new SpurService(
            $this->clientFactoryMock,
            $this->settingsServiceMock,
            $this->loggerFactoryMock,
            $this->configMock
        );

        $this->assertTrue($service->isEnabled());
    }

    public function testLookupReturnsNullWhenDisabled(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(false);

        $result = $this->spurService->lookup('192.168.1.1');
        $this->assertNull($result);
    }

    public function testLookupReturnsNullForPrivateIP(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $result = $this->spurService->lookup('192.168.1.1');
        $this->assertNull($result);

        $result = $this->spurService->lookup('10.0.0.1');
        $this->assertNull($result);

        $result = $this->spurService->lookup('172.16.0.1');
        $this->assertNull($result);
    }

    public function testLookupReturnsNullForInvalidIP(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $result = $this->spurService->lookup('not_an_ip');
        $this->assertNull($result);

        $result = $this->spurService->lookup('');
        $this->assertNull($result);
    }

    public function testLookupReturnsNullOnAPIFailure(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $httpClientMock
            ->shouldReceive('get')
            ->andThrow(new \Exception('API error'));

        $result = $this->spurService->lookup('8.8.8.8');
        $this->assertNull($result);
    }

    public function testLookupReturnsNullOn404Response(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(404));

        $result = $this->spurService->lookup('8.8.8.8');
        $this->assertNull($result);
    }

    public function testLookupReturnsDataOnSuccess(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'risks' => ['VPN'],
            'tunnels' => [['type' => 'VPN']],
            'infrastructure' => ['DATACENTER'],
            'client' => ['type' => 'vpn'],
            'location' => ['country' => 'US'],
            'as' => ['asn' => 12345],
            'organization' => 'Test Org',
            'ip' => '8.8.8.8'
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->spurService->lookup('8.8.8.8');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('risks', $result);
        $this->assertArrayHasKey('tunnels', $result);
        $this->assertArrayHasKey('infrastructure', $result);
        $this->assertEquals('8.8.8.8', $result['ip']);
    }

    public function testEvaluateReturnsLowScoreForCleanIP(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        // Clean residential IP
        $responseData = [
            'risks' => [],
            'tunnels' => [],
            'infrastructure' => ['RESIDENTIAL'],
            'client' => ['type' => 'residential'],
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->spurService->evaluate('8.8.8.8');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('block', $result);
        $this->assertArrayHasKey('context', $result);
        $this->assertEquals(0, $result['score']);
        $this->assertFalse($result['block']);
        $this->assertEmpty($result['reasons']);
    }

    public function testEvaluateReturnsHighScoreForVPN(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'risks' => ['VPN'],
            'tunnels' => [['type' => 'VPN']],
            'infrastructure' => [],
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->spurService->evaluate('1.2.3.4');

        $this->assertGreaterThan(0, $result['score']);
        $this->assertContains('VPN detected', $result['reasons']);
    }

    public function testEvaluateReturnsBlockForBotnet(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'risks' => ['BOTNET'],
            'tunnels' => [],
            'infrastructure' => [],
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->spurService->evaluate('5.6.7.8');

        $this->assertTrue($result['block']);
        $this->assertContains('BOTNET detected', $result['reasons']);
    }

    public function testEvaluateReturnsBlockForMalware(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'risks' => ['MALWARE'],
            'tunnels' => [],
            'infrastructure' => [],
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->spurService->evaluate('9.10.11.12');

        $this->assertTrue($result['block']);
        $this->assertContains('MALWARE detected', $result['reasons']);
    }

    public function testEvaluateScoresDatacenterIP(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'risks' => [],
            'tunnels' => [],
            'infrastructure' => ['DATACENTER'],
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->spurService->evaluate('13.14.15.16');

        $this->assertGreaterThan(0, $result['score']);
        $this->assertContains('Datacenter IP', $result['reasons']);
    }

    public function testEvaluateScoresProxy(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'risks' => ['PROXY'],
            'tunnels' => [['type' => 'PROXY']],
            'infrastructure' => [],
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->spurService->evaluate('17.18.19.20');

        $this->assertGreaterThan(0, $result['score']);
        $this->assertContains('PROXY detected', $result['reasons']);
    }

    public function testEvaluateScoresTor(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'risks' => ['TOR'],
            'tunnels' => [['type' => 'TOR']],
            'infrastructure' => [],
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->spurService->evaluate('21.22.23.24');

        $this->assertGreaterThan(0, $result['score']);
        $this->assertContains('TOR detected', $result['reasons']);
    }

    public function testEvaluateHandlesRateLimitResponse(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(429));

        $result = $this->spurService->evaluate('25.26.27.28');

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['score']);
        $this->assertFalse($result['block']);
    }

    public function testEvaluateReturnsNullContextWhenDisabled(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(false);

        $result = $this->spurService->evaluate('29.30.31.32');

        $this->assertArrayHasKey('context', $result);
        $this->assertNull($result['context']);
    }

    public function testEvaluateWithMultipleRiskFactors(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'risks' => ['VPN', 'CALLBACK_PROXY', 'GEO_MISMATCH'],
            'tunnels' => [['type' => 'VPN']],
            'infrastructure' => ['DATACENTER'],
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->spurService->evaluate('33.34.35.36');

        $this->assertGreaterThan(0, $result['score']);
        $this->assertCount(3, $result['reasons']);
    }

    public function testEvaluateWithWebScrapingRisk(): void
    {
        $this->configMock
            ->shouldReceive('getBool')
            ->with('spur_enabled', false)
            ->andReturn(true);

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'risks' => ['WEB_SCRAPING'],
            'tunnels' => [],
            'infrastructure' => [],
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->spurService->evaluate('37.38.39.40');

        $this->assertGreaterThan(0, $result['score']);
        $this->assertContains('WEB_SCRAPING', $result['reasons']);
    }
}
