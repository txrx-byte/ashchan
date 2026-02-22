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

use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Global rate limiter – sliding window per IP.
 * More granular limits are enforced at the moderation-anti-spam service.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private const WINDOW   = 60;  // 60 seconds
    private const MAX_REQS = 120; // 120 requests per window

    public function __construct(
        private Redis $redis,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $this->getClientIp($request);
        $key = 'ratelimit:gateway:' . hash('sha256', $ip);
        $now = time();

        // Use Lua script for atomic rate limiting (prevents race conditions)
        $luaScript = <<<'LUA'
local key = KEYS[1]
local now = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local max_reqs = tonumber(ARGV[3])
local member = ARGV[4]
redis.call('ZREMRANGEBYSCORE', key, '-inf', now - window)
local count = redis.call('ZCARD', key)
if count >= max_reqs then
    return {count, 0}
end
redis.call('ZADD', key, now, member)
redis.call('EXPIRE', key, window)
return {count, 1}
LUA;

        $member = $now . ':' . random_int(0, 99999);
        /** @var array{int, int} $result */
        $result = $this->redis->eval(
            $luaScript,
            [$key, (string) $now, (string) self::WINDOW, (string) self::MAX_REQS, (string) $member],
            1
        );

        $count = (int) $result[0];
        $allowed = (int) $result[1];

        if ($allowed === 0) {
            $response = new \Hyperf\HttpMessage\Server\Response();
            $json = json_encode(['error' => 'Rate limit exceeded', 'retry_after' => self::WINDOW]);
            return $response
                ->withStatus(429)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string) self::WINDOW)
                ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream($json ?: ''));
        }

        $response = $handler->handle($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) self::MAX_REQS)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, self::MAX_REQS - $count - 1))
            ->withHeader('X-RateLimit-Reset', (string) ($now + self::WINDOW));
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        // Only trust X-Real-IP which is set by our trusted reverse proxy (nginx)
        $realIp = $request->getHeaderLine('X-Real-IP');
        if ($realIp !== '' && filter_var(trim($realIp), FILTER_VALIDATE_IP)) {
            return trim($realIp);
        }
        $params = $request->getServerParams();
        $remoteAddr = is_string($params['remote_addr'] ?? null) ? trim($params['remote_addr']) : '';
        if ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return $remoteAddr;
        }
        return '127.0.0.1';
    }
}
