<?php
declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\Board;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Model\Board
 */
final class BoardTest extends TestCase
{
    private Board $board;

    protected function setUp(): void
    {
        $this->board = new Board();
    }

    public function testFillableProperties(): void
    {
        $expected = [
            'slug', 'name', 'title', 'subtitle', 'category', 'nsfw',
            'max_threads', 'bump_limit', 'image_limit', 'cooldown_seconds',
            'staff_only', 'default_name', 'force_image', 'force_anon'
        ];

        $this->assertEquals($expected, $this->board->getFillable());
    }

    public function testCastsFieldsCorrectly(): void
    {
        $casts = $this->board->getCasts();

        $this->assertArrayHasKey('id', $casts);
        $this->assertEquals('integer', $casts['id']);

        $this->assertArrayHasKey('nsfw', $casts);
        $this->assertEquals('integer', $casts['nsfw']);

        $this->assertArrayHasKey('max_threads', $casts);
        $this->assertEquals('integer', $casts['max_threads']);

        $this->assertArrayHasKey('bump_limit', $casts);
        $this->assertEquals('integer', $casts['bump_limit']);

        $this->assertArrayHasKey('image_limit', $casts);
        $this->assertEquals('integer', $casts['image_limit']);

        $this->assertArrayHasKey('cooldown_seconds', $casts);
        $this->assertEquals('integer', $casts['cooldown_seconds']);

        $this->assertArrayHasKey('staff_only', $casts);
        $this->assertEquals('integer', $casts['staff_only']);

        $this->assertArrayHasKey('force_image', $casts);
        $this->assertEquals('integer', $casts['force_image']);

        $this->assertArrayHasKey('force_anon', $casts);
        $this->assertEquals('integer', $casts['force_anon']);
    }

    public function testHiddenFields(): void
    {
        $expected = ['created_at', 'updated_at'];
        $this->assertEquals($expected, $this->board->getHidden());
    }

    public function testIsNsfwReturnsTrueForNsfwBoard(): void
    {
        $this->board->nsfw = 1;
        $this->assertTrue($this->board->isNsfw());
    }

    public function testIsNsfwReturnsFalseForWorksafeBoard(): void
    {
        $this->board->nsfw = 0;
        $this->assertFalse($this->board->isNsfw());
    }

    public function testIsStaffOnlyReturnsTrueForStaffBoard(): void
    {
        $this->board->staff_only = 1;
        $this->assertTrue($this->board->isStaffOnly());
    }

    public function testIsStaffOnlyReturnsFalseForPublicBoard(): void
    {
        $this->board->staff_only = 0;
        $this->assertFalse($this->board->isStaffOnly());
    }

    public function testForceImageReturnsTrueWhenEnabled(): void
    {
        $this->board->force_image = 1;
        $this->assertTrue($this->board->requiresImage());
    }

    public function testForceImageReturnsFalseWhenDisabled(): void
    {
        $this->board->force_image = 0;
        $this->assertFalse($this->board->requiresImage());
    }

    public function testForceAnonReturnsTrueWhenEnabled(): void
    {
        $this->board->force_anon = 1;
        $this->assertTrue($this->board->forcesAnonymous());
    }

    public function testForceAnonReturnsFalseWhenDisabled(): void
    {
        $this->board->force_anon = 0;
        $this->assertFalse($this->board->forcesAnonymous());
    }

    public function testHydrateAttributes(): void
    {
        $data = [
            'slug' => 'g',
            'name' => 'Technology',
            'title' => 'Technology',
            'subtitle' => 'Software & Hardware',
            'category' => 'Interests',
            'nsfw' => 0,
            'max_threads' => 200,
            'bump_limit' => 300,
            'image_limit' => 150,
            'cooldown_seconds' => 60,
            'staff_only' => 0,
            'default_name' => 'Anonymous',
            'force_image' => 0,
            'force_anon' => 0
        ];

        $this->board->fill($data);

        $this->assertEquals('g', $this->board->slug);
        $this->assertEquals('Technology', $this->board->name);
        $this->assertEquals('Technology', $this->board->title);
        $this->assertEquals(200, $this->board->max_threads);
        $this->assertEquals(300, $this->board->bump_limit);
        $this->assertEquals(150, $this->board->image_limit);
        $this->assertEquals(60, $this->board->cooldown_seconds);
    }

    public function testThreadsRelationship(): void
    {
        $relationship = $this->board->threads();
        $this->assertInstanceOf(\Hyperf\Database\Model\Relations\HasMany::class, $relationship);
    }

    public function testReportsRelationship(): void
    {
        $relationship = $this->board->reports();
        $this->assertInstanceOf(\Hyperf\Database\Model\Relations\HasMany::class, $relationship);
    }

    public function testSlugScope(): void
    {
        $query = $this->board->newQuery();
        $scopedQuery = $query->bySlug('g');

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testActiveScope(): void
    {
        $query = $this->board->newQuery();
        $scopedQuery = $query->active();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testNsfwScope(): void
    {
        $query = $this->board->newQuery();
        $scopedQuery = $query->nsfw();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testWorksafeScope(): void
    {
        $query = $this->board->newQuery();
        $scopedQuery = $query->worksafe();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testStaffOnlyScope(): void
    {
        $query = $this->board->newQuery();
        $scopedQuery = $query->staffOnly();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testGetFullTitleReturnsTitleAndSubtitle(): void
    {
        $this->board->title = 'Technology';
        $this->board->subtitle = 'Software & Hardware';

        $result = $this->board->getFullTitle();
        $this->assertEquals('Technology - Software & Hardware', $result);
    }

    public function testGetFullTitleReturnsOnlyTitleWhenNoSubtitle(): void
    {
        $this->board->title = 'Technology';
        $this->board->subtitle = null;

        $result = $this->board->getFullTitle();
        $this->assertEquals('Technology', $result);
    }

    public function testGetConfigReturnsArray(): void
    {
        $this->board->slug = 'g';
        $this->board->max_threads = 200;
        $this->board->bump_limit = 300;
        $this->board->image_limit = 150;
        $this->board->cooldown_seconds = 60;

        $result = $this->board->getConfig();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('max_threads', $result);
        $this->assertArrayHasKey('bump_limit', $result);
        $this->assertArrayHasKey('image_limit', $result);
        $this->assertArrayHasKey('cooldown_seconds', $result);
    }
}
