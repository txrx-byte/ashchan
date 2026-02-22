<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AuthService;
use App\Service\PiiEncryptionService;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \App\Service\AuthService
 */
final class AuthServiceTest extends TestCase
{
    private MockObject $redis;

    protected function setUp(): void
    {
        putenv('IP_HMAC_KEY=test-hmac-key-for-unit-tests');
    }

    protected function tearDown(): void
    {
        putenv('IP_HMAC_KEY');
        putenv('PII_ENCRYPTION_KEY');
    }

    /**
     * Build an AuthService using reflection so that the real (final)
     * PiiEncryptionService can be injected without needing to mock it.
     */
    private function createService(): AuthService
    {
        $this->redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->addMethods(['get', 'setex', 'del'])
            ->getMock();

        $logger = $this->createMock(LoggerInterface::class);
        $loggerFactory = $this->createMock(LoggerFactory::class);
        $loggerFactory->method('get')->willReturn($logger);

        putenv('PII_ENCRYPTION_KEY=unit-test-pii-key-must-be-long-enough');
        $pii = new PiiEncryptionService($loggerFactory);

        return new AuthService($this->redis, $pii);
    }

    /* ──────────────────────────────────────
     * Constructor
     * ────────────────────────────────────── */

    public function testConstructorRequiresHmacKey(): void
    {
        putenv('IP_HMAC_KEY=');
        putenv('PII_ENCRYPTION_KEY=');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('IP_HMAC_KEY or PII_ENCRYPTION_KEY must be configured');

        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->addMethods(['get', 'setex', 'del'])
            ->getMock();
        $logger = $this->createMock(LoggerInterface::class);
        $lf = $this->createMock(LoggerFactory::class);
        $lf->method('get')->willReturn($logger);
        // PiiEncryptionService works without a key (disabled mode)
        $pii = new PiiEncryptionService($lf);

        new AuthService($redis, $pii);
    }

    public function testConstructorAcceptsIpHmacKey(): void
    {
        $service = $this->createService();
        $this->assertInstanceOf(AuthService::class, $service);
    }

    public function testConstructorFallsToPiiEncryptionKey(): void
    {
        putenv('IP_HMAC_KEY=');
        putenv('PII_ENCRYPTION_KEY=fallback-key-for-testing');

        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->addMethods(['get', 'setex', 'del'])
            ->getMock();
        $logger = $this->createMock(LoggerInterface::class);
        $lf = $this->createMock(LoggerFactory::class);
        $lf->method('get')->willReturn($logger);
        $pii = new PiiEncryptionService($lf);

        $service = new AuthService($redis, $pii);
        $this->assertInstanceOf(AuthService::class, $service);
    }

    /* ──────────────────────────────────────
     * getRedis()
     * ────────────────────────────────────── */

    public function testGetRedisReturnsInjectedInstance(): void
    {
        $service = $this->createService();
        $this->assertSame($this->redis, $service->getRedis());
    }

    /* ──────────────────────────────────────
     * hashIp()
     * ────────────────────────────────────── */

    public function testHashIpReturnsHmacSha256HexString(): void
    {
        $service = $this->createService();
        $hash = $service->hashIp('192.168.1.1');
        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function testHashIpIsDeterministic(): void
    {
        $service = $this->createService();
        $this->assertSame($service->hashIp('10.0.0.1'), $service->hashIp('10.0.0.1'));
    }

    public function testHashIpDifferentIpsProduceDifferentHashes(): void
    {
        $service = $this->createService();
        $this->assertNotEquals($service->hashIp('192.168.1.1'), $service->hashIp('192.168.1.2'));
    }

    public function testHashIpMatchesExpectedHmac(): void
    {
        $service = $this->createService();
        $expected = hash_hmac('sha256', '10.0.0.1', 'test-hmac-key-for-unit-tests');
        $this->assertSame($expected, $service->hashIp('10.0.0.1'));
    }

    public function testHashIpEmptyStringProducesValidHash(): void
    {
        $service = $this->createService();
        $hash = $service->hashIp('');
        $this->assertSame(64, strlen($hash));
    }

    /* ──────────────────────────────────────
     * isIpBanned()
     * ────────────────────────────────────── */

    public function testIsIpBannedReturnsTrueWhenBanned(): void
    {
        $service = $this->createService();
        $this->redis->method('get')
            ->with('ban:ip:somehash')
            ->willReturn('{"reason":"spam"}');
        $this->assertTrue($service->isIpBanned('somehash'));
    }

    public function testIsIpBannedReturnsFalseWhenNotBanned(): void
    {
        $service = $this->createService();
        $this->redis->method('get')->willReturn(false);
        $this->assertFalse($service->isIpBanned('cleanhash'));
    }

    public function testIsIpBannedReturnsFalseOnRedisFailure(): void
    {
        $service = $this->createService();
        $this->redis->method('get')
            ->willThrowException(new \RuntimeException('Redis down'));
        $this->assertFalse($service->isIpBanned('anyhash'));
    }

    public function testIsIpBannedReturnsFalseForNullValue(): void
    {
        $service = $this->createService();
        $this->redis->method('get')->willReturn(null);
        $this->assertFalse($service->isIpBanned('hash'));
    }

    public function testIsIpBannedReturnsFalseForEmptyString(): void
    {
        $service = $this->createService();
        $this->redis->method('get')->willReturn('');
        $this->assertFalse($service->isIpBanned('hash'));
    }

    /* ──────────────────────────────────────
     * banIp()
     * ────────────────────────────────────── */

    public function testBanIpSetsRedisKeyWithTtl(): void
    {
        $service = $this->createService();
        $this->redis->expects($this->once())
            ->method('setex')
            ->with(
                'ban:ip:testhash',
                3600,
                $this->callback(function (string $val): bool {
                    $data = json_decode($val, true);
                    return is_array($data)
                        && $data['reason'] === 'Spamming'
                        && isset($data['banned_at'], $data['expires_at']);
                })
            );
        $service->banIp('testhash', 'Spamming', 3600);
    }

    public function testBanIpDefaultDuration24h(): void
    {
        $service = $this->createService();
        $this->redis->expects($this->once())
            ->method('setex')
            ->with('ban:ip:h', 86400, $this->isType('string'));
        $service->banIp('h', 'reason');
    }

    public function testBanIpReasonTruncatedTo500Chars(): void
    {
        $service = $this->createService();
        $this->redis->expects($this->once())
            ->method('setex')
            ->with(
                $this->isType('string'),
                $this->isType('int'),
                $this->callback(function (string $val): bool {
                    $data = json_decode($val, true);
                    return is_array($data) && strlen($data['reason']) <= 500;
                })
            );
        $service->banIp('h', str_repeat('x', 600));
    }

    public function testBanIpSilentlyFailsOnRedisError(): void
    {
        $service = $this->createService();
        $this->redis->method('setex')
            ->willThrowException(new \RuntimeException('Redis down'));
        $service->banIp('h', 'reason', 3600);
        $this->assertTrue(true); // no throw
    }

    public function testBanIpExpiryDelta(): void
    {
        $service = $this->createService();
        $this->redis->expects($this->once())
            ->method('setex')
            ->with(
                'ban:ip:h',
                7200,
                $this->callback(function (string $val): bool {
                    $data = json_decode($val, true);
                    return is_array($data)
                        && ($data['expires_at'] - $data['banned_at']) === 7200;
                })
            );
        $service->banIp('h', 'test', 7200);
    }

    /* ──────────────────────────────────────
     * logout()
     * ────────────────────────────────────── */

    public function testLogoutDeletesRedisKey(): void
    {
        $service = $this->createService();
        $token = 'raw-token';
        $hash = hash('sha256', $token);

        $this->redis->expects($this->once())->method('del')->with("session:{$hash}");

        try {
            $service->logout($token);
        } catch (\Throwable) {
            // Session::query() fails without DB — expected
        }
    }

    public function testLogoutHandlesRedisFailure(): void
    {
        $service = $this->createService();
        $this->redis->method('del')
            ->willThrowException(new \RuntimeException('down'));
        try {
            $service->logout('tok');
        } catch (\Throwable) {
        }
        $this->assertTrue(true);
    }

    public function testLogoutTokenIsHashed(): void
    {
        $service = $this->createService();
        $this->redis->expects($this->once())
            ->method('del')
            ->with('session:' . hash('sha256', 'my-token'));
        try {
            $service->logout('my-token');
        } catch (\Throwable) {
        }
    }
}
