<?php
declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\BannedUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Model\BannedUser
 */
final class BannedUserTest extends TestCase
{
    private BannedUser $bannedUser;

    protected function setUp(): void
    {
        $this->bannedUser = new BannedUser();
    }

    public function testFillableProperties(): void
    {
        $expected = [
            'board', 'global', 'zonly', 'name', 'host', 'host_hash', 'reverse',
            'xff', 'reason', 'length', 'now', 'admin', 'md5', 'post_num', 'rule',
            'post_time', 'template_id', 'password', 'pass_id', 'post_json',
            'admin_ip', 'active', 'appealable', 'unbannedon', 'ban_reason'
        ];

        $this->assertEquals($expected, $this->bannedUser->getFillable());
    }

    public function testCastsFieldsCorrectly(): void
    {
        $casts = $this->bannedUser->getCasts();

        $this->assertArrayHasKey('id', $casts);
        $this->assertEquals('integer', $casts['id']);

        $this->assertArrayHasKey('global', $casts);
        $this->assertEquals('integer', $casts['global']);

        $this->assertArrayHasKey('zonly', $casts);
        $this->assertEquals('integer', $casts['zonly']);

        $this->assertArrayHasKey('post_num', $casts);
        $this->assertEquals('integer', $casts['post_num']);

        $this->assertArrayHasKey('template_id', $casts);
        $this->assertEquals('integer', $casts['template_id']);

        $this->assertArrayHasKey('active', $casts);
        $this->assertEquals('integer', $casts['active']);

        $this->assertArrayHasKey('appealable', $casts);
        $this->assertEquals('integer', $casts['appealable']);

        $this->assertArrayHasKey('now', $casts);
        $this->assertEquals('datetime', $casts['now']);

        $this->assertArrayHasKey('length', $casts);
        $this->assertEquals('datetime', $casts['length']);

        $this->assertArrayHasKey('unbannedon', $casts);
        $this->assertEquals('datetime', $casts['unbannedon']);
    }

    public function testTypeConstants(): void
    {
        $this->assertEquals('local', BannedUser::TYPE_LOCAL);
        $this->assertEquals('global', BannedUser::TYPE_GLOBAL);
        $this->assertEquals('zonly', BannedUser::TYPE_ZONLY);
    }

    public function testIsGlobalReturnsTrueWhenGlobalBan(): void
    {
        $this->bannedUser->global = 1;
        $this->assertTrue($this->bannedUser->isGlobal());
    }

    public function testIsGlobalReturnsFalseWhenLocalBan(): void
    {
        $this->bannedUser->global = 0;
        $this->assertFalse($this->bannedUser->isGlobal());
    }

    public function testIsZonlyReturnsTrueWhenUnappealable(): void
    {
        $this->bannedUser->zonly = 1;
        $this->assertTrue($this->bannedUser->isZonly());
    }

    public function testIsZonlyReturnsFalseWhenAppealable(): void
    {
        $this->bannedUser->zonly = 0;
        $this->assertFalse($this->bannedUser->isZonly());
    }

    public function testIsActiveReturnsTrueWhenActive(): void
    {
        $this->bannedUser->active = 1;
        $this->assertTrue($this->bannedUser->isActive());
    }

    public function testIsActiveReturnsFalseWhenInactive(): void
    {
        $this->bannedUser->active = 0;
        $this->assertFalse($this->bannedUser->isActive());
    }

    public function testIsAppealableReturnsTrueWhenAllowed(): void
    {
        $this->bannedUser->appealable = 1;
        $this->assertTrue($this->bannedUser->isAppealable());
    }

    public function testIsAppealableReturnsFalseWhenNotAllowed(): void
    {
        $this->bannedUser->appealable = 0;
        $this->assertFalse($this->bannedUser->isAppealable());
    }

    public function testIsExpiredReturnsTrueWhenUnbannedonIsPast(): void
    {
        $this->bannedUser->unbannedon = new \DateTime('-1 day');
        $this->assertTrue($this->bannedUser->isExpired());
    }

    public function testIsExpiredReturnsFalseWhenUnbannedonIsFuture(): void
    {
        $this->bannedUser->unbannedon = new \DateTime('+1 day');
        $this->assertFalse($this->bannedUser->isExpired());
    }

    public function testIsExpiredReturnsFalseForPermanentBan(): void
    {
        $this->bannedUser->unbannedon = null;
        $this->bannedUser->zonly = 1;
        $this->assertFalse($this->bannedUser->isExpired());
    }

    public function testIsWarningReturnsTrueWhenNoBanLength(): void
    {
        $this->bannedUser->length = null;
        $this->assertTrue($this->bannedUser->isWarning());
    }

    public function testIsWarningReturnsFalseWhenHasBanLength(): void
    {
        $this->bannedUser->length = new \DateTime('+1 day');
        $this->assertFalse($this->bannedUser->isWarning());
    }

    public function testGetRemainingSecondsReturnsZeroForExpired(): void
    {
        $this->bannedUser->unbannedon = new \DateTime('-1 day');
        $this->assertEquals(0, $this->bannedUser->getRemainingSeconds());
    }

    public function testGetRemainingSecondsReturnsPositiveForActive(): void
    {
        $future = new \DateTime('+1 day');
        $this->bannedUser->unbannedon = $future;

        $remaining = $this->bannedUser->getRemainingSeconds();
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(86400, $remaining); // Should be around 1 day
    }

    public function testGetRemainingSecondsReturnsZeroForNull(): void
    {
        $this->bannedUser->unbannedon = null;
        $this->assertEquals(0, $this->bannedUser->getRemainingSeconds());
    }

    public function testGetSummaryReturnsArray(): void
    {
        $this->bannedUser->board = 'g';
        $this->bannedUser->reason = 'Test ban';
        $this->bannedUser->now = new \DateTime();

        $result = $this->bannedUser->getSummary();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('board', $result);
        $this->assertArrayHasKey('reason', $result);
    }

    public function testHydrateAttributes(): void
    {
        $data = [
            'board' => 'g',
            'global' => 1,
            'zonly' => 0,
            'name' => 'Anonymous',
            'host' => '192.168.1.1',
            'reason' => 'Test ban reason',
            'now' => '2024-01-01 12:00:00',
            'length' => '2024-01-02 12:00:00',
            'admin' => 'mod',
            'post_num' => 12345,
            'rule' => 'global1',
            'template_id' => 1,
            'active' => 1,
            'appealable' => 1
        ];

        $this->bannedUser->fill($data);

        $this->assertEquals('g', $this->bannedUser->board);
        $this->assertEquals(1, $this->bannedUser->global);
        $this->assertEquals(0, $this->bannedUser->zonly);
        $this->assertEquals('Test ban reason', $this->bannedUser->reason);
        $this->assertEquals(12345, $this->bannedUser->post_num);
    }

    public function testActiveScope(): void
    {
        $query = $this->bannedUser->newQuery();
        $scopedQuery = $query->active();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testForBoardScope(): void
    {
        $query = $this->bannedUser->newQuery();
        $scopedQuery = $query->forBoard('g');

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testGlobalScope(): void
    {
        $query = $this->bannedUser->newQuery();
        $scopedQuery = $query->global();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testByIpScope(): void
    {
        $query = $this->bannedUser->newQuery();
        $scopedQuery = $query->byIp('192.168.1.1');

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testByPassIdScope(): void
    {
        $query = $this->bannedUser->newQuery();
        $scopedQuery = $query->byPassId('pass123');

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testExpiredScope(): void
    {
        $query = $this->bannedUser->newQuery();
        $scopedQuery = $query->expired();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testNotExpiredScope(): void
    {
        $query = $this->bannedUser->newQuery();
        $scopedQuery = $query->notExpired();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testZonlyScope(): void
    {
        $query = $this->bannedUser->newQuery();
        $scopedQuery = $query->zonly();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testGetBanType(): void
    {
        $this->bannedUser->global = 1;
        $this->assertEquals('global', $this->bannedUser->getBanType());

        $this->bannedUser->global = 0;
        $this->bannedUser->zonly = 1;
        $this->assertEquals('zonly', $this->bannedUser->getBanType());

        $this->bannedUser->global = 0;
        $this->bannedUser->zonly = 0;
        $this->assertEquals('local', $this->bannedUser->getBanType());
    }

    public function testGetBoardName(): void
    {
        $this->bannedUser->board = 'g';
        $this->assertEquals('g', $this->bannedUser->getBoardName());
    }

    public function testGetAdminName(): void
    {
        $this->bannedUser->admin = 'mod';
        $this->assertEquals('mod', $this->bannedUser->getAdminName());
    }

    public function testGetPostNumber(): void
    {
        $this->bannedUser->post_num = 12345;
        $this->assertEquals(12345, $this->bannedUser->getPostNumber());
    }

    public function testGetRuleId(): void
    {
        $this->bannedUser->rule = 'global1';
        $this->assertEquals('global1', $this->bannedUser->getRuleId());
    }

    public function testGetTemplateId(): void
    {
        $this->bannedUser->template_id = 5;
        $this->assertEquals(5, $this->bannedUser->getTemplateId());
    }

    public function testGetReason(): void
    {
        $this->bannedUser->reason = 'Ban reason';
        $this->assertEquals('Ban reason', $this->bannedUser->getReason());
    }

    public function testGetBanReason(): void
    {
        $this->bannedUser->ban_reason = 'Detailed reason';
        $this->assertEquals('Detailed reason', $this->bannedUser->getBanReason());
    }

    public function testGetHost(): void
    {
        $this->bannedUser->host = '192.168.1.1';
        $this->assertEquals('192.168.1.1', $this->bannedUser->getHost());
    }

    public function testGetHostHash(): void
    {
        $this->bannedUser->host_hash = 'abc123hash';
        $this->assertEquals('abc123hash', $this->bannedUser->getHostHash());
    }

    public function testGetXff(): void
    {
        $this->bannedUser->xff = '10.0.0.1';
        $this->assertEquals('10.0.0.1', $this->bannedUser->getXff());
    }

    public function testGetReverse(): void
    {
        $this->bannedUser->reverse = 'host.example.com';
        $this->assertEquals('host.example.com', $this->bannedUser->getReverse());
    }

    public function testGetName(): void
    {
        $this->bannedUser->name = 'Anonymous';
        $this->assertEquals('Anonymous', $this->bannedUser->getName());
    }

    public function testGetPassword(): void
    {
        $this->bannedUser->password = 'pass123';
        $this->assertEquals('pass123', $this->bannedUser->getPassword());
    }

    public function testGetPassId(): void
    {
        $this->bannedUser->pass_id = 'pass_id_123';
        $this->assertEquals('pass_id_123', $this->bannedUser->getPassId());
    }

    public function testGetPostJson(): void
    {
        $this->bannedUser->post_json = '{"name":"Anonymous"}';
        $result = $this->bannedUser->getPostJson();
        $this->assertIsArray($result);
        $this->assertEquals('Anonymous', $result['name']);
    }

    public function testGetPostJsonReturnsEmptyArrayForInvalidJson(): void
    {
        $this->bannedUser->post_json = 'invalid';
        $result = $this->bannedUser->getPostJson();
        $this->assertEquals([], $result);
    }

    public function testGetPostJsonReturnsEmptyArrayForNull(): void
    {
        $this->bannedUser->post_json = null;
        $result = $this->bannedUser->getPostJson();
        $this->assertEquals([], $result);
    }

    public function testGetStartTime(): void
    {
        $now = new \DateTime();
        $this->bannedUser->now = $now;
        $this->assertEquals($now, $this->bannedUser->getStartTime());
    }

    public function testGetEndTime(): void
    {
        $future = new \DateTime('+1 day');
        $this->bannedUser->length = $future;
        $this->assertEquals($future, $this->bannedUser->getEndTime());
    }

    public function testGetUnbannedOn(): void
    {
        $future = new \DateTime('+1 day');
        $this->bannedUser->unbannedon = $future;
        $this->assertEquals($future, $this->bannedUser->getUnbannedOn());
    }
}
