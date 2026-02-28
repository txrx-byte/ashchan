<?php
declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\Post;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Model\Post
 */
final class PostTest extends TestCase
{
    private Post $post;

    protected function setUp(): void
    {
        $this->post = new Post();
    }

    public function testFillableProperties(): void
    {
        $expected = [
            'board_slug', 'thread_no', 'name', 'tripcode', 'capcode', 
            'country', 'country_name', 'no', 'resto', 'op', 'time', 
            'image', 'thumb', 'imagew', 'imageh', 'thumbw', 'thumbh', 
            'filesize', 'filename', 'filehash', 'file_path', 'thumb_path', 
            'embed', 'tag', 'slug', 'comment', 'media_id'
        ];

        $this->assertEquals($expected, $this->post->getFillable());
    }

    public function testCastsFieldsCorrectly(): void
    {
        $casts = $this->post->getCasts();

        $this->assertArrayHasKey('no', $casts);
        $this->assertEquals('integer', $casts['no']);

        $this->assertArrayHasKey('resto', $casts);
        $this->assertEquals('integer', $casts['resto']);

        $this->assertArrayHasKey('op', $casts);
        $this->assertEquals('integer', $casts['op']);

        $this->assertArrayHasKey('time', $casts);
        $this->assertEquals('datetime', $casts['time']);

        $this->assertArrayHasKey('filesize', $casts);
        $this->assertEquals('integer', $casts['filesize']);

        $this->assertArrayHasKey('imagew', $casts);
        $this->assertEquals('integer', $casts['imagew']);

        $this->assertArrayHasKey('imageh', $casts);
        $this->assertEquals('integer', $casts['imageh']);
    }

    public function testHiddenFields(): void
    {
        $expected = ['created_at', 'updated_at'];
        $this->assertEquals($expected, $this->post->getHidden());
    }

    public function testIsOpReturnsTrueForOriginalPost(): void
    {
        $this->post->op = 1;
        $this->assertTrue($this->post->isOp());
    }

    public function testIsOpReturnsFalseForReply(): void
    {
        $this->post->op = 0;
        $this->assertFalse($this->post->isOp());
    }

    public function testIsReplyReturnsTrueForReply(): void
    {
        $this->post->resto = 12345;
        $this->assertTrue($this->post->isReply());
    }

    public function testIsReplyReturnsFalseForOP(): void
    {
        $this->post->resto = 0;
        $this->assertFalse($this->post->isReply());
    }

    public function testHasImageReturnsTrueWhenImageExists(): void
    {
        $this->post->image = 'image.jpg';
        $this->assertTrue($this->post->hasImage());
    }

    public function testHasImageReturnsFalseWhenNoImage(): void
    {
        $this->post->image = null;
        $this->assertFalse($this->post->hasImage());
    }

    public function testHasEmbedReturnsTrueWhenEmbedExists(): void
    {
        $this->post->embed = 'youtube:abc123';
        $this->assertTrue($this->post->hasEmbed());
    }

    public function testHasEmbedReturnsFalseWhenNoEmbed(): void
    {
        $this->post->embed = null;
        $this->assertFalse($this->post->hasEmbed());
    }

    public function testHasCommentReturnsTrueWhenCommentExists(): void
    {
        $this->post->comment = 'Test comment';
        $this->assertTrue($this->post->hasComment());
    }

    public function testHasCommentReturnsFalseWhenEmptyComment(): void
    {
        $this->post->comment = '';
        $this->assertFalse($this->post->hasComment());
    }

    public function testHasCommentReturnsFalseWhenNullComment(): void
    {
        $this->post->comment = null;
        $this->assertFalse($this->post->hasComment());
    }

    public function testGetCommentReturnsRawComment(): void
    {
        $this->post->comment = 'Test comment';
        $this->assertEquals('Test comment', $this->post->getComment());
    }

    public function testGetCommentReturnsEmptyStringWhenNull(): void
    {
        $this->post->comment = null;
        $this->assertEquals('', $this->post->getComment());
    }

    public function testHydrateAttributes(): void
    {
        $data = [
            'board_slug' => 'g',
            'thread_no' => 12345,
            'name' => 'Anonymous',
            'no' => 67890,
            'resto' => 12345,
            'op' => 0,
            'time' => '2024-01-01 12:00:00',
            'comment' => 'Test comment',
            'image' => 'image.jpg',
            'filesize' => 102400,
            'imagew' => 800,
            'imageh' => 600
        ];

        $this->post->fill($data);

        $this->assertEquals('g', $this->post->board_slug);
        $this->assertEquals(12345, $this->post->thread_no);
        $this->assertEquals(67890, $this->post->no);
        $this->assertEquals(0, $this->post->op);
        $this->assertEquals('Test comment', $this->post->comment);
        $this->assertEquals(102400, $this->post->filesize);
    }

    public function testThreadRelationship(): void
    {
        $relationship = $this->post->thread();
        $this->assertInstanceOf(\Hyperf\Database\Model\Relations\BelongsTo::class, $relationship);
    }

    public function testBoardRelationship(): void
    {
        $relationship = $this->post->board();
        $this->assertInstanceOf(\Hyperf\Database\Model\Relations\BelongsTo::class, $relationship);
    }

    public function testReportsRelationship(): void
    {
        $relationship = $this->post->reports();
        $this->assertInstanceOf(\Hyperf\Database\Model\Relations\HasMany::class, $relationship);
    }

    public function testForBoardScope(): void
    {
        $query = $this->post->newQuery();
        $scopedQuery = $query->forBoard('g');

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testForThreadScope(): void
    {
        $query = $this->post->newQuery();
        $scopedQuery = $query->forThread(12345);

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testOpOnlyScope(): void
    {
        $query = $this->post->newQuery();
        $scopedQuery = $query->opOnly();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testRepliesOnlyScope(): void
    {
        $query = $this->post->newQuery();
        $scopedQuery = $query->repliesOnly();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testWithImageScope(): void
    {
        $query = $this->post->newQuery();
        $scopedQuery = $query->withImage();

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testRecentScope(): void
    {
        $query = $this->post->newQuery();
        $scopedQuery = $query->recent(10);

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testGetFileUrl(): void
    {
        $this->post->file_path = '/images/test.jpg';
        
        $result = $this->post->getFileUrl();
        $this->assertStringContainsString('test.jpg', $result);
    }

    public function testGetThumbUrl(): void
    {
        $this->post->thumb_path = '/thumbs/test.jpg';
        
        $result = $this->post->getThumbUrl();
        $this->assertStringContainsString('test.jpg', $result);
    }

    public function testGetFileSizeFormatted(): void
    {
        $this->post->filesize = 1048576; // 1 MB
        
        $reflection = new \ReflectionClass($this->post);
        $method = $reflection->getMethod('getFileSizeFormatted');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->post);
        $this->assertStringContainsString('MB', $result);
    }

    public function testGetImageDimensions(): void
    {
        $this->post->imagew = 800;
        $this->post->imageh = 600;
        
        $result = $this->post->getImageDimensions();
        $this->assertEquals('800x600', $result);
    }

    public function testGetImageDimensionsReturnsUnknownWhenMissing(): void
    {
        $this->post->imagew = null;
        $this->post->imageh = null;
        
        $result = $this->post->getImageDimensions();
        $this->assertEquals('Unknown', $result);
    }

    public function testGetPostId(): void
    {
        $this->post->no = 12345;
        $this->assertEquals(12345, $this->post->getPostId());
    }

    public function testGetThreadNo(): void
    {
        $this->post->resto = 67890;
        $this->assertEquals(67890, $this->post->getThreadNo());
    }

    public function testGetBoardSlug(): void
    {
        $this->post->board_slug = 'g';
        $this->assertEquals('g', $this->post->getBoardSlug());
    }

    public function testGetName(): void
    {
        $this->post->name = 'Anonymous';
        $this->assertEquals('Anonymous', $this->post->getName());
    }

    public function testGetTripcode(): void
    {
        $this->post->tripcode = '!ABC123';
        $this->assertEquals('!ABC123', $this->post->getTripcode());
    }

    public function testGetCapcode(): void
    {
        $this->post->capcode = 'mod';
        $this->assertEquals('mod', $this->post->getCapcode());
    }

    public function testGetCountry(): void
    {
        $this->post->country = 'US';
        $this->assertEquals('US', $this->post->getCountry());
    }

    public function testGetCountryName(): void
    {
        $this->post->country_name = 'United States';
        $this->assertEquals('United States', $this->post->getCountryName());
    }

    public function testGetTime(): void
    {
        $now = new \DateTime();
        $this->post->time = $now;
        
        $result = $this->post->getTime();
        $this->assertInstanceOf(\DateTime::class, $result);
    }

    public function testGetSlugReturnsExistingSlug(): void
    {
        $this->post->slug = 'test-post';
        $this->assertEquals('test-post', $this->post->getSlug());
    }

    public function testGetSlugGeneratesFromId(): void
    {
        $this->post->slug = null;
        $this->post->no = 12345;
        
        $result = $this->post->getSlug();
        $this->assertStringContainsString('12345', $result);
    }
}
