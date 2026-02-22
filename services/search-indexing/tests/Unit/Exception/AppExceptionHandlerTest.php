<?php

declare(strict_types=1);

namespace Tests\Unit\Exception;

use App\Exception\Handler\AppExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class AppExceptionHandlerTest extends TestCase
{
    private AppExceptionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new AppExceptionHandler();
    }

    protected function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        Mockery::close();
        parent::tearDown();
    }

    public function testHandleReturns500WithJsonError(): void
    {
        $exception = new \RuntimeException('Something went wrong');

        $mockResponse = Mockery::mock(ResponseInterface::class);
        $mockResponse->shouldReceive('withStatus')
            ->once()
            ->with(500)
            ->andReturnSelf();
        $mockResponse->shouldReceive('withHeader')
            ->once()
            ->with('Content-Type', 'application/json')
            ->andReturnSelf();
        $mockResponse->shouldReceive('withBody')
            ->once()
            ->with(Mockery::on(function ($stream) {
                if ($stream instanceof SwooleStream) {
                    $body = (string) $stream;
                    $decoded = json_decode($body, true);
                    return $decoded === ['error' => 'Internal server error'];
                }
                return false;
            }))
            ->andReturnSelf();

        $result = $this->handler->handle($exception, $mockResponse);

        $this->assertSame($mockResponse, $result);
    }

    public function testIsValidReturnsTrueForAllExceptions(): void
    {
        $this->assertTrue($this->handler->isValid(new \RuntimeException('test')));
        $this->assertTrue($this->handler->isValid(new \InvalidArgumentException('test')));
        $this->assertTrue($this->handler->isValid(new \Exception('test')));
        $this->assertTrue($this->handler->isValid(new \Error('test')));
    }
}
