<?php
declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\OpenPostBody;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Model\OpenPostBody
 */
final class OpenPostBodyTest extends TestCase
{
    private OpenPostBody $postBody;

    protected function setUp(): void
    {
        $this->postBody = new OpenPostBody();
    }

    public function testFillableProperties(): void
    {
        $expected = ['board', 'thread', 'ip_hash', 'body'];
        $this->assertEquals($expected, $this->postBody->getFillable());
    }

    public function testCastsFieldsCorrectly(): void
    {
        $casts = $this->postBody->getCasts();

        $this->assertArrayHasKey('id', $casts);
        $this->assertEquals('integer', $casts['id']);

        $this->assertArrayHasKey('thread', $casts);
        $this->assertEquals('integer', $casts['thread']);
    }

    public function testHiddenFields(): void
    {
        $expected = ['created_at', 'updated_at'];
        $this->assertEquals($expected, $this->postBody->getHidden());
    }

    public function testHydrateAttributes(): void
    {
        $data = [
            'board' => 'g',
            'thread' => 12345,
            'ip_hash' => 'abc123hash',
            'body' => 'Test post body content'
        ];

        $this->postBody->fill($data);

        $this->assertEquals('g', $this->postBody->board);
        $this->assertEquals(12345, $this->postBody->thread);
        $this->assertEquals('abc123hash', $this->postBody->ip_hash);
        $this->assertEquals('Test post body content', $this->postBody->body);
    }

    public function testForBoardScope(): void
    {
        $query = $this->postBody->newQuery();
        $scopedQuery = $query->forBoard('g');

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testForThreadScope(): void
    {
        $query = $this->postBody->newQuery();
        $scopedQuery = $query->forThread(12345);

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testForIpHashScope(): void
    {
        $query = $this->postBody->newQuery();
        $scopedQuery = $query->forIpHash('abc123hash');

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testRecentScope(): void
    {
        $query = $this->postBody->newQuery();
        $scopedQuery = $query->recent(100);

        $this->assertInstanceOf(\Hyperf\Database\Model\Builder::class, $scopedQuery);
    }

    public function testGetBody(): void
    {
        $this->postBody->body = 'Test content';
        $this->assertEquals('Test content', $this->postBody->getBody());
    }

    public function testGetBoard(): void
    {
        $this->postBody->board = 'g';
        $this->assertEquals('g', $this->postBody->getBoard());
    }

    public function testGetThreadNo(): void
    {
        $this->postBody->thread = 12345;
        $this->assertEquals(12345, $this->postBody->getThreadNo());
    }

    public function testGetIpHash(): void
    {
        $this->postBody->ip_hash = 'testhash';
        $this->assertEquals('testhash', $this->postBody->getIpHash());
    }
}
