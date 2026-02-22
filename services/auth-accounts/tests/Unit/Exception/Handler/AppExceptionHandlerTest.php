<?php
declare(strict_types=1);

namespace App\Tests\Unit\Exception\Handler;

use App\Exception\Handler\AppExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \App\Exception\Handler\AppExceptionHandler
 */
final class AppExceptionHandlerTest extends TestCase
{
    private AppExceptionHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new AppExceptionHandler();
    }

    /**
     * Helper to build a mock response chain: withStatus → withHeader → withBody.
     *
     * @return array{final: ResponseInterface, bodyCapture: \Closure}
     */
    private function buildResponseChain(): array
    {
        $finalResponse = $this->createMock(ResponseInterface::class);
        $headerResponse = $this->createMock(ResponseInterface::class);
        $statusResponse = $this->createMock(ResponseInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $capturedBody = null;

        $response->method('withStatus')->with(500)->willReturn($statusResponse);
        $statusResponse->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturn($headerResponse);
        $headerResponse->method('withBody')
            ->willReturnCallback(function ($stream) use (&$capturedBody, $finalResponse) {
                $capturedBody = (string) $stream;
                return $finalResponse;
            });

        return [
            'response' => $response,
            'final' => $finalResponse,
            'getBody' => function () use (&$capturedBody): ?string {
                return $capturedBody;
            },
        ];
    }

    /* ──────────────────────────────────────
     * handle()
     * ────────────────────────────────────── */

    public function testHandleReturns500WithGenericError(): void
    {
        $chain = $this->buildResponseChain();
        $result = $this->handler->handle(
            new \RuntimeException('Sensitive internal details'),
            $chain['response']
        );

        $this->assertSame($chain['final'], $result);

        $body = ($chain['getBody'])();
        $this->assertNotNull($body);
        $decoded = json_decode($body, true);
        $this->assertSame('Internal server error', $decoded['error']);
    }

    public function testHandleDoesNotLeakExceptionDetails(): void
    {
        $chain = $this->buildResponseChain();
        $this->handler->handle(
            new \RuntimeException('Database password: secret123'),
            $chain['response']
        );

        $body = ($chain['getBody'])();
        $this->assertNotNull($body);
        $this->assertStringNotContainsString('secret123', $body);
        $this->assertStringNotContainsString('Database password', $body);
    }

    public function testHandleDoesNotLeakStackTrace(): void
    {
        $chain = $this->buildResponseChain();
        $this->handler->handle(
            new \RuntimeException('error in /etc/passwd'),
            $chain['response']
        );

        $body = ($chain['getBody'])();
        $this->assertStringNotContainsString('/etc/passwd', $body);
        $this->assertStringNotContainsString('trace', strtolower($body));
    }

    /* ──────────────────────────────────────
     * isValid()
     * ────────────────────────────────────── */

    public function testIsValidReturnsTrueForRuntimeException(): void
    {
        $this->assertTrue($this->handler->isValid(new \RuntimeException('test')));
    }

    public function testIsValidReturnsTrueForLogicException(): void
    {
        $this->assertTrue($this->handler->isValid(new \LogicException('test')));
    }

    public function testIsValidReturnsTrueForError(): void
    {
        $this->assertTrue($this->handler->isValid(new \Error('test')));
    }

    public function testIsValidReturnsTrueForTypeError(): void
    {
        $this->assertTrue($this->handler->isValid(new \TypeError('test')));
    }

    /* ──────────────────────────────────────
     * Propagation
     * ────────────────────────────────────── */

    public function testHandleStopsPropagation(): void
    {
        $chain = $this->buildResponseChain();
        $this->handler->handle(new \RuntimeException('test'), $chain['response']);

        $this->assertTrue($this->handler->isPropagationStopped());
    }

    /* ──────────────────────────────────────
     * Various exception types all produce 500
     * ────────────────────────────────────── */

    /**
     * @dataProvider exceptionProvider
     */
    public function testAllExceptionTypesReturn500(\Throwable $exception): void
    {
        $chain = $this->buildResponseChain();
        $result = $this->handler->handle($exception, $chain['response']);
        $this->assertSame($chain['final'], $result);
    }

    /** @return array<string, array{\Throwable}> */
    public static function exceptionProvider(): array
    {
        return [
            'RuntimeException' => [new \RuntimeException('runtime')],
            'InvalidArgumentException' => [new \InvalidArgumentException('bad arg')],
            'LogicException' => [new \LogicException('logic')],
            'TypeError' => [new \TypeError('type')],
            'Error' => [new \Error('fatal')],
            'DomainException' => [new \DomainException('domain')],
        ];
    }
}
