<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\SiteConfigService;
use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SiteConfigService.
 *
 * Covers: get(), getInt(), getFloat(), getBool(), getList(), loadAll() with
 * Redis cache hit, Redis miss + DB fallback, and full fallback on both failures.
 */
final class SiteConfigServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Redis&Mockery\MockInterface $redis;
    private LoggerFactory&Mockery\MockInterface $loggerFactory;
    private LoggerInterface&Mockery\MockInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->loggerFactory = Mockery::mock(LoggerFactory::class);
        $this->loggerFactory->shouldReceive('get')
            ->with('site-config')
            ->andReturn($this->logger);

        $this->redis = Mockery::mock(Redis::class);
    }

    private function makeService(): SiteConfigService
    {
        return new SiteConfigService($this->loggerFactory, $this->redis);
    }

    // ── get() ────────────────────────────────────────────────────────

    public function testGetReturnsValueFromRedisCache(): void
    {
        $cached = json_encode(['max_file_size' => '8388608', 'site_name' => 'Test']);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertSame('8388608', $service->get('max_file_size'));
    }

    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $cached = json_encode(['foo' => 'bar']);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertSame('fallback', $service->get('nonexistent', 'fallback'));
    }

    public function testGetCachesInMemoryOnSecondCall(): void
    {
        $cached = json_encode(['key1' => 'val1']);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $service->get('key1');
        // Second call should NOT hit Redis again (in-memory cache)
        $this->assertSame('val1', $service->get('key1'));
    }

    public function testGetFallsBackToDefaultsWhenRedisAndDbFail(): void
    {
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andThrow(new \RuntimeException('Redis down'));

        $this->logger->shouldReceive('warning')
            ->once();

        $service = $this->makeService();
        $this->assertSame('default_val', $service->get('anything', 'default_val'));
    }

    // ── getInt() ─────────────────────────────────────────────────────

    public function testGetIntReturnsIntValue(): void
    {
        $cached = json_encode(['max_file_size' => '4194304']);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertSame(4194304, $service->getInt('max_file_size'));
    }

    public function testGetIntReturnsDefaultForMissingKey(): void
    {
        $cached = json_encode([]);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertSame(42, $service->getInt('missing', 42));
    }

    public function testGetIntReturnsDefaultForEmptyString(): void
    {
        $cached = json_encode(['empty_val' => '']);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertSame(99, $service->getInt('empty_val', 99));
    }

    // ── getFloat() ───────────────────────────────────────────────────

    public function testGetFloatReturnsFloatValue(): void
    {
        $cached = json_encode(['ratio' => '3.14']);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertEqualsWithDelta(3.14, $service->getFloat('ratio'), 0.001);
    }

    public function testGetFloatReturnsDefaultForMissingKey(): void
    {
        $cached = json_encode([]);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertEqualsWithDelta(1.5, $service->getFloat('missing', 1.5), 0.001);
    }

    // ── getBool() ────────────────────────────────────────────────────

    /**
     * @dataProvider boolTrueProvider
     */
    public function testGetBoolReturnsTrueForTruthyValues(string $value): void
    {
        $cached = json_encode(['flag' => $value]);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertTrue($service->getBool('flag'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function boolTrueProvider(): array
    {
        return [
            'true'  => ['true'],
            'TRUE'  => ['TRUE'],
            '1'     => ['1'],
            'yes'   => ['yes'],
            'on'    => ['on'],
            'Yes'   => ['Yes'],
            'ON'    => ['ON'],
        ];
    }

    /**
     * @dataProvider boolFalseProvider
     */
    public function testGetBoolReturnsFalseForFalsyValues(string $value): void
    {
        $cached = json_encode(['flag' => $value]);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertFalse($service->getBool('flag'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function boolFalseProvider(): array
    {
        return [
            'false' => ['false'],
            '0'     => ['0'],
            'no'    => ['no'],
            'off'   => ['off'],
            'random' => ['random'],
        ];
    }

    public function testGetBoolReturnsDefaultWhenKeyMissing(): void
    {
        $cached = json_encode([]);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertTrue($service->getBool('missing', true));
        $this->assertFalse($service->getBool('missing', false));
    }

    // ── getList() ────────────────────────────────────────────────────

    public function testGetListParsesCommaSeparatedString(): void
    {
        $cached = json_encode(['mimes' => 'image/jpeg, image/png, image/gif']);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertSame(['image/jpeg', 'image/png', 'image/gif'], $service->getList('mimes'));
    }

    public function testGetListReturnsSingleItemForNoComma(): void
    {
        $cached = json_encode(['mimes' => 'image/jpeg']);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertSame(['image/jpeg'], $service->getList('mimes'));
    }

    public function testGetListReturnsEmptyArrayForEmptyString(): void
    {
        $cached = json_encode(['mimes' => '']);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertSame([], $service->getList('mimes'));
    }

    public function testGetListUsesDefaultWhenKeyMissing(): void
    {
        $cached = json_encode([]);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertSame(['a', 'b'], $service->getList('missing', 'a,b'));
    }

    public function testGetListReturnsEmptyArrayWhenKeyAndDefaultEmpty(): void
    {
        $cached = json_encode([]);
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn($cached);

        $service = $this->makeService();
        $this->assertSame([], $service->getList('missing'));
    }

    // ── Redis empty/invalid cache ────────────────────────────────────

    public function testHandlesEmptyRedisReturn(): void
    {
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn('');

        // DB query will also fail since no DB in unit tests
        $this->logger->shouldReceive('warning')
            ->once();

        $service = $this->makeService();
        $this->assertSame('default', $service->get('key', 'default'));
    }

    public function testHandlesInvalidJsonFromRedis(): void
    {
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn('not-json{');

        // Falls through to DB which will also fail
        $this->logger->shouldReceive('warning')
            ->once();

        $service = $this->makeService();
        $this->assertSame('fallback', $service->get('key', 'fallback'));
    }

    public function testHandlesFalseFromRedis(): void
    {
        $this->redis->shouldReceive('get')
            ->with('site_config:all')
            ->once()
            ->andReturn(false);

        $this->logger->shouldReceive('warning')
            ->once();

        $service = $this->makeService();
        $this->assertSame('default', $service->get('key', 'default'));
    }
}
