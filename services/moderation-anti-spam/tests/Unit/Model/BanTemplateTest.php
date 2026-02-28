<?php
declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\BanTemplate;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Model\BanTemplate
 */
final class BanTemplateTest extends TestCase
{
    private BanTemplate $template;

    protected function setUp(): void
    {
        $this->template = new BanTemplate();
    }

    public function testFillableProperties(): void
    {
        $expected = [
            'rule', 'name', 'ban_type', 'ban_days', 'banlen', 'can_warn', 
            'publicban', 'is_public', 'public_reason', 'private_reason', 
            'action', 'save_type', 'blacklist_image', 'reject_image', 
            'access', 'boards', 'exclude', 'appealable', 'active'
        ];

        $this->assertEquals($expected, $this->template->getFillable());
    }

    public function testCastsIntegerFields(): void
    {
        $casts = $this->template->getCasts();

        $this->assertArrayHasKey('id', $casts);
        $this->assertEquals('integer', $casts['id']);

        $this->assertArrayHasKey('ban_days', $casts);
        $this->assertEquals('integer', $casts['ban_days']);

        $this->assertArrayHasKey('can_warn', $casts);
        $this->assertEquals('integer', $casts['can_warn']);

        $this->assertArrayHasKey('active', $casts);
        $this->assertEquals('integer', $casts['active']);
    }

    public function testIsWarningReturnsTrueForZeroDays(): void
    {
        $this->template->ban_days = 0;
        $this->assertTrue($this->template->isWarning());
    }

    public function testIsWarningReturnsFalseForNonZeroDays(): void
    {
        $this->template->ban_days = 1;
        $this->assertFalse($this->template->isWarning());
    }

    public function testIsPermanentReturnsTrueForNegativeOneDays(): void
    {
        $this->template->ban_days = -1;
        $this->assertTrue($this->template->isPermanent());
    }

    public function testIsPermanentReturnsFalseForOtherValues(): void
    {
        $this->template->ban_days = 30;
        $this->assertFalse($this->template->isPermanent());

        $this->template->ban_days = 0;
        $this->assertFalse($this->template->isPermanent());
    }

    public function testGetBanLengthSecondsReturnsZeroForWarning(): void
    {
        $this->template->ban_days = 0;
        $this->template->banlen = '';
        $this->assertEquals(0, $this->template->getBanLengthSeconds());
    }

    public function testGetBanLengthSecondsReturnsZeroForPermanent(): void
    {
        $this->template->ban_days = -1;
        $this->template->banlen = 'indefinite';
        $this->assertEquals(0, $this->template->getBanLengthSeconds());
    }

    public function testGetBanLengthSecondsCalculatesDaysCorrectly(): void
    {
        $this->template->ban_days = 7;
        $this->template->banlen = '';
        $this->assertEquals(7 * 24 * 60 * 60, $this->template->getBanLengthSeconds());
    }

    public function testGetBanLengthSecondsUsesBanlenWhenDaysIsZero(): void
    {
        $this->template->ban_days = 0;
        $this->template->banlen = '86400';
        $this->assertEquals(86400, $this->template->getBanLengthSeconds());
    }

    public function testReportAbuseTemplateIdConstant(): void
    {
        $this->assertSame(190, BanTemplate::REPORT_ABUSE_TEMPLATE);
    }

    public function testTypeConstants(): void
    {
        $this->assertEquals('local', BanTemplate::TYPE_LOCAL);
        $this->assertEquals('global', BanTemplate::TYPE_GLOBAL);
        $this->assertEquals('zonly', BanTemplate::TYPE_ZONLY);
    }

    public function testAccessLevelConstants(): void
    {
        $this->assertEquals('janitor', BanTemplate::ACCESS_JANITOR);
        $this->assertEquals('mod', BanTemplate::ACCESS_MOD);
        $this->assertEquals('manager', BanTemplate::ACCESS_MANAGER);
        $this->assertEquals('admin', BanTemplate::ACCESS_ADMIN);
    }

    public function testHydrateAttributes(): void
    {
        $data = [
            'rule' => 'global1',
            'name' => 'Test Ban',
            'ban_type' => 'global',
            'ban_days' => 7,
            'banlen' => '',
            'can_warn' => 1,
            'publicban' => 0,
            'is_public' => 1,
            'public_reason' => 'Test reason',
            'private_reason' => 'Private reason',
            'action' => 'delall',
            'save_type' => 'everything',
            'blacklist_image' => 0,
            'reject_image' => 0,
            'access' => 'janitor',
            'boards' => '',
            'exclude' => '',
            'appealable' => 1,
            'active' => 1
        ];

        $this->template->fill($data);

        $this->assertEquals('global1', $this->template->rule);
        $this->assertEquals('Test Ban', $this->template->name);
        $this->assertEquals('global', $this->template->ban_type);
        $this->assertEquals(7, $this->template->ban_days);
        $this->assertEquals('Test reason', $this->template->public_reason);
    }

    public function testIsActiveScope(): void
    {
        $query = $this->template->newQuery();
        $scopedQuery = $query->active();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testForAccessScope(): void
    {
        $query = $this->template->newQuery();
        $scopedQuery = $query->forAccess('janitor');

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }
}
