<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\Handler\AppExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Unit tests for AppExceptionHandler.
 *
 * Covers: handle() returns 500 JSON, isValid() catches all exceptions.
 */
final class AppExceptionHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private AppExceptionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new AppExceptionHandler();
    }

    public function testHandleReturns500WithJsonBody(): void
    {
        $throwable = new \RuntimeException('Something broke');

        $innerResponse = Mockery::mock(ResponseInterface::class);
        $withHeaderResponse = Mockery::mock(ResponseInterface::class);
        $finalResponse = Mockery::mock(ResponseInterface::class);

        $innerResponse->shouldReceive('withStatus')
            ->with(500)
            ->once()
            ->andReturn($withHeaderResponse);

        $withHeaderResponse->shouldReceive('withHeader')
            ->with('Content-Type', 'application/json')
            ->once()
            ->andReturn($finalResponse);

        $finalResponse->shouldReceive('withBody')
            ->withArgs(function ($body) {
                if (!$body instanceof SwooleStream) {
                    return false;
                }
                $decoded = json_decode((string) $body, true);
                return is_array($decoded) && $decoded['error'] === 'Internal server error';
            })
            ->once()
            ->andReturn($finalResponse);

        $result = $this->handler->handle($throwable, $innerResponse);
        $this->assertSame($finalResponse, $result);
    }

    public function testIsValidReturnsTrueForAllExceptions(): void
    {
        $this->assertTrue($this->handler->isValid(new \RuntimeException('test')));
        $this->assertTrue($this->handler->isValid(new \InvalidArgumentException('test')));
        $this->assertTrue($this->handler->isValid(new \Exception('test')));
        $this->assertTrue($this->handler->isValid(new \Error('test')));
    }
}
