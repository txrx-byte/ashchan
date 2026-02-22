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

class AppExceptionHandler extends ExceptionHandler
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

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

    public function isValid(Throwable $throwable): bool
    {
        return true; // Catch ALL exceptions
    }
}
