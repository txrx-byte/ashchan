<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\SiteConfigService;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SiteConfigServiceTest extends TestCase
{
    private SiteConfigService $service;
    private Redis&MockInterface $mockRedis;
    private LoggerFactory&MockInterface $mockLoggerFactory;
    private LoggerInterface&MockInterface $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRedis = Mockery::mock(Redis::class);
        $this->mockLoggerFactory = Mockery::mock(LoggerFactory::class);
        $this->mockLogger = Mockery::mock(LoggerInterface::class);

        $this->mockLoggerFactory->shouldReceive('get')
            ->with('site-config')
            ->andReturn($this->mockLogger);

        $this->service = new SiteConfigService($this->mockLoggerFactory, $this->mockRedis);
    }

    protected function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        Mockery::close();
        parent::tearDown();
    }

    // --- get() tests ---

    public function testGetReturnsValueFromRedisCache(): void
    {
        $cached = json_encode(['site_name' => 'Ashchan', 'max_file_size' => '4194304']);

        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $result = $this->service->get('site_name');
        $this->assertSame('Ashchan', $result);
    }

    public function testGetReturnsDefaultWhenKeyNotFound(): void
    {
        $cached = json_encode(['site_name' => 'Ashchan']);

        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $result = $this->service->get('nonexistent_key', 'default_value');
        $this->assertSame('default_value', $result);
    }

    public function testGetUsesInMemoryCacheOnSubsequentCalls(): void
    {
        $cached = json_encode(['site_name' => 'Ashchan']);

        // Redis should only be called once
        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $this->service->get('site_name');
        $result = $this->service->get('site_name');

        $this->assertSame('Ashchan', $result);
    }

    public function testGetReturnsEmptyDefaultWhenRedisReturnsInvalidJson(): void
    {
        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn('not-valid-json');

        // When Redis returns invalid JSON and DB also fails, returns default
        $this->mockLogger->shouldReceive('warning')
            ->once()
            ->with('Failed to load site settings', Mockery::type('array'));

        $result = $this->service->get('key', 'fallback');
        $this->assertSame('fallback', $result);
    }

    public function testGetHandlesRedisExceptionGracefully(): void
    {
        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andThrow(new \RuntimeException('Redis connection refused'));

        // Falls back to DB, which also fails since no container
        $this->mockLogger->shouldReceive('warning')
            ->once()
            ->with('Failed to load site settings', Mockery::type('array'));

        $result = $this->service->get('nonexistent', 'fallback');
        $this->assertSame('fallback', $result);
    }

    // --- getInt() tests ---

    public function testGetIntReturnsIntegerValue(): void
    {
        $cached = json_encode(['max_threads' => '100']);

        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $this->assertSame(100, $this->service->getInt('max_threads'));
    }

    public function testGetIntReturnsDefaultWhenEmpty(): void
    {
        $cached = json_encode([]);

        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $this->assertSame(42, $this->service->getInt('missing_key', 42));
    }

    public function testGetIntConvertsStringToInt(): void
    {
        $cached = json_encode(['count' => '55']);

        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $this->assertSame(55, $this->service->getInt('count'));
    }

    // --- getFloat() tests ---

    public function testGetFloatReturnsFloatValue(): void
    {
        $cached = json_encode(['rate_limit' => '1.5']);

        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $this->assertSame(1.5, $this->service->getFloat('rate_limit'));
    }

    public function testGetFloatReturnsDefaultWhenEmpty(): void
    {
        $cached = json_encode([]);

        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $this->assertSame(3.14, $this->service->getFloat('missing', 3.14));
    }

    // --- getBool() tests ---

    public function testGetBoolReturnsTrueForTrueValues(): void
    {
        $cached = json_encode([
            'feature_a' => 'true',
            'feature_b' => '1',
            'feature_c' => 'yes',
            'feature_d' => 'on',
        ]);

        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $this->assertTrue($this->service->getBool('feature_a'));
        $this->assertTrue($this->service->getBool('feature_b'));
        $this->assertTrue($this->service->getBool('feature_c'));
        $this->assertTrue($this->service->getBool('feature_d'));
    }

    public function testGetBoolReturnsFalseForFalseValues(): void
    {
        $cached = json_encode([
            'feature_a' => 'false',
            'feature_b' => '0',
            'feature_c' => 'no',
            'feature_d' => 'off',
        ]);

        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $this->assertFalse($this->service->getBool('feature_a'));
        $this->assertFalse($this->service->getBool('feature_b'));
        $this->assertFalse($this->service->getBool('feature_c'));
        $this->assertFalse($this->service->getBool('feature_d'));
    }

    public function testGetBoolReturnsDefaultWhenMissing(): void
    {
        $cached = json_encode([]);

        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $this->assertFalse($this->service->getBool('missing'));
        $this->assertTrue($this->service->getBool('missing', true));
    }

    // --- getList() tests ---

    public function testGetListReturnsParsedCommaSeparatedValues(): void
    {
        $cached = json_encode(['allowed_exts' => 'jpg,png,gif,webp']);

        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $result = $this->service->getList('allowed_exts');
        $this->assertSame(['jpg', 'png', 'gif', 'webp'], $result);
    }

    public function testGetListTrimsWhitespace(): void
    {
        $cached = json_encode(['tags' => ' alpha , beta , gamma ']);

        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $result = $this->service->getList('tags');
        $this->assertSame(['alpha', 'beta', 'gamma'], $result);
    }

    public function testGetListReturnsEmptyArrayWhenEmpty(): void
    {
        $cached = json_encode([]);

        $this->mockRedis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $result = $this->service->getList('missing');
        $this->assertSame([], $result);
    }
}
