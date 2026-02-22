<?php
declare(strict_types=1);

/*
 * Copyright 2026 txrx-byte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


namespace App\Exception\Handler;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Global exception handler — catches all unhandled exceptions.
 *
 * Security: Never exposes stack traces, file paths, or error details to clients.
 * All internal details are logged to STDERR for ops visibility.
 * The client always receives a generic 500 JSON response.
 */
final class AppExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        // Log full details to STDERR for ops/debugging — never to the client.
        // Format: [ExceptionClass] message in /path/to/file.php:line
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

        // SECURITY: Return only a generic error message to prevent information leakage.
        // Internal details (class name, message, stack trace) are logged above.
        return $response
            ->withStatus(500)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new SwooleStream((string) json_encode([
                'error' => 'Internal server error',
            ])));
    }

    /**
     * This handler catches ALL exception types as the last line of defense.
     */
    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
