<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SpamService;
use App\Service\SiteConfigService;
use App\Tests\Stub\RedisStub;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Service\SpamService
 */
final class SpamServiceTest extends TestCase
{
    private RedisStub $redis;
    private SpamService $service;

    protected function setUp(): void
    {
        $this->redis = new RedisStub();
        $config = $this->createMock(SiteConfigService::class);

        // Return defaults for all config calls
        $config->method('getInt')->willReturnCallback(function (string $key, int $default) {
            return $default;
        });
        $config->method('getFloat')->willReturnCallback(function (string $key, float $default) {
            return $default;
        });

        $this->service = new SpamService($this->redis, $config);
    }

    /* ──────────────────────────────────────
     * generateCaptcha()
     * ────────────────────────────────────── */

    public function testGenerateCaptchaReturnsTokenAndQuestion(): void
    {
        $result = $this->service->generateCaptcha();

        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('question', $result);
        $this->assertNotEmpty($result['token']);
        $this->assertStringContainsString(' + ', $result['question']);
        $this->assertStringContainsString(' = ?', $result['question']);
    }

    public function testGenerateCaptchaTokenIsHex(): void
    {
        $result = $this->service->generateCaptcha();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result['token']);
    }

    public function testGenerateCaptchaStoresAnswerInRedis(): void
    {
        $result = $this->service->generateCaptcha();
        $storedAnswer = $this->redis->get('captcha:' . $result['token']);
        $this->assertNotFalse($storedAnswer);

        preg_match('/(\d+) \+ (\d+)/', $result['question'], $matches);
        $expected = (int) $matches[1] + (int) $matches[2];
        $this->assertSame((string) $expected, $storedAnswer);
    }

    /* ──────────────────────────────────────
     * verifyCaptcha()
     * ────────────────────────────────────── */

    public function testVerifyCaptchaWithCorrectAnswer(): void
    {
        $this->redis->_set('captcha:test-token', '42');
        $this->assertTrue($this->service->verifyCaptcha('test-token', '42'));
    }

    public function testVerifyCaptchaDeletesTokenAfterUse(): void
    {
        $this->redis->_set('captcha:test-token', '42');
        $this->service->verifyCaptcha('test-token', '42');
        $this->assertFalse($this->redis->get('captcha:test-token'));
    }

    public function testVerifyCaptchaWithWrongAnswer(): void
    {
        $this->redis->_set('captcha:test-token', '42');
        $this->assertFalse($this->service->verifyCaptcha('test-token', '99'));
    }

    public function testVerifyCaptchaWithExpiredToken(): void
    {
        $this->assertFalse($this->service->verifyCaptcha('expired-token', '42'));
    }

    public function testVerifyCaptchaWithEmptyToken(): void
    {
        $this->assertFalse($this->service->verifyCaptcha('', '42'));
    }

    public function testVerifyCaptchaWithEmptyResponse(): void
    {
        $this->assertFalse($this->service->verifyCaptcha('token', ''));
    }

    /* ──────────────────────────────────────
     * check() – clean content
     * ────────────────────────────────────── */

    public function testCheckCleanContentPasses(): void
    {
        $result = $this->service->check('iphash123', 'Hello world, this is a normal post.');

        $this->assertFalse($result['is_spam']);
        $this->assertSame('OK', $result['message']);
        $this->assertSame(0.0, $result['score']);
    }

    /* ──────────────────────────────────────
     * check() – URL detection
     * ────────────────────────────────────── */

    public function testCheckExcessiveUrlsFlagged(): void
    {
        $content = 'Check out http://a.com http://b.com http://c.com http://d.com http://e.com';
        $result = $this->service->check('iphash456', $content);

        $this->assertGreaterThan(0, $result['score']);
    }

    /* ──────────────────────────────────────
     * check() – rate limiting
     * ────────────────────────────────────── */

    public function testCheckRateLimitExceededBlocks(): void
    {
        $ipHash = 'ratelimited-ip';

        // Simulate 5 prior posts (hitting the default limit of 5)
        for ($i = 0; $i < 5; $i++) {
            $this->redis->_zAdd("ratelimit:post:{$ipHash}", (float) time(), time() . ':' . $i);
        }

        $result = $this->service->check($ipHash, 'another post');

        $this->assertTrue($result['is_spam']);
        $this->assertStringContainsString('rate limit', strtolower($result['message']));
    }

    /* ──────────────────────────────────────
     * check() – banned image hash
     * ────────────────────────────────────── */

    public function testCheckBannedImageHashBlocks(): void
    {
        $this->redis->_sAdd('banned_images', 'banned-hash-123');

        $result = $this->service->check('iphash789', 'normal content', false, 'banned-hash-123');

        $this->assertTrue($result['is_spam']);
        $this->assertStringContainsString('Banned image', $result['message']);
    }

    /* ──────────────────────────────────────
     * check() – repeated characters
     * ────────────────────────────────────── */

    public function testCheckRepeatedCharactersFlagged(): void
    {
        $content = 'aaaaaaaaaa normal text';
        $result = $this->service->check('iphash-rep', $content);

        $this->assertGreaterThan(0, $result['score']);
    }

    /* ──────────────────────────────────────
     * check() – duplicate content
     * ────────────────────────────────────── */

    public function testCheckDuplicateContentFlagged(): void
    {
        $content = 'This is duplicate content that is long enough to fingerprint.';

        // First post should be fine
        $result1 = $this->service->check('iphash-dup1', $content);
        $this->assertSame('OK', $result1['message']);
        $this->assertSame(0.0, $result1['score']);

        // Second identical post should accumulate a duplicate penalty
        $result2 = $this->service->check('iphash-dup2', $content);
        $this->assertGreaterThan(0, $result2['score']);
        // Duplicate alone (4.0) is below the blocking threshold (10), so not blocked
        // but the score increases proving detection worked
        $this->assertGreaterThanOrEqual(4.0, $result2['score']);
    }

    /* ──────────────────────────────────────
     * check() – IP reputation
     * ────────────────────────────────────── */

    public function testCheckIpReputationPenaltyApplied(): void
    {
        $ipHash = 'bad-rep-ip';
        $this->redis->_set("ip_reputation:{$ipHash}", '5');

        $result = $this->service->check($ipHash, 'normal post content');

        $this->assertGreaterThanOrEqual(5.0, $result['score']);
    }

    /* ──────────────────────────────────────
     * check() – thread rate limiting
     * ────────────────────────────────────── */

    public function testCheckThreadRateLimitExceeded(): void
    {
        $ipHash = 'thread-limited-ip';

        // Simulate 1 prior thread creation (default limit is 1)
        $this->redis->_zAdd("ratelimit:thread:{$ipHash}", (float) time(), (string) time());

        $result = $this->service->check($ipHash, 'new thread content', true);

        $this->assertTrue($result['is_spam']);
        $this->assertStringContainsString('Thread creation rate limit', $result['message']);
    }

    /* ──────────────────────────────────────
     * check() – excessive caps
     * ────────────────────────────────────── */

    public function testCheckExcessiveCapsDetected(): void
    {
        $content = 'THIS IS ALL CAPS TEXT THAT SHOULD BE FLAGGED BY THE FILTER';
        $result = $this->service->check('iphash-caps', $content);

        $this->assertGreaterThan(0, $result['score']);
    }
}
