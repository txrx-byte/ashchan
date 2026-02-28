<?php
declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\Thread;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Model\Thread
 */
final class ThreadTest extends TestCase
{
    private Thread $thread;

    protected function setUp(): void
    {
        $this->thread = new Thread();
    }

    public function testFillableProperties(): void
    {
        $expected = [
            'board_slug', 'subject', 'name', 'tripcode', 'capcode', 
            'country', 'country_name', 'no', 'op', 'time', 'bumptime', 
            'lastpost', 'image', 'thumb', 'imagew', 'imageh', 'thumbw', 
            'thumbh', 'filesize', 'filename', 'filehash', 'image_deleted', 
            'file_path', 'thumb_path', 'embed', 'tag', 'slug', 'archived', 
            'archived_on', 'closed', 'sticky', 'end_reached', 'omitted', 
            'images', 'replies'
        ];

        $this->assertEquals($expected, $this->thread->getFillable());
    }

    public function testCastsFieldsCorrectly(): void
    {
        $casts = $this->thread->getCasts();

        $this->assertArrayHasKey('no', $casts);
        $this->assertEquals('integer', $casts['no']);

        $this->assertArrayHasKey('op', $casts);
        $this->assertEquals('integer', $casts['op']);

        $this->assertArrayHasKey('time', $casts);
        $this->assertEquals('datetime', $casts['time']);

        $this->assertArrayHasKey('bumptime', $casts);
        $this->assertEquals('datetime', $casts['bumptime']);

        $this->assertArrayHasKey('archived', $casts);
        $this->assertEquals('integer', $casts['archived']);

        $this->assertArrayHasKey('closed', $casts);
        $this->assertEquals('integer', $casts['closed']);

        $this->assertArrayHasKey('sticky', $casts);
        $this->assertEquals('integer', $casts['sticky']);

        $this->assertArrayHasKey('omitted', $casts);
        $this->assertEquals('integer', $casts['omitted']);

        $this->assertArrayHasKey('images', $casts);
        $this->assertEquals('integer', $casts['images']);

        $this->assertArrayHasKey('replies', $casts);
        $this->assertEquals('integer', $casts['replies']);
    }

    public function testHiddenFields(): void
    {
        $expected = ['created_at', 'updated_at'];
        $this->assertEquals($expected, $this->thread->getHidden());
    }

    public function testIsArchivedReturnsTrueWhenArchived(): void
    {
        $this->thread->archived = 1;
        $this->assertTrue($this->thread->isArchived());
    }

    public function testIsArchivedReturnsFalseWhenNotArchived(): void
    {
        $this->thread->archived = 0;
        $this->assertFalse($this->thread->isArchived());
    }

    public function testIsClosedReturnsTrueWhenClosed(): void
    {
        $this->thread->closed = 1;
        $this->assertTrue($this->thread->isClosed());
    }

    public function testIsClosedReturnsFalseWhenOpen(): void
    {
        $this->thread->closed = 0;
        $this->assertFalse($this->thread->isClosed());
    }

    public function testIsStickyReturnsTrueWhenSticky(): void
    {
        $this->thread->sticky = 1;
        $this->assertTrue($this->thread->isSticky());
    }

    public function testIsStickyReturnsFalseWhenNotSticky(): void
    {
        $this->thread->sticky = 0;
        $this->assertFalse($this->thread->isSticky());
    }

    public function testHasImageReturnsTrueWhenImageExists(): void
    {
        $this->thread->image = 'image.jpg';
        $this->assertTrue($this->thread->hasImage());
    }

    public function testHasImageReturnsFalseWhenNoImage(): void
    {
        $this->thread->image = null;
        $this->assertFalse($this->thread->hasImage());
    }

    public function testHasEmbedReturnsTrueWhenEmbedExists(): void
    {
        $this->thread->embed = 'youtube:abc123';
        $this->assertTrue($this->thread->hasEmbed());
    }

    public function testHasEmbedReturnsFalseWhenNoEmbed(): void
    {
        $this->thread->embed = null;
        $this->assertFalse($this->thread->hasEmbed());
    }

    public function testGetSubjectReturnsTruncatedSubject(): void
    {
        $this->thread->subject = str_repeat('a', 100);
        
        $result = $this->thread->getSubject(10);
        $this->assertEquals(str_repeat('a', 10) . '...', $result);
    }

    public function testGetSubjectReturnsFullSubjectWhenShortEnough(): void
    {
        $this->thread->subject = 'Short subject';
        
        $result = $this->thread->getSubject(50);
        $this->assertEquals('Short subject', $result);
    }

    public function testGetRepliesCount(): void
    {
        $this->thread->replies = 50;
        $this->assertEquals(50, $this->thread->getRepliesCount());
    }

    public function testGetImagesCount(): void
    {
        $this->thread->images = 25;
        $this->assertEquals(25, $this->thread->getImagesCount());
    }

    public function testGetOmittedCount(): void
    {
        $this->thread->omitted = 10;
        $this->assertEquals(10, $this->thread->getOmittedCount());
    }

    public function testIsEndReachedReturnsTrueWhenEndReached(): void
    {
        $this->thread->end_reached = 1;
        $this->assertTrue($this->thread->isEndReached());
    }

    public function testIsEndReachedReturnsFalseWhenNotEndReached(): void
    {
        $this->thread->end_reached = 0;
        $this->assertFalse($this->thread->isEndReached());
    }

    public function testHydrateAttributes(): void
    {
        $data = [
            'board_slug' => 'g',
            'subject' => 'Test Thread',
            'name' => 'Anonymous',
            'no' => 12345,
            'op' => 1,
            'time' => '2024-01-01 12:00:00',
            'bumptime' => '2024-01-01 13:00:00',
            'image' => 'image.jpg',
            'thumb' => 'thumb.jpg',
            'replies' => 50,
            'images' => 25,
            'omitted' => 10,
            'sticky' => 1,
            'closed' => 0,
            'archived' => 0
        ];

        $this->thread->fill($data);

        $this->assertEquals('g', $this->thread->board_slug);
        $this->assertEquals('Test Thread', $this->thread->subject);
        $this->assertEquals(12345, $this->thread->no);
        $this->assertEquals(1, $this->thread->op);
        $this->assertEquals(50, $this->thread->replies);
        $this->assertEquals(25, $this->thread->images);
    }

    public function testBoardRelationship(): void
    {
        $relationship = $this->thread->board();
        $this->assertInstanceOf(\Hyperf\Database\Model\Relations\BelongsTo::class, $relationship);
    }

    public function testPostsRelationship(): void
    {
        $relationship = $this->thread->posts();
        $this->assertInstanceOf(\Hyperf\Database\Model\Relations\HasMany::class, $relationship);
    }

    public function testForBoardScope(): void
    {
        $query = $this->thread->newQuery();
        $scopedQuery = $query->forBoard('g');

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testActiveScope(): void
    {
        $query = $this->thread->newQuery();
        $scopedQuery = $query->active();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testArchivedScope(): void
    {
        $query = $this->thread->newQuery();
        $scopedQuery = $query->archived();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testStickyScope(): void
    {
        $query = $this->thread->newQuery();
        $scopedQuery = $query->sticky();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testClosedScope(): void
    {
        $query = $this->thread->newQuery();
        $scopedQuery = $query->closed();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testWithImageScope(): void
    {
        $query = $this->thread->newQuery();
        $scopedQuery = $query->withImage();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testGetSlugReturnsExistingSlug(): void
    {
        $this->thread->slug = 'test-thread';
        $this->assertEquals('test-thread', $this->thread->getSlug());
    }

    public function testGetSlugGeneratesFromSubject(): void
    {
        $this->thread->slug = null;
        $this->thread->subject = 'Test Thread Subject';
        $this->thread->no = 12345;
        
        $result = $this->thread->getSlug();
        $this->assertStringContainsString('test-thread-subject', $result);
        $this->assertStringContainsString('12345', $result);
    }

    public function testGetSlugGeneratesFromIdWhenNoSubject(): void
    {
        $this->thread->slug = null;
        $this->thread->subject = null;
        $this->thread->no = 12345;
        
        $result = $this->thread->getSlug();
        $this->assertStringContainsString('12345', $result);
    }

    public function testIncrementReplies(): void
    {
        $this->thread->replies = 10;
        
        $reflection = new \ReflectionClass($this->thread);
        $method = $reflection->getMethod('incrementReplies');
        $method->setAccessible(true);
        $method->invoke($this->thread);
        
        $this->assertEquals(11, $this->thread->replies);
    }

    public function testDecrementReplies(): void
    {
        $this->thread->replies = 10;
        
        $reflection = new \ReflectionClass($this->thread);
        $method = $reflection->getMethod('decrementReplies');
        $method->setAccessible(true);
        $method->invoke($this->thread);
        
        $this->assertEquals(9, $this->thread->replies);
    }

    public function testIncrementImages(): void
    {
        $this->thread->images = 5;
        
        $reflection = new \ReflectionClass($this->thread);
        $method = $reflection->getMethod('incrementImages');
        $method->setAccessible(true);
        $method->invoke($this->thread);
        
        $this->assertEquals(6, $this->thread->images);
    }

    public function testGetBumpTime(): void
    {
        $now = new \DateTime();
        $this->thread->bumptime = $now;
        
        $result = $this->thread->getBumpTime();
        $this->assertInstanceOf(\DateTime::class, $result);
    }

    public function testGetLastPostTime(): void
    {
        $now = new \DateTime();
        $this->thread->lastpost = $now->format('Y-m-d H:i:s');
        
        $result = $this->thread->getLastPostTime();
        $this->assertNotNull($result);
    }
}
