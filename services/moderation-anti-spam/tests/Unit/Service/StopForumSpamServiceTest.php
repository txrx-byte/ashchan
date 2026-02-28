<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\StopForumSpamService;
use Hyperf\Guzzle\ClientFactory;
use Hyperf\Logger\LoggerFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Service\StopForumSpamService
 */
final class StopForumSpamServiceTest extends TestCase
{
    private MockInterface $clientFactoryMock;
    private MockInterface $loggerMock;
    private MockInterface $loggerFactoryMock;
    private MockInterface $configMock;
    private StopForumSpamService $sfsService;

    protected function setUp(): void
    {
        $this->clientFactoryMock = m::mock(ClientFactory::class);
        $this->loggerMock = m::mock(\Psr\Log\LoggerInterface::class);
        $this->loggerFactoryMock = m::mock(LoggerFactory::class);
        $this->loggerFactoryMock
            ->shouldReceive('get')
            ->andReturn($this->loggerMock);
        $this->configMock = m::mock(\App\Service\SiteConfigService::class);

        $this->configMock
            ->shouldReceive('getString')
            ->with('sfs_api_key', '')
            ->andReturn('');
        $this->configMock
            ->shouldReceive('getInt')
            ->with('sfs_confidence_threshold', 80)
            ->andReturn(80);

        $this->sfsService = new StopForumSpamService(
            $this->clientFactoryMock,
            $this->loggerFactoryMock,
            $this->configMock
        );
    }

    protected function tearDown(): void
    {
        m::close();
    }

    public function testCheckReturnsFalseOnAPIFailure(): void
    {
        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $httpClientMock
            ->shouldReceive('get')
            ->andThrow(new \Exception('Connection failed'));

        $result = $this->sfsService->check('192.168.1.1');
        $this->assertFalse($result);
    }

    public function testCheckReturnsFalseOnTimeout(): void
    {
        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $httpClientMock
            ->shouldReceive('get')
            ->andThrow(new \GuzzleHttp\Exception\ConnectException(
                'Timeout',
                new \GuzzleHttp\Psr7\Request('GET', 'http://example.com')
            ));

        $result = $this->sfsService->check('192.168.1.1');
        $this->assertFalse($result);
    }

    public function testCheckReturnsFalseWhenIPNotInDatabase(): void
    {
        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'success' => 1,
            'ip' => ['appears' => 0]
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->sfsService->check('8.8.8.8');
        $this->assertFalse($result);
    }

    public function testCheckReturnsFalseWhenConfidenceBelowThreshold(): void
    {
        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'success' => 1,
            'ip' => [
                'appears' => 1,
                'confidence' => 50 // Below 80 threshold
            ]
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->sfsService->check('1.2.3.4');
        $this->assertFalse($result);
    }

    public function testCheckReturnsTrueWhenIPBlockedWithHighConfidence(): void
    {
        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'success' => 1,
            'ip' => [
                'appears' => 1,
                'confidence' => 95 // Above 80 threshold
            ]
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->sfsService->check('5.6.7.8');
        $this->assertTrue($result);
    }

    public function testCheckWithEmailBlocksSpammer(): void
    {
        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'success' => 1,
            'ip' => ['appears' => 0],
            'email' => [
                'appears' => 1,
                'confidence' => 90
            ]
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->sfsService->check('8.8.8.8', 'spammer@example.com');
        $this->assertTrue($result);
    }

    public function testCheckWithUsernameBlocksSpammer(): void
    {
        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'success' => 1,
            'ip' => ['appears' => 0],
            'email' => ['appears' => 0],
            'username' => [
                'appears' => 1,
                'confidence' => 85
            ]
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->sfsService->check('8.8.8.8', null, 'known_spammer');
        $this->assertTrue($result);
    }

    public function testCheckWithMultipleEntitiesBlocksWhenAnyMatch(): void
    {
        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'success' => 1,
            'ip' => [
                'appears' => 1,
                'confidence' => 90
            ],
            'email' => [
                'appears' => 1,
                'confidence' => 85
            ],
            'username' => [
                'appears' => 0
            ]
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->sfsService->check('1.2.3.4', 'bad@example.com', 'baduser');
        $this->assertTrue($result);
    }

    public function testCheckHandlesInvalidResponse(): void
    {
        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                'invalid json'
            ));

        $result = $this->sfsService->check('8.8.8.8');
        $this->assertFalse($result);
    }

    public function testCheckHandlesMissingSuccessField(): void
    {
        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'error' => 'Invalid parameters'
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->sfsService->check('8.8.8.8');
        $this->assertFalse($result);
    }

    public function testCheckHandles500Response(): void
    {
        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(500));

        $result = $this->sfsService->check('8.8.8.8');
        $this->assertFalse($result);
    }

    public function testReportReturnsFalseWithoutAPIKey(): void
    {
        $this->configMock
            ->shouldReceive('getString')
            ->with('sfs_api_key', '')
            ->andReturn('');

        $result = $this->sfsService->report('1.2.3.4', 'test@example.com', 'spammer', 'evidence');
        $this->assertFalse($result);
    }

    public function testReportReturnsFalseOnAPIFailure(): void
    {
        $this->configMock
            ->shouldReceive('getString')
            ->with('sfs_api_key', '')
            ->andReturn('test_api_key');

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $httpClientMock
            ->shouldReceive('post')
            ->andThrow(new \Exception('Connection failed'));

        $result = $this->sfsService->report('1.2.3.4', 'test@example.com', 'spammer', 'evidence');
        $this->assertFalse($result);
    }

    public function testReportReturnsTrueOnSuccess(): void
    {
        $this->configMock
            ->shouldReceive('getString')
            ->with('sfs_api_key', '')
            ->andReturn('test_api_key');

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'success' => 1
        ];

        $httpClientMock
            ->shouldReceive('post')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->sfsService->report('1.2.3.4', 'test@example.com', 'spammer', 'evidence');
        $this->assertTrue($result);
    }

    public function testReportLogsErrorOnFailure(): void
    {
        $this->configMock
            ->shouldReceive('getString')
            ->with('sfs_api_key', '')
            ->andReturn('test_api_key');

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'success' => 0,
            'error' => 'Invalid evidence'
        ];

        $httpClientMock
            ->shouldReceive('post')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $this->loggerMock
            ->shouldReceive('warning')
            ->once();

        $result = $this->sfsService->report('1.2.3.4', 'test@example.com', 'spammer', 'evidence');
        $this->assertFalse($result);
    }

    public function testReportWithLongEvidenceIsTruncated(): void
    {
        $this->configMock
            ->shouldReceive('getString')
            ->with('sfs_api_key', '')
            ->andReturn('test_api_key');

        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'success' => 1
        ];

        $httpClientMock
            ->shouldReceive('post')
            ->with(
                m::type('string'),
                m::on(function ($options) {
                    // Evidence should be truncated to 2000 chars
                    return strlen($options['form_params']['evidence']) <= 2000;
                })
            )
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $longEvidence = str_repeat('x', 3000);
        $result = $this->sfsService->report('1.2.3.4', 'test@example.com', 'spammer', $longEvidence);
        $this->assertTrue($result);
    }

    public function testCheckWithEmptyIPReturnsFalse(): void
    {
        $result = $this->sfsService->check('');
        $this->assertFalse($result);
    }

    public function testCheckWithInvalidIPFormatReturnsFalse(): void
    {
        $result = $this->sfsService->check('not.an.ip.address');
        $this->assertFalse($result);
    }

    public function testCheckWithIPv6Address(): void
    {
        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        $responseData = [
            'success' => 1,
            'ip' => [
                'appears' => 1,
                'confidence' => 90
            ]
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->sfsService->check('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        $this->assertTrue($result);
    }

    public function testCheckAtConfidenceThresholdBoundary(): void
    {
        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        // Exactly at threshold (80)
        $responseData = [
            'success' => 1,
            'ip' => [
                'appears' => 1,
                'confidence' => 80
            ]
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->sfsService->check('8.8.8.8');
        $this->assertTrue($result);
    }

    public function testCheckJustBelowConfidenceThreshold(): void
    {
        $httpClientMock = m::mock(Client::class);
        $this->clientFactoryMock
            ->shouldReceive('create')
            ->andReturn($httpClientMock);

        // Just below threshold (79)
        $responseData = [
            'success' => 1,
            'ip' => [
                'appears' => 1,
                'confidence' => 79
            ]
        ];

        $httpClientMock
            ->shouldReceive('get')
            ->andReturn(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($responseData)
            ));

        $result = $this->sfsService->check('8.8.8.8');
        $this->assertFalse($result);
    }
}
