<?php
declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\ReportCategory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Model\ReportCategory
 */
final class ReportCategoryTest extends TestCase
{
    private ReportCategory $category;

    protected function setUp(): void
    {
        $this->category = new ReportCategory();
    }

    public function testFillableProperties(): void
    {
        $expected = [
            'board', 'title', 'weight', 'exclude_boards', 'filtered', 
            'op_only', 'reply_only', 'image_only'
        ];

        $this->assertEquals($expected, $this->category->getFillable());
    }

    public function testCastsFieldsCorrectly(): void
    {
        $casts = $this->category->getCasts();

        $this->assertArrayHasKey('id', $casts);
        $this->assertEquals('integer', $casts['id']);

        $this->assertArrayHasKey('weight', $casts);
        $this->assertEquals('float', $casts['weight']);

        $this->assertArrayHasKey('filtered', $casts);
        $this->assertEquals('integer', $casts['filtered']);

        $this->assertArrayHasKey('op_only', $casts);
        $this->assertEquals('integer', $casts['op_only']);
    }

    public function testBoardConstants(): void
    {
        $this->assertEquals('_ws_', ReportCategory::WS_BOARD);
        $this->assertEquals('_nws_', ReportCategory::NWS_BOARD);
        $this->assertEquals('_all_', ReportCategory::ALL_BOARDS);
    }

    public function testMaxWeightConstant(): void
    {
        $this->assertEquals(9999.99, ReportCategory::MAX_WEIGHT);
    }

    public function testAppliesToPostReturnsTrueForGlobalCategory(): void
    {
        $this->category->board = '_all_';
        $this->category->op_only = 0;
        $this->category->reply_only = 0;
        $this->category->image_only = 0;

        $result = $this->category->appliesToPost('g', true, false);
        $this->assertTrue($result);
    }

    public function testAppliesToPostReturnsFalseForExcludedBoard(): void
    {
        $this->category->board = '_all_';
        $this->category->exclude_boards = 'g,v';
        $this->category->op_only = 0;
        $this->category->reply_only = 0;
        $this->category->image_only = 0;

        $result = $this->category->appliesToPost('g', true, false);
        $this->assertFalse($result);
    }

    public function testAppliesToPostReturnsTrueForOpOnlyOnThread(): void
    {
        $this->category->board = '_all_';
        $this->category->op_only = 1;
        $this->category->reply_only = 0;
        $this->category->image_only = 0;

        $result = $this->category->appliesToPost('g', true, false);
        $this->assertTrue($result);
    }

    public function testAppliesToPostReturnsFalseForOpOnlyOnReply(): void
    {
        $this->category->board = '_all_';
        $this->category->op_only = 1;
        $this->category->reply_only = 0;
        $this->category->image_only = 0;

        $result = $this->category->appliesToPost('g', false, false);
        $this->assertFalse($result);
    }

    public function testAppliesToPostReturnsTrueForReplyOnlyOnReply(): void
    {
        $this->category->board = '_all_';
        $this->category->op_only = 0;
        $this->category->reply_only = 1;
        $this->category->image_only = 0;

        $result = $this->category->appliesToPost('g', false, false);
        $this->assertTrue($result);
    }

    public function testAppliesToPostReturnsFalseForReplyOnlyOnThread(): void
    {
        $this->category->board = '_all_';
        $this->category->op_only = 0;
        $this->category->reply_only = 1;
        $this->category->image_only = 0;

        $result = $this->category->appliesToPost('g', true, false);
        $this->assertFalse($result);
    }

    public function testAppliesToPostReturnsTrueForImageOnlyWithImage(): void
    {
        $this->category->board = '_all_';
        $this->category->op_only = 0;
        $this->category->reply_only = 0;
        $this->category->image_only = 1;

        $result = $this->category->appliesToPost('g', false, true);
        $this->assertTrue($result);
    }

    public function testAppliesToPostReturnsFalseForImageOnlyWithoutImage(): void
    {
        $this->category->board = '_all_';
        $this->category->op_only = 0;
        $this->category->reply_only = 0;
        $this->category->image_only = 1;

        $result = $this->category->appliesToPost('g', false, false);
        $this->assertFalse($result);
    }

    public function testAppliesToPostReturnsFalseForWorksafeCategoryOnNwsBoard(): void
    {
        $this->category->board = '_ws_';
        $this->category->op_only = 0;
        $this->category->reply_only = 0;
        $this->category->image_only = 0;

        $result = $this->category->appliesToPost('b', true, false);
        $this->assertFalse($result);
    }

    public function testAppliesToPostReturnsTrueForWorksafeCategoryOnWsBoard(): void
    {
        $this->category->board = '_ws_';
        $this->category->op_only = 0;
        $this->category->reply_only = 0;
        $this->category->image_only = 0;

        $result = $this->category->appliesToPost('g', true, false);
        $this->assertTrue($result);
    }

    public function testForBoardScope(): void
    {
        $query = $this->category->newQuery();
        $scopedQuery = $query->forBoard('g');

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testWorksafeScope(): void
    {
        $query = $this->category->newQuery();
        $scopedQuery = $query->worksafe();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testNotWorksafeScope(): void
    {
        $query = $this->category->newQuery();
        $scopedQuery = $query->notWorksafe();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testHydrateAttributes(): void
    {
        $data = [
            'board' => '_all_',
            'title' => 'Spam',
            'weight' => 30.00,
            'filtered' => 0,
            'op_only' => 0,
            'reply_only' => 0,
            'image_only' => 0
        ];

        $this->category->fill($data);

        $this->assertEquals('_all_', $this->category->board);
        $this->assertEquals('Spam', $this->category->title);
        $this->assertEquals(30.00, $this->category->weight);
        $this->assertEquals(0, $this->category->op_only);
    }

    public function testGetForReportFormReturnsCategoriesForBoard(): void
    {
        $result = ReportCategory::getForReportForm('g', true);
        
        $this->assertIsArray($result);
    }

    public function testGetForReportFormReturnsDifferentCategoriesForNwsBoard(): void
    {
        $wsResult = ReportCategory::getForReportForm('g', true);
        $nwsResult = ReportCategory::getForReportForm('b', false);

        $this->assertIsArray($wsResult);
        $this->assertIsArray($nwsResult);
    }
}
