<?php
declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\Consent;
use App\Tests\TestBootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Model\Consent
 */
final class ConsentTest extends TestCase
{
    protected function setUp(): void
    {
        TestBootstrap::init();
    }

    public function testTableName(): void
    {
        $this->assertSame('consents', (new Consent())->getTable());
    }

    public function testTimestampsDisabled(): void
    {
        $this->assertFalse((new Consent())->timestamps);
    }

    public function testFillableColumns(): void
    {
        $fillable = (new Consent())->getFillable();
        foreach (['ip_hash', 'ip_encrypted', 'user_id', 'consent_type', 'policy_version', 'consented'] as $col) {
            $this->assertContains($col, $fillable);
        }
    }

    public function testCastsConfiguration(): void
    {
        $casts = (new Consent())->getCasts();
        $this->assertSame('integer', $casts['id']);
        $this->assertSame('integer', $casts['user_id']);
        $this->assertSame('boolean', $casts['consented']);
    }

    public function testSetAttributes(): void
    {
        $consent = new Consent();
        $consent->setAttribute('ip_hash', 'abc123');
        $consent->setAttribute('consent_type', 'privacy_policy');
        $consent->setAttribute('policy_version', '2.0');
        $consent->setAttribute('consented', true);
        $consent->setAttribute('user_id', null);

        $this->assertSame('abc123', $consent->getAttribute('ip_hash'));
        $this->assertSame('privacy_policy', $consent->getAttribute('consent_type'));
        $this->assertSame('2.0', $consent->getAttribute('policy_version'));
        $this->assertTrue($consent->getAttribute('consented'));
        $this->assertNull($consent->getAttribute('user_id'));
    }

    public function testIdNotFillable(): void
    {
        $this->assertNotContains('id', (new Consent())->getFillable());
    }

    public function testConsentedFalse(): void
    {
        $consent = new Consent();
        $consent->setAttribute('consented', false);
        $this->assertFalse($consent->getAttribute('consented'));
    }
}
