<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\SearchService;
use App\Service\SiteConfigService;
use Hyperf\Redis\Redis;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

final class SearchServiceTest extends TestCase
{
    private SearchService $searchService;
    private Redis&MockInterface $mockRedis;
    private SiteConfigService&MockInterface $mockConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRedis = Mockery::mock(Redis::class);
        $this->mockConfig = Mockery::mock(SiteConfigService::class);

        // Default config values
        $this->mockConfig->shouldReceive('getInt')->with('search_index_ttl', 604800)->andReturn(604800);
        $this->mockConfig->shouldReceive('getInt')->with('search_index_text_max', 500)->andReturn(500);
        $this->mockConfig->shouldReceive('getInt')->with('search_default_per_page', 25)->andReturn(25);
        $this->mockConfig->shouldReceive('getInt')->with('search_min_query_length', 2)->andReturn(2);
        $this->mockConfig->shouldReceive('getInt')->with('search_excerpt_length', 200)->andReturn(200);

        $this->searchService = new SearchService($this->mockRedis, $this->mockConfig);
    }

    protected function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        Mockery::close();
        parent::tearDown();
    }

    // --- indexPost tests ---

    public function testIndexPostStoresDocumentInRedis(): void
    {
        $this->mockRedis->shouldReceive('hSet')
            ->once()
            ->with('search:tech', '42', Mockery::on(function (string $json): bool {
                $doc = json_decode($json, true);
                return $doc['thread_id'] === 1
                    && $doc['post_id'] === 42
                    && str_contains($doc['text'], 'hello world');
            }))
            ->andReturn(1);

        $this->mockRedis->shouldReceive('expire')
            ->once()
            ->with('search:tech', 604800)
            ->andReturn(true);

        $this->searchService->indexPost('tech', 1, 42, 'Hello World');
    }

    public function testIndexPostIncludesSubjectInText(): void
    {
        $this->mockRedis->shouldReceive('hSet')
            ->once()
            ->with('search:tech', '42', Mockery::on(function (string $json): bool {
                $doc = json_decode($json, true);
                return str_starts_with($doc['text'], 'test subject hello world');
            }))
            ->andReturn(1);

        $this->mockRedis->shouldReceive('expire')
            ->once()
            ->andReturn(true);

        $this->searchService->indexPost('tech', 1, 42, 'Hello World', 'Test Subject');
    }

    public function testIndexPostStripsHtmlTags(): void
    {
        $this->mockRedis->shouldReceive('hSet')
            ->once()
            ->with('search:tech', '1', Mockery::on(function (string $json): bool {
                $doc = json_decode($json, true);
                return !str_contains($doc['text'], '<b>') && str_contains($doc['text'], 'bold text');
            }))
            ->andReturn(1);

        $this->mockRedis->shouldReceive('expire')->once()->andReturn(true);

        $this->searchService->indexPost('tech', 1, 1, '<b>Bold text</b> and <i>italic</i>');
    }

    public function testIndexPostSkipsVeryShortContent(): void
    {
        // Content shorter than 3 chars after processing should be skipped
        $this->mockRedis->shouldNotReceive('hSet');
        $this->mockRedis->shouldNotReceive('expire');

        $this->searchService->indexPost('tech', 1, 1, 'ab');
    }

    public function testIndexPostTruncatesLongText(): void
    {
        $longContent = str_repeat('a', 1000);

        $this->mockRedis->shouldReceive('hSet')
            ->once()
            ->with('search:tech', '1', Mockery::on(function (string $json): bool {
                $doc = json_decode($json, true);
                return mb_strlen($doc['text']) <= 500;
            }))
            ->andReturn(1);

        $this->mockRedis->shouldReceive('expire')->once()->andReturn(true);

        $this->searchService->indexPost('tech', 1, 1, $longContent);
    }

    public function testIndexPostLowercasesContent(): void
    {
        $this->mockRedis->shouldReceive('hSet')
            ->once()
            ->with('search:tech', '1', Mockery::on(function (string $json): bool {
                $doc = json_decode($json, true);
                return $doc['text'] === 'uppercase content';
            }))
            ->andReturn(1);

        $this->mockRedis->shouldReceive('expire')->once()->andReturn(true);

        $this->searchService->indexPost('tech', 1, 1, 'UPPERCASE CONTENT');
    }

    // --- removePost tests ---

    public function testRemovePostDeletesFromRedis(): void
    {
        $this->mockRedis->shouldReceive('hDel')
            ->once()
            ->with('search:tech', '42')
            ->andReturn(1);

        $this->searchService->removePost('tech', 42);
    }

    // --- search tests ---

    public function testSearchReturnsMatchingResults(): void
    {
        $this->mockRedis->shouldReceive('hGetAll')
            ->once()
            ->with('search:tech')
            ->andReturn([
                '1' => json_encode(['thread_id' => 10, 'post_id' => 1, 'text' => 'hello world from tech board']),
                '2' => json_encode(['thread_id' => 10, 'post_id' => 2, 'text' => 'goodbye world']),
                '3' => json_encode(['thread_id' => 11, 'post_id' => 3, 'text' => 'no match here']),
            ]);

        $result = $this->searchService->search('tech', 'hello', 1, 25);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['results']);
        $this->assertSame(1, $result['results'][0]['post_id']);
        $this->assertSame(10, $result['results'][0]['thread_id']);
    }

    public function testSearchReturnsPaginatedResults(): void
    {
        $docs = [];
        for ($i = 1; $i <= 30; $i++) {
            $docs[(string) $i] = json_encode([
                'thread_id' => 1,
                'post_id' => $i,
                'text' => "matching query content post {$i}",
            ]);
        }

        $this->mockRedis->shouldReceive('hGetAll')
            ->once()
            ->with('search:tech')
            ->andReturn($docs);

        $result = $this->searchService->search('tech', 'matching query', 1, 10);

        $this->assertSame(30, $result['total']);
        $this->assertCount(10, $result['results']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(3, $result['total_pages']);
    }

    public function testSearchSecondPage(): void
    {
        $docs = [];
        for ($i = 1; $i <= 30; $i++) {
            $docs[(string) $i] = json_encode([
                'thread_id' => 1,
                'post_id' => $i,
                'text' => "matching query content post {$i}",
            ]);
        }

        $this->mockRedis->shouldReceive('hGetAll')
            ->once()
            ->with('search:tech')
            ->andReturn($docs);

        $result = $this->searchService->search('tech', 'matching query', 2, 10);

        $this->assertSame(30, $result['total']);
        $this->assertCount(10, $result['results']);
        $this->assertSame(2, $result['page']);
    }

    public function testSearchRejectsShortQuery(): void
    {
        $result = $this->searchService->search('tech', 'a');

        $this->assertSame(0, $result['total']);
        $this->assertEmpty($result['results']);
    }

    public function testSearchReturnsEmptyForNoMatches(): void
    {
        $this->mockRedis->shouldReceive('hGetAll')
            ->once()
            ->with('search:tech')
            ->andReturn([
                '1' => json_encode(['thread_id' => 1, 'post_id' => 1, 'text' => 'something else entirely']),
            ]);

        $result = $this->searchService->search('tech', 'nonexistent term');

        $this->assertSame(0, $result['total']);
        $this->assertEmpty($result['results']);
    }

    public function testSearchSkipsInvalidJsonEntries(): void
    {
        $this->mockRedis->shouldReceive('hGetAll')
            ->once()
            ->with('search:tech')
            ->andReturn([
                '1' => 'not valid json{{{',
                '2' => json_encode(['thread_id' => 1, 'post_id' => 2, 'text' => 'valid match term']),
            ]);

        $result = $this->searchService->search('tech', 'match term');

        $this->assertSame(1, $result['total']);
        $this->assertSame(2, $result['results'][0]['post_id']);
    }

    public function testSearchSkipsEntriesMissingRequiredFields(): void
    {
        $this->mockRedis->shouldReceive('hGetAll')
            ->once()
            ->with('search:tech')
            ->andReturn([
                '1' => json_encode(['post_id' => 1, 'text' => 'missing thread_id match']),
                '2' => json_encode(['thread_id' => 1, 'post_id' => 2, 'text' => 'valid match here']),
            ]);

        $result = $this->searchService->search('tech', 'match');

        // Only entry 2 has required 'thread_id' field
        $this->assertSame(1, $result['total']);
        $this->assertSame(2, $result['results'][0]['post_id']);
    }

    public function testSearchResultContainsExcerptWithHighlighting(): void
    {
        $this->mockRedis->shouldReceive('hGetAll')
            ->once()
            ->with('search:tech')
            ->andReturn([
                '1' => json_encode(['thread_id' => 1, 'post_id' => 1, 'text' => 'hello world from tech board']),
            ]);

        $result = $this->searchService->search('tech', 'hello');

        $this->assertArrayHasKey('excerpt', $result['results'][0]);
        $this->assertStringContainsString('<mark>hello</mark>', $result['results'][0]['excerpt']);
    }

    // --- searchAll tests ---

    public function testSearchAllAggregatesResultsAcrossBoards(): void
    {
        // scan returns keys for two boards
        $this->mockRedis->shouldReceive('scan')
            ->once()
            ->andReturnUsing(function (&$cursor) {
                $cursor = 0;
                return ['search:tech', 'search:random'];
            });

        // tech board results
        $this->mockRedis->shouldReceive('hGetAll')
            ->with('search:tech')
            ->andReturn([
                '1' => json_encode(['thread_id' => 1, 'post_id' => 1, 'text' => 'matching query on tech']),
            ]);

        // random board results
        $this->mockRedis->shouldReceive('hGetAll')
            ->with('search:random')
            ->andReturn([
                '10' => json_encode(['thread_id' => 5, 'post_id' => 10, 'text' => 'matching query on random']),
            ]);

        $result = $this->searchService->searchAll('matching query', 1, 25);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['results']);

        // Each result should have a 'board' field
        $boards = array_column($result['results'], 'board');
        $this->assertContains('tech', $boards);
        $this->assertContains('random', $boards);
    }

    public function testSearchAllPaginatesResults(): void
    {
        $docs = [];
        for ($i = 1; $i <= 30; $i++) {
            $docs[(string) $i] = json_encode([
                'thread_id' => 1,
                'post_id' => $i,
                'text' => "matching term post {$i}",
            ]);
        }

        $this->mockRedis->shouldReceive('scan')
            ->once()
            ->andReturnUsing(function (&$cursor) {
                $cursor = 0;
                return ['search:tech'];
            });

        $this->mockRedis->shouldReceive('hGetAll')
            ->with('search:tech')
            ->andReturn($docs);

        $result = $this->searchService->searchAll('matching term', 1, 10);

        $this->assertSame(30, $result['total']);
        $this->assertCount(10, $result['results']);
        $this->assertSame(3, $result['total_pages']);
    }

    public function testSearchAllReturnsEmptyWhenNoKeys(): void
    {
        $this->mockRedis->shouldReceive('scan')
            ->once()
            ->andReturnUsing(function (&$cursor) {
                $cursor = 0;
                return [];
            });

        $result = $this->searchService->searchAll('query', 1, 25);

        $this->assertSame(0, $result['total']);
        $this->assertEmpty($result['results']);
    }
}
