<?php
declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\Blotter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Model\Blotter
 */
final class BlotterTest extends TestCase
{
    private Blotter $blotter;

    protected function setUp(): void
    {
        $this->blotter = new Blotter();
    }

    public function testFillableProperties(): void
    {
        $expected = ['content', 'is_important'];
        $this->assertEquals($expected, $this->blotter->getFillable());
    }

    public function testCastsFieldsCorrectly(): void
    {
        $casts = $this->blotter->getCasts();

        $this->assertArrayHasKey('id', $casts);
        $this->assertEquals('integer', $casts['id']);

        $this->assertArrayHasKey('is_important', $casts);
        $this->assertEquals('integer', $casts['is_important']);
    }

    public function testIsImportantReturnsTrueWhenFlagged(): void
    {
        $this->blotter->is_important = 1;
        $this->assertTrue($this->blotter->isImportant());
    }

    public function testIsImportantReturnsFalseWhenNotFlagged(): void
    {
        $this->blotter->is_important = 0;
        $this->assertFalse($this->blotter->isImportant());
    }

    public function testHydrateAttributes(): void
    {
        $data = [
            'content' => 'Welcome to the board!',
            'is_important' => 1
        ];

        $this->blotter->fill($data);

        $this->assertEquals('Welcome to the board!', $this->blotter->content);
        $this->assertEquals(1, $this->blotter->is_important);
    }

    public function testImportantScope(): void
    {
        $query = $this->blotter->newQuery();
        $scopedQuery = $query->important();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testGetRecentReturnsLimitedResults(): void
    {
        $result = Blotter::getRecent(5);
        $this->assertIsArray($result);
    }

    public function testGetRecentUsesDefaultLimit(): void
    {
        $result = Blotter::getRecent();
        $this->assertIsArray($result);
    }
}
