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
use Psr\Log\LoggerInterface;
use Throwable;

use function Hyperf\Support\env;

/**
 * Global exception handler for the application.
 *
 * Catches all unhandled exceptions and returns a standardized JSON error response.
 * Stack traces are only included in non-production environments for security.
 *
 * Error response format:
 *   {"error": "Internal server error"}
 *
 * Logging includes:
 * - Exception class name
 * - File and line number
 * - Stack trace (non-production only)
 *
 * @see docs/SECURITY.md §Error Handling
 */
class AppExceptionHandler extends ExceptionHandler
{
    /**
     * @param LoggerInterface $logger Logger for error reporting
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * Handle an exception and return an error response.
     *
     * Logs the exception with full context and returns a 500 response
     * with a generic error message (no internal details exposed).
     *
     * @param Throwable $throwable The caught exception
     * @param ResponseInterface $response Response builder
     * @return ResponseInterface HTTP 500 response with JSON error body
     */
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $context = [
            'exception' => get_class($throwable),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
        ];

        // Only include stack traces in non-production environments
        if (env('APP_ENV', 'production') !== 'production') {
            $context['trace'] = $throwable->getTraceAsString();
        }

        $this->logger->error($throwable->getMessage(), $context);

        $this->stopPropagation();

        return $response
            ->withStatus(500)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new SwooleStream(json_encode([
                'error' => 'Internal server error',
            ]) ?: '{"error":"Internal server error"}'));
    }

    /**
     * Determine if this handler should process the exception.
     *
     * Returns true for ALL exceptions, making this the global catch-all handler.
     *
     * @param Throwable $throwable The exception to check
     * @return bool Always true
     */
    public function isValid(Throwable $throwable): bool
    {
        return true; // Catch ALL exceptions
    }
}
