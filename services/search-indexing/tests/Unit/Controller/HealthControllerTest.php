<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\HealthController;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class HealthControllerTest extends TestCase
{
    private HealthController $controller;
    private HttpResponse&MockInterface $mockResponse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockResponse = Mockery::mock(HttpResponse::class);
        $this->controller = new HealthController($this->mockResponse);
    }

    protected function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        Mockery::close();
        parent::tearDown();
    }

    public function testCheckReturnsOkStatus(): void
    {
        $psr7Response = Mockery::mock(ResponseInterface::class);

        $this->mockResponse->shouldReceive('json')
            ->once()
            ->with(['status' => 'ok'])
            ->andReturn($psr7Response);

        $result = $this->controller->check();

        $this->assertSame($psr7Response, $result);
    }
}
