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


namespace App\Middleware;

use Hyperf\Contract\ConfigInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function Hyperf\Support\env;

/**
 * CORS middleware for cross-origin requests.
 * Uses Hyperf config injection for Swoole-safe env access.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private readonly array $allowedOrigins;

    public function __construct(ConfigInterface $config)
    {
        $origins = (string) ($config->get('cors.origins') ?: env('CORS_ORIGINS', '*'));
        $this->allowedOrigins = $origins === '*' ? ['*'] : array_map('trim', explode(',', $origins));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        // Handle preflight
        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Hyperf\HttpMessage\Server\Response();
            return $this->addCorsHeaders($response, $origin)->withStatus(204);
        }

        $response = $handler->handle($request);
        return $this->addCorsHeaders($response, $origin);
    }

    private function addCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        $allowOrigin = in_array('*', $this->allowedOrigins)
            ? '*'
            : (in_array($origin, $this->allowedOrigins) ? $origin : '');

        if (!$allowOrigin) return $response;

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Captcha-Token, X-CSRF-Token')
            ->withHeader('Access-Control-Max-Age', '3600');

        // Only send credentials header when origin is explicitly allowed (not wildcard)
        if ($allowOrigin !== '*') {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
