<?php
declare(strict_types=1);

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

        // Clean old entries
        $this->redis->zRemRangeByScore($key, '-inf', (string) ($now - self::WINDOW));
        $count = (int) $this->redis->zCard($key);

        if ($count >= self::MAX_REQS) {
            $response = new \Hyperf\HttpMessage\Server\Response();
            $json = json_encode(['error' => 'Rate limit exceeded', 'retry_after' => self::WINDOW]);
            return $response
                ->withStatus(429)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string) self::WINDOW)
                ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream($json ?: ''));
        }

        $this->redis->zAdd($key, $now, (string) $now . ':' . random_int(0, 99999));
        $this->redis->expire($key, self::WINDOW);

        $response = $handler->handle($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) self::MAX_REQS)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, self::MAX_REQS - $count - 1))
            ->withHeader('X-RateLimit-Reset', (string) ($now + self::WINDOW));
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded) {
            $ips = explode(',', $forwarded);
            return trim($ips[0]);
        }
        $params = $request->getServerParams();
        $remoteAddr = $params['remote_addr'] ?? '127.0.0.1';
        return is_string($remoteAddr) ? $remoteAddr : '127.0.0.1';
    }
}
