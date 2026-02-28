<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SpamService;
use App\Service\SpurService;
use App\Service\StopForumSpamService;
use Hyperf\Redis\Redis;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Service\SpamService
 */
final class SpamServiceTest extends TestCase
{
    private MockInterface $redisMock;
    private MockInterface $sfsServiceMock;
    private MockInterface $spurServiceMock;
    private MockInterface $configMock;
    private SpamService $spamService;

    protected function setUp(): void
    {
        $this->redisMock = m::mock(Redis::class);
        $this->sfsServiceMock = m::mock(StopForumSpamService::class);
        $this->spurServiceMock = m::mock(SpurService::class);
        $this->configMock = m::mock(\App\Service\SiteConfigService::class);

        // Configure default config values
        $this->configMock
            ->shouldReceive('getInt')
            ->with('post_rate_window', 60)
            ->andReturn(60);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('post_rate_limit', 5)
            ->andReturn(5);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('thread_rate_limit', 1)
            ->andReturn(1);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('thread_rate_window', 300)
            ->andReturn(300);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('risk_threshold_high', 7)
            ->andReturn(7);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('risk_threshold_block', 10)
            ->andReturn(10);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('duplicate_fingerprint_ttl', 3600)
            ->andReturn(3600);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('captcha_ttl', 300)
            ->andReturn(300);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('captcha_length', 6)
            ->andReturn(6);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('ip_reputation_ttl', 86400)
            ->andReturn(86400);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('url_count_threshold', 3)
            ->andReturn(3);
        $this->configMock
            ->shouldReceive('getFloat')
            ->with('caps_ratio_threshold', 0.7)
            ->andReturn(0.7);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('excessive_length_threshold', 1500)
            ->andReturn(1500);

        $this->spamService = new SpamService(
            $this->redisMock,
            $this->sfsServiceMock,
            $this->spurServiceMock,
            $this->configMock
        );
    }

    protected function tearDown(): void
    {
        m::close();
    }

    public function testCheckReturnsAllowedWhenScoreIsLow(): void
    {
        $ipHash = 'abc123';
        $content = 'Normal post content';
        $realIp = '192.168.1.1';

        // Layer 0a: StopForumSpam - not blocked
        $this->sfsServiceMock
            ->shouldReceive('check')
            ->with($realIp)
            ->once()
            ->andReturn(false);

        // Layer 0b: Spur - not blocked, low score
        $this->spurServiceMock
            ->shouldReceive('evaluate')
            ->with($realIp)
            ->once()
            ->andReturn(['block' => false, 'score' => 0, 'reasons' => []]);

        // Layer 1: Rate limiting - not exceeded
        $this->redisMock
            ->shouldReceive('zRemRangeByScore')
            ->with('ratelimit:post:abc123', '-inf', time() - 60)
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zCard')
            ->with('ratelimit:post:abc123')
            ->andReturn(1);
        $this->redisMock
            ->shouldReceive('zAdd')
            ->with('ratelimit:post:abc123', m::type('integer'), m::type('string'))
            ->andReturn(true);
        $this->redisMock
            ->shouldReceive('expire')
            ->with('ratelimit:post:abc123', 60)
            ->andReturn(true);

        // Layer 2: Duplicate content - not duplicate
        $this->redisMock
            ->shouldReceive('exists')
            ->andReturn(false);
        $this->redisMock
            ->shouldReceive('setex')
            ->andReturn(true);

        // Layer 4: Image ban - not banned
        $this->redisMock
            ->shouldReceive('sIsMember')
            ->with('banned_images', m::any())
            ->andReturn(false);

        // Layer 5: IP reputation - clean
        $this->redisMock
            ->shouldReceive('get')
            ->with('ip_reputation:abc123')
            ->andReturn('0');

        // Record attempt
        $this->redisMock
            ->shouldReceive('incr')
            ->never();

        $result = $this->spamService->check($ipHash, $content, false, null, $realIp);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('allowed', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('captcha_required', $result);
        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['captcha_required']);
    }

    public function testCheckBlocksWhenStopForumSpamFlagsIP(): void
    {
        $ipHash = 'blocked_ip';
        $content = 'Test content';
        $realIp = '10.0.0.1';

        // Layer 0a: StopForumSpam - BLOCKED
        $this->sfsServiceMock
            ->shouldReceive('check')
            ->with($realIp)
            ->once()
            ->andReturn(true);

        // Layer 0b: Spur - not blocked
        $this->spurServiceMock
            ->shouldReceive('evaluate')
            ->with($realIp)
            ->andReturn(['block' => false, 'score' => 0, 'reasons' => []]);

        // Rate limiting - OK
        $this->redisMock
            ->shouldReceive('zRemRangeByScore')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zCard')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zAdd')
            ->andReturn(true);
        $this->redisMock
            ->shouldReceive('expire')
            ->andReturn(true);

        // Duplicate check
        $this->redisMock
            ->shouldReceive('exists')
            ->andReturn(false);
        $this->redisMock
            ->shouldReceive('setex')
            ->andReturn(true);

        // Image ban
        $this->redisMock
            ->shouldReceive('sIsMember')
            ->andReturn(false);

        // IP reputation
        $this->redisMock
            ->shouldReceive('get')
            ->andReturn('0');

        $result = $this->spamService->check($ipHash, $content, false, null, $realIp);

        $this->assertFalse($result['allowed']);
        $this->assertGreaterThanOrEqual(100, $result['score']);
        $this->assertContains('Blocked by StopForumSpam', $result['reasons']);
    }

    public function testCheckBlocksWhenSpurFlagsIPForBlock(): void
    {
        $ipHash = 'proxy_ip';
        $content = 'Test';
        $realIp = '203.0.113.50';

        $this->sfsServiceMock
            ->shouldReceive('check')
            ->andReturn(false);

        // Layer 0b: Spur - BLOCK
        $this->spurServiceMock
            ->shouldReceive('evaluate')
            ->with($realIp)
            ->andReturn([
                'block' => true,
                'score' => 15,
                'reasons' => ['VPN detected', 'BOTNET']
            ]);

        // Rate limiting
        $this->redisMock
            ->shouldReceive('zRemRangeByScore')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zCard')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zAdd')
            ->andReturn(true);
        $this->redisMock
            ->shouldReceive('expire')
            ->andReturn(true);

        // Duplicate
        $this->redisMock
            ->shouldReceive('exists')
            ->andReturn(false);
        $this->redisMock
            ->shouldReceive('setex')
            ->andReturn(true);

        // Image ban
        $this->redisMock
            ->shouldReceive('sIsMember')
            ->andReturn(false);

        // IP reputation
        $this->redisMock
            ->shouldReceive('get')
            ->andReturn('0');

        $result = $this->spamService->check($ipHash, $content, false, null, $realIp);

        $this->assertFalse($result['allowed']);
        $this->assertGreaterThanOrEqual(10, $result['score']);
    }

    public function testCheckRequiresCaptchaWhenScoreExceedsHighThreshold(): void
    {
        $ipHash = 'suspicious_ip';
        $content = 'BUY NOW!!! CLICK HERE!!! http://spam.com http://spam2.com http://spam3.com';
        $realIp = '198.51.100.1';

        $this->sfsServiceMock
            ->shouldReceive('check')
            ->andReturn(false);

        $this->spurServiceMock
            ->shouldReceive('evaluate')
            ->andReturn(['block' => false, 'score' => 2, 'reasons' => ['Datacenter IP']]);

        // Rate limiting - OK
        $this->redisMock
            ->shouldReceive('zRemRangeByScore')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zCard')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zAdd')
            ->andReturn(true);
        $this->redisMock
            ->shouldReceive('expire')
            ->andReturn(true);

        // Not duplicate
        $this->redisMock
            ->shouldReceive('exists')
            ->andReturn(false);
        $this->redisMock
            ->shouldReceive('setex')
            ->andReturn(true);

        // Image ban
        $this->redisMock
            ->shouldReceive('sIsMember')
            ->andReturn(false);

        // IP reputation - some negative history
        $this->redisMock
            ->shouldReceive('get')
            ->andReturn('3');

        $result = $this->spamService->check($ipHash, $content, false, null, $realIp);

        $this->assertTrue($result['allowed']); // Below block threshold
        $this->assertTrue($result['captcha_required']); // Above high threshold
    }

    public function testCheckDetectsDuplicateContent(): void
    {
        $ipHash = 'user123';
        $content = 'This is duplicate content that was posted before';
        $realIp = '192.0.2.1';

        $this->sfsServiceMock
            ->shouldReceive('check')
            ->andReturn(false);

        $this->spurServiceMock
            ->shouldReceive('evaluate')
            ->andReturn(['block' => false, 'score' => 0, 'reasons' => []]);

        // Rate limiting
        $this->redisMock
            ->shouldReceive('zRemRangeByScore')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zCard')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zAdd')
            ->andReturn(true);
        $this->redisMock
            ->shouldReceive('expire')
            ->andReturn(true);

        // Layer 2: DUPLICATE
        $this->redisMock
            ->shouldReceive('exists')
            ->with(m::contains('fingerprint:'))
            ->andReturn(true);

        // Image ban
        $this->redisMock
            ->shouldReceive('sIsMember')
            ->andReturn(false);

        // IP reputation
        $this->redisMock
            ->shouldReceive('get')
            ->andReturn('0');

        $result = $this->spamService->check($ipHash, $content, false, null, $realIp);

        $this->assertContains('Duplicate content detected', $result['reasons']);
    }

    public function testCheckDetectsBannedImage(): void
    {
        $ipHash = 'img_user';
        $content = 'Test post';
        $imageHash = 'banned_md5_hash';
        $realIp = '192.0.2.5';

        $this->sfsServiceMock
            ->shouldReceive('check')
            ->andReturn(false);

        $this->spurServiceMock
            ->shouldReceive('evaluate')
            ->andReturn(['block' => false, 'score' => 0, 'reasons' => []]);

        // Rate limiting
        $this->redisMock
            ->shouldReceive('zRemRangeByScore')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zCard')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zAdd')
            ->andReturn(true);
        $this->redisMock
            ->shouldReceive('expire')
            ->andReturn(true);

        // Not duplicate
        $this->redisMock
            ->shouldReceive('exists')
            ->andReturn(false);
        $this->redisMock
            ->shouldReceive('setex')
            ->andReturn(true);

        // Layer 4: BANNED IMAGE
        $this->redisMock
            ->shouldReceive('sIsMember')
            ->with('banned_images', $imageHash)
            ->andReturn(true);

        // IP reputation
        $this->redisMock
            ->shouldReceive('get')
            ->andReturn('0');

        $result = $this->spamService->check($ipHash, $content, false, $imageHash, $realIp);

        $this->assertContains('Banned image hash', $result['reasons']);
    }

    public function testCheckEnforcesPostRateLimit(): void
    {
        $ipHash = 'spammy_user';
        $content = 'Another post';
        $realIp = '198.51.100.100';

        $this->sfsServiceMock
            ->shouldReceive('check')
            ->andReturn(false);

        $this->spurServiceMock
            ->shouldReceive('evaluate')
            ->andReturn(['block' => false, 'score' => 0, 'reasons' => []]);

        // Layer 1: RATE LIMIT EXCEEDED
        $this->redisMock
            ->shouldReceive('zRemRangeByScore')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zCard')
            ->with('ratelimit:post:spammy_user')
            ->andReturn(6); // Exceeds limit of 5

        // Duplicate
        $this->redisMock
            ->shouldReceive('exists')
            ->andReturn(false);
        $this->redisMock
            ->shouldReceive('setex')
            ->andReturn(true);

        // Image ban
        $this->redisMock
            ->shouldReceive('sIsMember')
            ->andReturn(false);

        // IP reputation
        $this->redisMock
            ->shouldReceive('get')
            ->andReturn('0');

        $result = $this->spamService->check($ipHash, $content, false, null, $realIp);

        $this->assertContainsString('Post rate limit exceeded', implode(' ', $result['reasons']));
    }

    public function testCheckEnforcesThreadRateLimit(): void
    {
        $ipHash = 'thread_spammer';
        $content = 'New thread';
        $realIp = '203.0.113.10';

        $this->sfsServiceMock
            ->shouldReceive('check')
            ->andReturn(false);

        $this->spurServiceMock
            ->shouldReceive('evaluate')
            ->andReturn(['block' => false, 'score' => 0, 'reasons' => []]);

        // Post rate - OK
        $this->redisMock
            ->shouldReceive('zRemRangeByScore')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zCard')
            ->with('ratelimit:post:thread_spammer')
            ->andReturn(1);

        // Thread rate - EXCEEDED
        $this->redisMock
            ->shouldReceive('zCard')
            ->with('ratelimit:thread:thread_spammer')
            ->andReturn(1); // Exceeds limit of 1

        $this->redisMock
            ->shouldReceive('zAdd')
            ->with('ratelimit:post:*', m::any(), m::any())
            ->andReturn(true);
        $this->redisMock
            ->shouldReceive('expire')
            ->with('ratelimit:post:*', 60)
            ->andReturn(true);

        // Duplicate
        $this->redisMock
            ->shouldReceive('exists')
            ->andReturn(false);
        $this->redisMock
            ->shouldReceive('setex')
            ->andReturn(true);

        // Image ban
        $this->redisMock
            ->shouldReceive('sIsMember')
            ->andReturn(false);

        // IP reputation
        $this->redisMock
            ->shouldReceive('get')
            ->andReturn('0');

        $result = $this->spamService->check($ipHash, $content, true, null, $realIp);

        $this->assertContainsString('Thread creation rate limit exceeded', implode(' ', $result['reasons']));
    }

    public function testContentScoringPenalizesExcessiveURLs(): void
    {
        $ipHash = 'url_spammer';
        $content = 'Check out http://a.com http://b.com http://c.com http://d.com';
        $realIp = '192.0.2.10';

        $this->sfsServiceMock
            ->shouldReceive('check')
            ->andReturn(false);

        $this->spurServiceMock
            ->shouldReceive('evaluate')
            ->andReturn(['block' => false, 'score' => 0, 'reasons' => []]);

        // Rate limiting
        $this->redisMock
            ->shouldReceive('zRemRangeByScore')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zCard')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zAdd')
            ->andReturn(true);
        $this->redisMock
            ->shouldReceive('expire')
            ->andReturn(true);

        // Not duplicate
        $this->redisMock
            ->shouldReceive('exists')
            ->andReturn(false);
        $this->redisMock
            ->shouldReceive('setex')
            ->andReturn(true);

        // Image ban
        $this->redisMock
            ->shouldReceive('sIsMember')
            ->andReturn(false);

        // IP reputation
        $this->redisMock
            ->shouldReceive('get')
            ->andReturn('0');

        $result = $this->spamService->check($ipHash, $content, false, null, $realIp);

        $this->assertContainsString('Content risk', implode(' ', $result['reasons']));
    }

    public function testContentScoringPenalizesExcessiveCaps(): void
    {
        $ipHash = 'caps_user';
        $content = 'THIS IS ALL CAPS AND SHOULD BE PENALIZED ACCORDINGLY';
        $realIp = '192.0.2.20';

        $this->sfsServiceMock
            ->shouldReceive('check')
            ->andReturn(false);

        $this->spurServiceMock
            ->shouldReceive('evaluate')
            ->andReturn(['block' => false, 'score' => 0, 'reasons' => []]);

        // Rate limiting
        $this->redisMock
            ->shouldReceive('zRemRangeByScore')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zCard')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zAdd')
            ->andReturn(true);
        $this->redisMock
            ->shouldReceive('expire')
            ->andReturn(true);

        // Not duplicate
        $this->redisMock
            ->shouldReceive('exists')
            ->andReturn(false);
        $this->redisMock
            ->shouldReceive('setex')
            ->andReturn(true);

        // Image ban
        $this->redisMock
            ->shouldReceive('sIsMember')
            ->andReturn(false);

        // IP reputation
        $this->redisMock
            ->shouldReceive('get')
            ->andReturn('0');

        $result = $this->spamService->check($ipHash, $content, false, null, $realIp);

        $this->assertContainsString('Content risk', implode(' ', $result['reasons']));
    }

    public function testContentScoringPenalizesRepeatedCharacters(): void
    {
        $ipHash = 'repeat_user';
        $content = 'Aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $realIp = '192.0.2.30';

        $this->sfsServiceMock
            ->shouldReceive('check')
            ->andReturn(false);

        $this->spurServiceMock
            ->shouldReceive('evaluate')
            ->andReturn(['block' => false, 'score' => 0, 'reasons' => []]);

        // Rate limiting
        $this->redisMock
            ->shouldReceive('zRemRangeByScore')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zCard')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zAdd')
            ->andReturn(true);
        $this->redisMock
            ->shouldReceive('expire')
            ->andReturn(true);

        // Not duplicate
        $this->redisMock
            ->shouldReceive('exists')
            ->andReturn(false);
        $this->redisMock
            ->shouldReceive('setex')
            ->andReturn(true);

        // Image ban
        $this->redisMock
            ->shouldReceive('sIsMember')
            ->andReturn(false);

        // IP reputation
        $this->redisMock
            ->shouldReceive('get')
            ->andReturn('0');

        $result = $this->spamService->check($ipHash, $content, false, null, $realIp);

        $this->assertContainsString('Content risk', implode(' ', $result['reasons']));
    }

    public function testGenerateCaptchaReturnsTokenAndAnswer(): void
    {
        $this->redisMock
            ->shouldReceive('setex')
            ->with('captcha:' . m::type('string'), 300, m::type('string'))
            ->andReturn(true);

        $result = $this->spamService->generateCaptcha();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('answer', $result);
        $this->assertIsString($result['token']);
        $this->assertIsString($result['answer']);
        $this->assertEquals(6, strlen($result['answer']));
        $this->assertMatchesRegularExpression('/^[A-Z2-9]+$/', $result['answer']);
    }

    public function testVerifyCaptchaReturnsTrueForCorrectAnswer(): void
    {
        $token = 'abc123token';
        $answer = 'ABC123';

        $this->redisMock
            ->shouldReceive('get')
            ->with('captcha:' . $token)
            ->andReturn($answer);
        $this->redisMock
            ->shouldReceive('del')
            ->with('captcha:' . $token)
            ->andReturn(1);

        $result = $this->spamService->verifyCaptcha($token, $answer);
        $this->assertTrue($result);
    }

    public function testVerifyCaptchaReturnsFalseForWrongAnswer(): void
    {
        $token = 'wrong_token';
        $expected = 'CORRECT';
        $provided = 'WRONG';

        $this->redisMock
            ->shouldReceive('get')
            ->with('captcha:' . $token)
            ->andReturn($expected);
        $this->redisMock
            ->shouldReceive('del')
            ->with('captcha:' . $token)
            ->andReturn(1);

        $result = $this->spamService->verifyCaptcha($token, $provided);
        $this->assertFalse($result);
    }

    public function testVerifyCaptchaReturnsFalseForExpiredToken(): void
    {
        $token = 'expired_token';

        $this->redisMock
            ->shouldReceive('get')
            ->with('captcha:' . $token)
            ->andReturn(false);

        $result = $this->spamService->verifyCaptcha($token, 'ANY');
        $this->assertFalse($result);
    }

    public function testVerifyCaptchaIsOneTimeUse(): void
    {
        $token = 'once_token';
        $answer = 'ONCE12';

        $this->redisMock
            ->shouldReceive('get')
            ->with('captcha:' . $token)
            ->andReturn($answer);
        $this->redisMock
            ->shouldReceive('del')
            ->with('captcha:' . $token)
            ->andReturn(1);

        // First use - success
        $result1 = $this->spamService->verifyCaptcha($token, $answer);
        $this->assertTrue($result1);

        // Token should be deleted after first use
        $this->redisMock
            ->shouldReceive('get')
            ->with('captcha:' . $token)
            ->andReturn(false);

        $result2 = $this->spamService->verifyCaptcha($token, $answer);
        $this->assertFalse($result2);
    }

    public function testBanImageAddsToSet(): void
    {
        $hash = 'new_banned_hash';

        $this->redisMock
            ->shouldReceive('sAdd')
            ->with('banned_images', $hash)
            ->andReturn(1);

        $this->spamService->banImage($hash);
        // Test passes if no exception thrown
    }

    public function testCheckWithoutRealIpSkipsExternalServices(): void
    {
        $ipHash = 'no_real_ip';
        $content = 'Test';

        // Should NOT call SFS or Spur without real IP
        $this->sfsServiceMock
            ->shouldReceive('check')
            ->never();

        $this->spurServiceMock
            ->shouldReceive('evaluate')
            ->never();

        // Rate limiting
        $this->redisMock
            ->shouldReceive('zRemRangeByScore')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zCard')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zAdd')
            ->andReturn(true);
        $this->redisMock
            ->shouldReceive('expire')
            ->andReturn(true);

        // Duplicate
        $this->redisMock
            ->shouldReceive('exists')
            ->andReturn(false);
        $this->redisMock
            ->shouldReceive('setex')
            ->andReturn(true);

        // Image ban
        $this->redisMock
            ->shouldReceive('sIsMember')
            ->andReturn(false);

        // IP reputation
        $this->redisMock
            ->shouldReceive('get')
            ->andReturn('0');

        $result = $this->spamService->check($ipHash, $content, false, null, null);

        $this->assertIsArray($result);
    }

    public function testCheckIncrementsIpReputationForHighScore(): void
    {
        $ipHash = 'bad_reputation';
        // Content that will score high
        $content = 'http://a.com http://b.com http://c.com http://d.com http://e.com';
        $realIp = '192.0.2.50';

        $this->sfsServiceMock
            ->shouldReceive('check')
            ->andReturn(false);

        $this->spurServiceMock
            ->shouldReceive('evaluate')
            ->andReturn(['block' => false, 'score' => 5, 'reasons' => ['Proxy']]);

        // Rate limiting
        $this->redisMock
            ->shouldReceive('zRemRangeByScore')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zCard')
            ->andReturn(0);
        $this->redisMock
            ->shouldReceive('zAdd')
            ->andReturn(true);
        $this->redisMock
            ->shouldReceive('expire')
            ->andReturn(true);

        // Not duplicate
        $this->redisMock
            ->shouldReceive('exists')
            ->andReturn(false);
        $this->redisMock
            ->shouldReceive('setex')
            ->andReturn(true);

        // Image ban
        $this->redisMock
            ->shouldReceive('sIsMember')
            ->andReturn(false);

        // Current reputation
        $this->redisMock
            ->shouldReceive('get')
            ->with('ip_reputation:bad_reputation')
            ->andReturn('0');

        // Should increment reputation due to high score
        $this->redisMock
            ->shouldReceive('incr')
            ->with('ip_reputation:bad_reputation')
            ->andReturn(1);
        $this->redisMock
            ->shouldReceive('expire')
            ->with('ip_reputation:bad_reputation', 86400)
            ->andReturn(true);

        $result = $this->spamService->check($ipHash, $content, false, null, $realIp);

        $this->assertIsArray($result);
    }

    /**
     * Helper assertion for string containment
     */
    private function assertContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertStringContainsString($needle, $haystack, $message);
    }
}
