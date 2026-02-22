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

use App\Service\SiteConfigService;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Global rate limiter – sliding window per IP.
 * More granular limits are enforced at the moderation-anti-spam service.
 * Window and max requests are configured via site_settings (admin panel).
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private int $window;
    private int $maxReqs;

    public function __construct(
        private Redis $redis,
        SiteConfigService $config,
    ) {
        $this->window  = $config->getInt('rate_limit_window', 60);
        $this->maxReqs = $config->getInt('rate_limit_max_requests', 120);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Skip rate limiting for health checks
        if ($request->getUri()->getPath() === '/health') {
            return $handler->handle($request);
        }

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

        try {
            /** @var array{int, int}|false $result */
            $result = $this->redis->eval(
                $luaScript,
                [$key, (string) $now, (string) $this->window, (string) $this->maxReqs, (string) $member],
                1
            );
        } catch (\Throwable) {
            // Redis unavailable — fail open to avoid blocking all traffic
            return $handler->handle($request);
        }

        if (!is_array($result)) {
            // Unexpected Redis response — fail open
            return $handler->handle($request);
        }

        $count = (int) $result[0];
        $allowed = (int) $result[1];

        if ($allowed === 0) {
            $response = new \Hyperf\HttpMessage\Server\Response();
            $json = json_encode(['error' => 'Rate limit exceeded', 'retry_after' => $this->window]);
            return $response
                ->withStatus(429)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string) $this->window)
                ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream($json ?: ''));
        }

        $response = $handler->handle($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxReqs)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $this->maxReqs - $count - 1))
            ->withHeader('X-RateLimit-Reset', (string) ($now + $this->window));
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
