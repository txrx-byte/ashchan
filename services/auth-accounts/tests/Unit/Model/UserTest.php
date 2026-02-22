<?php
declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\User;
use App\Tests\TestBootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Model\User
 */
final class UserTest extends TestCase
{
    protected function setUp(): void
    {
        TestBootstrap::init();
    }

    /* ──────────────────────────────────────
     * Model configuration
     * ────────────────────────────────────── */

    public function testTableName(): void
    {
        $this->assertSame('users', (new User())->getTable());
    }

    public function testFillableColumns(): void
    {
        $fillable = (new User())->getFillable();
        foreach (['username', 'password_hash', 'email', 'role', 'banned', 'ban_reason', 'ban_expires_at'] as $col) {
            $this->assertContains($col, $fillable);
        }
    }

    public function testPasswordHashIsHidden(): void
    {
        $this->assertContains('password_hash', (new User())->getHidden());
    }

    public function testCastsIdToIntegerAndBannedToBoolean(): void
    {
        $casts = (new User())->getCasts();
        $this->assertSame('integer', $casts['id']);
        $this->assertSame('boolean', $casts['banned']);
    }

    /* ──────────────────────────────────────
     * Attribute access
     * ────────────────────────────────────── */

    public function testSetAndGetUsername(): void
    {
        $user = new User();
        $user->setAttribute('username', 'testuser');
        $this->assertSame('testuser', $user->getAttribute('username'));
    }

    public function testSetAndGetRole(): void
    {
        $user = new User();
        $user->setAttribute('role', 'admin');
        $this->assertSame('admin', $user->getAttribute('role'));
    }

    public function testSetAndGetEmail(): void
    {
        $user = new User();
        $user->setAttribute('email', 'a@b.com');
        $this->assertSame('a@b.com', $user->getAttribute('email'));
    }

    public function testBannedCastToBoolean(): void
    {
        $user = new User();
        $user->setAttribute('banned', true);
        $this->assertTrue($user->getAttribute('banned'));

        $user->setAttribute('banned', false);
        $this->assertFalse($user->getAttribute('banned'));
    }

    public function testBanReasonCanBeNull(): void
    {
        $user = new User();
        $user->setAttribute('ban_reason', null);
        $this->assertNull($user->getAttribute('ban_reason'));
    }

    public function testBanExpiresAtCanBeNull(): void
    {
        $user = new User();
        $user->setAttribute('ban_expires_at', null);
        $this->assertNull($user->getAttribute('ban_expires_at'));
    }

    /* ──────────────────────────────────────
     * Serialization security
     * ────────────────────────────────────── */

    public function testToArrayExcludesPasswordHash(): void
    {
        $user = new User();
        $user->setAttribute('username', 'admin');
        $user->setAttribute('password_hash', '$argon2id$v=19$...');
        $user->setAttribute('role', 'admin');

        $arr = $user->toArray();
        $this->assertArrayNotHasKey('password_hash', $arr);
        $this->assertArrayHasKey('username', $arr);
    }

    public function testToJsonExcludesPasswordHash(): void
    {
        $user = new User();
        $user->setAttribute('username', 'admin');
        $user->setAttribute('password_hash', '$argon2id$v=19$...');

        $json = $user->toJson();
        $this->assertStringNotContainsString('password_hash', $json);
        $this->assertStringNotContainsString('argon2id', $json);
    }

    /* ──────────────────────────────────────
     * Mass assignment guard
     * ────────────────────────────────────── */

    public function testIdAndTimestampsNotFillable(): void
    {
        $fillable = (new User())->getFillable();
        $this->assertNotContains('id', $fillable);
        $this->assertNotContains('created_at', $fillable);
        $this->assertNotContains('updated_at', $fillable);
    }
}
