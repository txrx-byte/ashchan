<?php
declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\Report;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Model\Report
 */
final class ReportTest extends TestCase
{
    private Report $report;

    protected function setUp(): void
    {
        $this->report = new Report();
    }

    public function testFillableProperties(): void
    {
        $expected = [
            'ip', 'ip_hash', 'pwd', 'pass_id', 'board', 'no', 'resto', 
            'cat', 'weight', 'report_category', 'post_ip', 'post_json', 
            'cleared', 'cleared_by', 'req_sig', 'ws', 'ts'
        ];

        $this->assertEquals($expected, $this->report->getFillable());
    }

    public function testCastsFieldsCorrectly(): void
    {
        $casts = $this->report->getCasts();

        $this->assertArrayHasKey('id', $casts);
        $this->assertEquals('integer', $casts['id']);

        $this->assertArrayHasKey('no', $casts);
        $this->assertEquals('integer', $casts['no']);

        $this->assertArrayHasKey('weight', $casts);
        $this->assertEquals('float', $casts['weight']);

        $this->assertArrayHasKey('cleared', $casts);
        $this->assertEquals('integer', $casts['cleared']);
    }

    public function testCategoryConstants(): void
    {
        $this->assertSame(1, Report::CAT_RULE);
        $this->assertSame(2, Report::CAT_ILLEGAL);
    }

    public function testThresholdConstants(): void
    {
        $this->assertSame(1500, Report::GLOBAL_THRES);
        $this->assertSame(500, Report::HIGHLIGHT_THRES);
    }

    public function testThreadWeightBoostConstant(): void
    {
        $this->assertEquals(1.25, Report::THREAD_WEIGHT_BOOST);
    }

    public function testIsUnlockedReturnsTrueWhenWeightExceedsThreshold(): void
    {
        $this->report->weight = 1600;
        $this->assertTrue($this->report->isUnlocked());
    }

    public function testIsUnlockedReturnsFalseWhenWeightBelowThreshold(): void
    {
        $this->report->weight = 1000;
        $this->assertFalse($this->report->isUnlocked());
    }

    public function testIsUnlockedReturnsFalseAtExactThreshold(): void
    {
        $this->report->weight = 1500;
        $this->assertFalse($this->report->isUnlocked());
    }

    public function testGetPostDataReturnsDecodedJson(): void
    {
        $postData = [
            'name' => 'Anonymous',
            'comment' => 'Test comment',
            'ip' => '192.168.1.1'
        ];
        $this->report->post_json = json_encode($postData);

        $result = $this->report->getPostData();
        $this->assertEquals($postData, $result);
    }

    public function testGetPostDataReturnsEmptyArrayWhenJsonInvalid(): void
    {
        $this->report->post_json = 'invalid json';
        $result = $this->report->getPostData();
        $this->assertEquals([], $result);
    }

    public function testGetPostDataReturnsEmptyArrayWhenNull(): void
    {
        $this->report->post_json = null;
        $result = $this->report->getPostData();
        $this->assertEquals([], $result);
    }

    public function testGetReporterIpReturnsDecryptedIp(): void
    {
        $this->report->ip = '192.168.1.100';
        $result = $this->report->getReporterIp();
        $this->assertEquals('192.168.1.100', $result);
    }

    public function testGetCategoryNameReturnsRuleForCat1(): void
    {
        $this->report->cat = 1;
        $result = $this->report->getCategoryName();
        $this->assertEquals('Rule Violation', $result);
    }

    public function testGetCategoryNameReturnsIllegalForCat2(): void
    {
        $this->report->cat = 2;
        $result = $this->report->getCategoryName();
        $this->assertEquals('Illegal', $result);
    }

    public function testGetCategoryNameReturnsUnknownForOtherCategories(): void
    {
        $this->report->cat = 99;
        $result = $this->report->getCategoryName();
        $this->assertEquals('Unknown', $result);
    }

    public function testForBoardScope(): void
    {
        $query = $this->report->newQuery();
        $scopedQuery = $query->forBoard('g');

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testClearedScope(): void
    {
        $query = $this->report->newQuery();
        $scopedQuery = $query->cleared();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testPendingScope(): void
    {
        $query = $this->report->newQuery();
        $scopedQuery = $query->pending();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testHydrateAttributes(): void
    {
        $data = [
            'board' => 'g',
            'no' => 12345,
            'resto' => 0,
            'cat' => 1,
            'weight' => 10.5,
            'cleared' => 0,
            'ip_hash' => 'abc123'
        ];

        $this->report->fill($data);

        $this->assertEquals('g', $this->report->board);
        $this->assertEquals(12345, $this->report->no);
        $this->assertEquals(10.5, $this->report->weight);
        $this->assertEquals(0, $this->report->cleared);
    }

    public function testIsThreadReturnsTrueWhenRestoIsZero(): void
    {
        $this->report->resto = 0;
        
        $reflection = new \ReflectionClass($this->report);
        $method = $reflection->getMethod('isThread');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->report);
        $this->assertTrue($result);
    }

    public function testIsThreadReturnsFalseWhenRestoIsNonZero(): void
    {
        $this->report->resto = 12345;
        
        $reflection = new \ReflectionClass($this->report);
        $method = $reflection->getMethod('isThread');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->report);
        $this->assertFalse($result);
    }
}
