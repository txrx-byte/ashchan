<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\SearchController;
use App\Service\SearchService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class SearchControllerTest extends TestCase
{
    private SearchController $controller;
    private SearchService&MockInterface $mockSearchService;
    private HttpResponse&MockInterface $mockResponse;
    private RequestInterface&MockInterface $mockRequest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockSearchService = Mockery::mock(SearchService::class);
        $this->mockResponse = Mockery::mock(HttpResponse::class);
        $this->mockRequest = Mockery::mock(RequestInterface::class);

        $this->controller = new SearchController($this->mockSearchService, $this->mockResponse);
    }

    protected function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        Mockery::close();
        parent::tearDown();
    }

    // --- search() tests ---

    public function testSearchWithBoardParameter(): void
    {
        $this->mockRequest->shouldReceive('getQueryParams')
            ->once()
            ->andReturn(['q' => 'test query', 'board' => 'tech', 'page' => '1']);

        $searchResults = ['results' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0];
        $this->mockSearchService->shouldReceive('search')
            ->once()
            ->with('tech', 'test query', 1)
            ->andReturn($searchResults);

        $psr7Response = Mockery::mock(ResponseInterface::class);
        $this->mockResponse->shouldReceive('json')
            ->once()
            ->with($searchResults)
            ->andReturn($psr7Response);

        $result = $this->controller->search($this->mockRequest);
        $this->assertSame($psr7Response, $result);
    }

    public function testSearchWithoutBoardSearchesAll(): void
    {
        $this->mockRequest->shouldReceive('getQueryParams')
            ->once()
            ->andReturn(['q' => 'test query']);

        $searchResults = ['results' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0];
        $this->mockSearchService->shouldReceive('searchAll')
            ->once()
            ->with('test query', 1)
            ->andReturn($searchResults);

        $psr7Response = Mockery::mock(ResponseInterface::class);
        $this->mockResponse->shouldReceive('json')
            ->once()
            ->with($searchResults)
            ->andReturn($psr7Response);

        $result = $this->controller->search($this->mockRequest);
        $this->assertSame($psr7Response, $result);
    }

    public function testSearchRejectsShortQuery(): void
    {
        $this->mockRequest->shouldReceive('getQueryParams')
            ->once()
            ->andReturn(['q' => 'a']);

        $psr7Response = Mockery::mock(ResponseInterface::class);
        $this->mockResponse->shouldReceive('json')
            ->once()
            ->with(['error' => 'Query must be at least 2 characters'])
            ->andReturn($psr7Response);

        $result = $this->controller->search($this->mockRequest);
        $this->assertSame($psr7Response, $result);
    }

    public function testSearchRejectsEmptyQuery(): void
    {
        $this->mockRequest->shouldReceive('getQueryParams')
            ->once()
            ->andReturn([]);

        $psr7Response = Mockery::mock(ResponseInterface::class);
        $this->mockResponse->shouldReceive('json')
            ->once()
            ->with(['error' => 'Query must be at least 2 characters'])
            ->andReturn($psr7Response);

        $result = $this->controller->search($this->mockRequest);
        $this->assertSame($psr7Response, $result);
    }

    public function testSearchClampsPageToMinimumOne(): void
    {
        $this->mockRequest->shouldReceive('getQueryParams')
            ->once()
            ->andReturn(['q' => 'test query', 'board' => 'tech', 'page' => '-5']);

        $searchResults = ['results' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0];
        $this->mockSearchService->shouldReceive('search')
            ->once()
            ->with('tech', 'test query', 1) // page clamped to 1
            ->andReturn($searchResults);

        $psr7Response = Mockery::mock(ResponseInterface::class);
        $this->mockResponse->shouldReceive('json')
            ->once()
            ->with($searchResults)
            ->andReturn($psr7Response);

        $result = $this->controller->search($this->mockRequest);
        $this->assertSame($psr7Response, $result);
    }

    public function testSearchHandlesNonNumericPage(): void
    {
        $this->mockRequest->shouldReceive('getQueryParams')
            ->once()
            ->andReturn(['q' => 'test query', 'board' => 'tech', 'page' => 'abc']);

        $searchResults = ['results' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0];
        $this->mockSearchService->shouldReceive('search')
            ->once()
            ->with('tech', 'test query', 1) // defaults to page 1
            ->andReturn($searchResults);

        $psr7Response = Mockery::mock(ResponseInterface::class);
        $this->mockResponse->shouldReceive('json')
            ->once()
            ->with($searchResults)
            ->andReturn($psr7Response);

        $result = $this->controller->search($this->mockRequest);
        $this->assertSame($psr7Response, $result);
    }

    public function testSearchWithEmptyBoardSearchesAll(): void
    {
        $this->mockRequest->shouldReceive('getQueryParams')
            ->once()
            ->andReturn(['q' => 'test query', 'board' => '']);

        $searchResults = ['results' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0];
        $this->mockSearchService->shouldReceive('searchAll')
            ->once()
            ->with('test query', 1)
            ->andReturn($searchResults);

        $psr7Response = Mockery::mock(ResponseInterface::class);
        $this->mockResponse->shouldReceive('json')
            ->once()
            ->with($searchResults)
            ->andReturn($psr7Response);

        $result = $this->controller->search($this->mockRequest);
        $this->assertSame($psr7Response, $result);
    }

    // --- index() tests ---

    public function testIndexPostSuccessfully(): void
    {
        $this->mockRequest->shouldReceive('input')->with('board')->andReturn('tech');
        $this->mockRequest->shouldReceive('input')->with('thread_id')->andReturn(1);
        $this->mockRequest->shouldReceive('input')->with('post_id')->andReturn(42);
        $this->mockRequest->shouldReceive('input')->with('content')->andReturn('Hello world');
        $this->mockRequest->shouldReceive('input')->with('subject')->andReturn(null);

        $this->mockSearchService->shouldReceive('indexPost')
            ->once()
            ->with('tech', 1, 42, 'Hello world', null);

        $psr7Response = Mockery::mock(ResponseInterface::class);
        $this->mockResponse->shouldReceive('json')
            ->once()
            ->with(['status' => 'indexed'])
            ->andReturn($psr7Response);

        $result = $this->controller->index($this->mockRequest);
        $this->assertSame($psr7Response, $result);
    }

    public function testIndexRejectsInvalidInput(): void
    {
        $this->mockRequest->shouldReceive('input')->with('board')->andReturn(null);
        $this->mockRequest->shouldReceive('input')->with('thread_id')->andReturn(null);
        $this->mockRequest->shouldReceive('input')->with('post_id')->andReturn(null);
        $this->mockRequest->shouldReceive('input')->with('content')->andReturn(null);
        $this->mockRequest->shouldReceive('input')->with('subject')->andReturn(null);

        $psr7Response = Mockery::mock(ResponseInterface::class);
        $this->mockResponse->shouldReceive('json')
            ->once()
            ->with(['error' => 'Invalid input'])
            ->andReturn($psr7Response);

        $result = $this->controller->index($this->mockRequest);
        $this->assertSame($psr7Response, $result);
    }

    // --- remove() tests ---

    public function testRemovePostSuccessfully(): void
    {
        $this->mockRequest->shouldReceive('input')->with('board')->andReturn('tech');
        $this->mockRequest->shouldReceive('input')->with('post_id')->andReturn(42);

        $this->mockSearchService->shouldReceive('removePost')
            ->once()
            ->with('tech', 42);

        $psr7Response = Mockery::mock(ResponseInterface::class);
        $this->mockResponse->shouldReceive('json')
            ->once()
            ->with(['status' => 'removed'])
            ->andReturn($psr7Response);

        $result = $this->controller->remove($this->mockRequest);
        $this->assertSame($psr7Response, $result);
    }

    public function testRemoveRejectsInvalidInput(): void
    {
        $this->mockRequest->shouldReceive('input')->with('board')->andReturn(null);
        $this->mockRequest->shouldReceive('input')->with('post_id')->andReturn(null);

        $psr7Response = Mockery::mock(ResponseInterface::class);
        $this->mockResponse->shouldReceive('json')
            ->once()
            ->with(['error' => 'board and post_id required'])
            ->andReturn($psr7Response);

        $result = $this->controller->remove($this->mockRequest);
        $this->assertSame($psr7Response, $result);
    }
}
