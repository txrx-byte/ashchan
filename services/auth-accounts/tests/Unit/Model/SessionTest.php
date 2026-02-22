<?php
declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\Session;
use App\Tests\TestBootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Model\Session
 */
final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        TestBootstrap::init();
    }

    private function makeSession(string $expiresAt): Session
    {
        $session = new Session();
        $session->setAttribute('expires_at', $expiresAt);
        return $session;
    }

    /* ──────────────────────────────────────
     * isExpired()
     * ────────────────────────────────────── */

    public function testIsExpiredReturnsTrueForPastDate(): void
    {
        $this->assertTrue($this->makeSession('2020-01-01 00:00:00')->isExpired());
    }

    public function testIsExpiredReturnsFalseForFutureDate(): void
    {
        $session = $this->makeSession(date('Y-m-d H:i:s', time() + 86400));
        $this->assertFalse($session->isExpired());
    }

    public function testIsExpiredReturnsTrueForMalformedDate(): void
    {
        $this->assertTrue($this->makeSession('not-a-date')->isExpired());
    }

    public function testIsExpiredReturnsTrueForEmptyDate(): void
    {
        $this->assertTrue($this->makeSession('')->isExpired());
    }

    public function testIsExpiredReturnsFalseForFarFuture(): void
    {
        $this->assertFalse($this->makeSession('2099-12-31 23:59:59')->isExpired());
    }

    public function testIsExpiredReturnsTrueForJustExpired(): void
    {
        $this->assertTrue($this->makeSession(date('Y-m-d H:i:s', time() - 1))->isExpired());
    }

    public function testIsExpiredWithIso8601PastDate(): void
    {
        $this->assertTrue($this->makeSession('2020-01-01T00:00:00+00:00')->isExpired());
    }

    public function testIsExpiredWithIso8601FutureDate(): void
    {
        $this->assertFalse($this->makeSession('2099-12-31T23:59:59+00:00')->isExpired());
    }

    public function testIsExpiredWithTimezoneOffset(): void
    {
        $this->assertFalse($this->makeSession('2099-06-15T12:00:00-05:00')->isExpired());
    }

    /* ──────────────────────────────────────
     * Model configuration
     * ────────────────────────────────────── */

    public function testTableName(): void
    {
        $this->assertSame('sessions', (new Session())->getTable());
    }

    public function testTimestampsDisabled(): void
    {
        $this->assertFalse((new Session())->timestamps);
    }

    public function testFillableColumns(): void
    {
        $fillable = (new Session())->getFillable();
        foreach (['user_id', 'token', 'ip_address', 'user_agent', 'expires_at'] as $col) {
            $this->assertContains($col, $fillable);
        }
    }

    public function testCastsIdAndUserIdToInteger(): void
    {
        $casts = (new Session())->getCasts();
        $this->assertSame('integer', $casts['id']);
        $this->assertSame('integer', $casts['user_id']);
    }

    public function testIdAndTimestampsNotFillable(): void
    {
        $fillable = (new Session())->getFillable();
        $this->assertNotContains('id', $fillable);
        $this->assertNotContains('created_at', $fillable);
    }
}
