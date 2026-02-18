<?php
declare(strict_types=1);

namespace App\Exception\Handler;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class AppExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        // Log the full error to stdout so we can see it in docker logs
        $msg = sprintf(
            "[AppExceptionHandler] %s: %s in %s:%d\n%s",
            get_class($throwable),
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine(),
            $throwable->getTraceAsString()
        );
        fwrite(STDERR, $msg . "\n");

        $this->stopPropagation();

        $json = json_encode([
            'error' => $throwable->getMessage(),
            'class' => get_class($throwable),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
        ]);

        return $response
            ->withStatus(500)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new SwooleStream($json ?: ''));
    }

    public function isValid(Throwable $throwable): bool
    {
        return true; // Catch ALL exceptions
    }
}
