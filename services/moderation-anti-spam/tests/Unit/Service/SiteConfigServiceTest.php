<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SiteConfigService;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Service\SiteConfigService
 */
final class SiteConfigServiceTest extends TestCase
{
    private SiteConfigService $configService;
    private MockObject $redisMock;
    private MockObject $loggerMock;

    protected function setUp(): void
    {
        $this->redisMock = $this->createMock(Redis::class);
        $this->loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);

        $loggerFactory = $this->createMock(LoggerFactory::class);
        $loggerFactory->method('get')->willReturn($this->loggerMock);

        $this->configService = new SiteConfigService($loggerFactory, $this->redisMock);
    }

    public function testGetReturnsValueFromCache(): void
    {
        $cachedData = json_encode(['test_key' => 'test_value']);
        $this->redisMock
            ->method('get')
            ->with('site_config:all')
            ->willReturn($cachedData);

        $result = $this->configService->get('test_key');
        $this->assertEquals('test_value', $result);
    }

    public function testGetReturnsDefaultWhenKeyNotFound(): void
    {
        $cachedData = json_encode(['other_key' => 'other_value']);
        $this->redisMock
            ->method('get')
            ->with('site_config:all')
            ->willReturn($cachedData);

        $result = $this->configService->get('missing_key', 'default_value');
        $this->assertEquals('default_value', $result);
    }

    public function testGetReturnsEmptyStringWhenCacheMissAndNoDefault(): void
    {
        $this->redisMock
            ->method('get')
            ->with('site_config:all')
            ->willReturn(false);

        $result = $this->configService->get('any_key');
        $this->assertEquals('', $result);
    }

    public function testGetIntReturnsIntegerValue(): void
    {
        $cachedData = json_encode(['rate_limit' => '100']);
        $this->redisMock
            ->method('get')
            ->with('site_config:all')
            ->willReturn($cachedData);

        $result = $this->configService->getInt('rate_limit');
        $this->assertSame(100, $result);
    }

    public function testGetIntReturnsDefaultWhenConversionFails(): void
    {
        $cachedData = json_encode(['rate_limit' => 'not_a_number']);
        $this->redisMock
            ->method('get')
            ->with('site_config:all')
            ->willReturn($cachedData);

        $result = $this->configService->getInt('rate_limit', 50);
        $this->assertSame(50, $result);
    }

    public function testGetFloatReturnsFloatValue(): void
    {
        $cachedData = json_encode(['threshold' => '7.5']);
        $this->redisMock
            ->method('get')
            ->with('site_config:all')
            ->willReturn($cachedData);

        $result = $this->configService->getFloat('threshold');
        $this->assertSame(7.5, $result);
    }

    public function testGetFloatReturnsDefaultWhenConversionFails(): void
    {
        $cachedData = json_encode(['threshold' => 'invalid']);
        $this->redisMock
            ->method('get')
            ->with('site_config:all')
            ->willReturn($cachedData);

        $result = $this->configService->getFloat('threshold', 5.0);
        $this->assertSame(5.0, $result);
    }

    public function testGetBoolReturnsTrueForTruthyValues(): void
    {
        $testCases = [
            ['enabled' => 'true'],
            ['enabled' => '1'],
            ['enabled' => 'yes'],
            ['enabled' => 'on'],
        ];

        foreach ($testCases as $case) {
            $cachedData = json_encode($case);
            $this->redisMock
                ->method('get')
                ->with('site_config:all')
                ->willReturn($cachedData);

            $result = $this->configService->getBool(array_keys($case)[0]);
            $this->assertTrue($result);
        }
    }

    public function testGetBoolReturnsFalseForFalsyValues(): void
    {
        $testCases = [
            ['enabled' => 'false'],
            ['enabled' => '0'],
            ['enabled' => 'no'],
            ['enabled' => 'off'],
            ['enabled' => ''],
        ];

        foreach ($testCases as $case) {
            $cachedData = json_encode($case);
            $this->redisMock
                ->method('get')
                ->with('site_config:all')
                ->willReturn($cachedData);

            $result = $this->configService->getBool(array_keys($case)[0]);
            $this->assertFalse($result);
        }
    }

    public function testGetListReturnsArrayFromCommaSeparatedString(): void
    {
        $cachedData = json_encode(['allowed_ips' => '192.168.1.1,192.168.1.2,192.168.1.3']);
        $this->redisMock
            ->method('get')
            ->with('site_config:all')
            ->willReturn($cachedData);

        $result = $this->configService->getList('allowed_ips');
        $this->assertEquals(['192.168.1.1', '192.168.1.2', '192.168.1.3'], $result);
    }

    public function testGetListReturnsEmptyArrayWhenValueEmpty(): void
    {
        $cachedData = json_encode(['allowed_ips' => '']);
        $this->redisMock
            ->method('get')
            ->with('site_config:all')
            ->willReturn($cachedData);

        $result = $this->configService->getList('allowed_ips');
        $this->assertEquals([], $result);
    }

    public function testGetListReturnsDefaultWhenKeyNotFound(): void
    {
        $cachedData = json_encode(['other_key' => 'value']);
        $this->redisMock
            ->method('get')
            ->with('site_config:all')
            ->willReturn($cachedData);

        $result = $this->configService->getList('missing_key', 'default1,default2');
        $this->assertEquals(['default1', 'default2'], $result);
    }

    public function testGetHandlesInvalidJsonGracefully(): void
    {
        $this->redisMock
            ->method('get')
            ->with('site_config:all')
            ->willReturn('invalid json');

        $result = $this->configService->get('any_key', 'fallback');
        $this->assertEquals('fallback', $result);
    }

    public function testGetHandlesNullCacheResponse(): void
    {
        $this->redisMock
            ->method('get')
            ->with('site_config:all')
            ->willReturn(null);

        $result = $this->configService->get('any_key', 'fallback');
        $this->assertEquals('fallback', $result);
    }

    public function testGetIntWithBooleanStringReturnsZero(): void
    {
        $cachedData = json_encode(['flag' => 'true']);
        $this->redisMock
            ->method('get')
            ->with('site_config:all')
            ->willReturn($cachedData);

        $result = $this->configService->getInt('flag', 0);
        $this->assertSame(0, $result);
    }

    public function testMultipleGetCallsUseCache(): void
    {
        $cachedData = json_encode([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3'
        ]);

        $this->redisMock
            ->method('get')
            ->with('site_config:all')
            ->willReturn($cachedData);

        $this->assertEquals('value1', $this->configService->get('key1'));
        $this->assertEquals('value2', $this->configService->get('key2'));
        $this->assertEquals('value3', $this->configService->get('key3'));
    }
}
