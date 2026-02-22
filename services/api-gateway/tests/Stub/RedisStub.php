<?php
declare(strict_types=1);

namespace App\Tests\Stub;

use Hyperf\Redis\Redis;

/**
 * Redis stub for unit tests.
 *
 * Hyperf\Redis\Redis uses __call() magic, so PHPUnit mocks cannot stub
 * individual method names. This stub extends Redis (bypass-finals handles
 * the final keyword) with a no-op constructor and in-memory storage.
 */
class RedisStub extends Redis
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** @var array<string, array<string, float>> */
    private array $sortedSets = [];

    /** @var array<string, array<string, bool>> */
    private array $sets = [];

    /** @var \Closure|null Custom eval handler for tests */
    private ?\Closure $evalHandler = null;

    public function __construct()
    {
        // No-op: skip parent constructor which requires PoolFactory
    }

    /**
     * Override __call to route to our explicit methods instead of the pool.
     */
    public function __call(string $name, array $arguments): mixed
    {
        $method = strtolower($name);
        return match ($method) {
            'get'               => $this->get(...$arguments),
            'setex'             => $this->setex(...$arguments),
            'set'               => $this->set(...$arguments),
            'del'               => $this->del(...$arguments),
            'exists'            => $this->exists(...$arguments),
            'incr'              => $this->incr(...$arguments),
            'expire'            => $this->expire(...$arguments),
            'zadd'              => $this->zAdd(...$arguments),
            'zcard'             => $this->zCard(...$arguments),
            'zremrangebyscore'  => $this->zRemRangeByScore(...$arguments),
            'sismember'         => $this->sIsMember(...$arguments),
            'sadd'              => $this->sAdd(...$arguments),
            'eval'              => $this->evalScript(...$arguments),
            default             => null,
        };
    }

    public function get(string $key): string|false
    {
        return $this->store[$key] ?? false;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function set(string $key, string $value): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function del(string ...$keys): int
    {
        $count = 0;
        foreach ($keys as $key) {
            if (isset($this->store[$key])) {
                unset($this->store[$key]);
                $count++;
            }
        }
        return $count;
    }

    public function exists(string $key): int
    {
        return isset($this->store[$key]) ? 1 : 0;
    }

    public function incr(string $key): int
    {
        $val = (int) ($this->store[$key] ?? 0);
        $this->store[$key] = (string) ($val + 1);
        return $val + 1;
    }

    public function expire(string $key, int $ttl): bool
    {
        return true;
    }

    public function zAdd(string $key, float|int $score, string $member): int
    {
        $this->sortedSets[$key][$member] = (float) $score;
        return 1;
    }

    public function zCard(string $key): int
    {
        return count($this->sortedSets[$key] ?? []);
    }

    public function zRemRangeByScore(string $key, string $min, string $max): int
    {
        if (!isset($this->sortedSets[$key])) {
            return 0;
        }
        $minVal = $min === '-inf' ? -INF : (float) $min;
        $maxVal = $max === '+inf' ? INF : (float) $max;
        $removed = 0;
        foreach ($this->sortedSets[$key] as $member => $score) {
            if ($score >= $minVal && $score <= $maxVal) {
                unset($this->sortedSets[$key][$member]);
                $removed++;
            }
        }
        return $removed;
    }

    public function sIsMember(string $key, string $member): bool
    {
        return $this->sets[$key][$member] ?? false;
    }

    public function sAdd(string $key, string ...$members): int
    {
        $added = 0;
        foreach ($members as $member) {
            if (!isset($this->sets[$key][$member])) {
                $this->sets[$key][$member] = true;
                $added++;
            }
        }
        return $added;
    }

    /**
     * Eval handler — delegates to custom handler if set, otherwise returns default.
     */
    public function evalScript(string $script, array $args = [], int $numKeys = 0): mixed
    {
        if ($this->evalHandler !== null) {
            return ($this->evalHandler)($script, $args, $numKeys);
        }
        // Default: return [0, 1] (allowed)
        return [0, 1];
    }

    /* ──────────────────────────────────────
     * Test helpers
     * ────────────────────────────────────── */

    /** Direct set for test setup. */
    public function _set(string $key, mixed $value): void
    {
        $this->store[$key] = $value;
    }

    /** Add a member to a set (for test setup). */
    public function _sAdd(string $key, string $member): void
    {
        $this->sets[$key][$member] = true;
    }

    /** Add a member to a sorted set (for test setup). */
    public function _zAdd(string $key, float $score, string $member): void
    {
        $this->sortedSets[$key][$member] = $score;
    }

    /** Set a custom eval handler for Lua script testing. */
    public function setEvalHandler(\Closure $handler): void
    {
        $this->evalHandler = $handler;
    }

    /** Throw on next eval call. */
    public function setEvalThrows(\Throwable $e): void
    {
        $this->evalHandler = function () use ($e): never {
            throw $e;
        };
    }
}
