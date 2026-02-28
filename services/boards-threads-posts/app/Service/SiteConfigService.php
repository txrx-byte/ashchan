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


namespace App\Service;

use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;

/**
 * Database-backed site configuration service.
 *
 * Reads from the shared `site_settings` table with Redis caching.
 * All site-configurable settings are managed by admins via the gateway's admin panel.
 *
 * Settings are cached in memory per-request and in Redis for 60 seconds
 * to minimize database queries.
 *
 * @see \App\Model\SiteSetting For database table structure
 */
final class SiteConfigService implements SiteConfigServiceInterface
{
    /**
     * Redis cache key for all settings
     */
    private const CACHE_KEY = 'site_config:all';

    /**
     * Redis cache TTL in seconds
     */
    private const CACHE_TTL = 60;

    /**
     * @var LoggerInterface Logger for configuration operations
     */
    private LoggerInterface $logger;

    /**
     * @var array<string, string>|null In-memory cache of all settings
     */
    private ?array $cache = null;

    /**
     * @param LoggerFactory $loggerFactory Logger factory
     * @param Redis $redis Redis client for caching
     */
    public function __construct(
        LoggerFactory $loggerFactory,
        private Redis $redis,
    ) {
        $this->logger = $loggerFactory->get('site-config');
    }

    /**
     * Get a string configuration value.
     *
     * @param string $key Configuration key
     * @param string $default Default value if key not found
     * @return string Configuration value or default
     */
    public function get(string $key, string $default = ''): string
    {
        $all = $this->loadAll();
        return $all[$key] ?? $default;
    }

    /**
     * Get an integer configuration value.
     *
     * @param string $key Configuration key
     * @param int $default Default value if key not found or invalid
     * @return int Configuration value or default
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return $value !== '' ? (int) $value : $default;
    }

    /**
     * Get a float configuration value.
     *
     * @param string $key Configuration key
     * @param float $default Default value if key not found or invalid
     * @return float Configuration value or default
     */
    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);
        return $value !== '' ? (float) $value : $default;
    }

    /**
     * Get a boolean configuration value.
     *
     * Recognized true values: 'true', '1', 'yes', 'on' (case-insensitive)
     *
     * @param string $key Configuration key
     * @param bool $default Default value if key not found
     * @return bool Configuration value or default
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default ? 'true' : 'false');
        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Get a list configuration value (comma-separated).
     *
     * @param string $key Configuration key
     * @param string $default Default value if key not found
     * @return string[] Array of trimmed list values
     */
    public function getList(string $key, string $default = ''): array
    {
        $value = $this->get($key, $default);
        if ($value === '') {
            return [];
        }
        return array_map('trim', explode(',', $value));
    }

    /**
     * Load all configuration values from cache or database.
     *
     * Uses two-level caching:
     * 1. In-memory cache (per-request)
     * 2. Redis cache (60 seconds)
     * 3. Database (fallback)
     *
     * @return array<string, string> All configuration key-value pairs
     */
    private function loadAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            $cached = $this->redis->get(self::CACHE_KEY);
            if (is_string($cached) && $cached !== '') {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    /** @var array<string, string> $decoded */
                    $this->cache = $decoded;
                    return $this->cache;
                }
            }
        } catch (\Throwable) {
            // Redis unavailable
        }

        try {
            $rows = Db::table('site_settings')->select(['key', 'value'])->get();
            $this->cache = [];
            foreach ($rows as $row) {
                /** @var object{key: string, value: string} $row */
                $this->cache[(string) $row->key] = (string) $row->value;
            }

            try {
                $json = json_encode($this->cache);
                if (is_string($json)) {
                    $this->redis->setex(self::CACHE_KEY, self::CACHE_TTL, $json);
                }
            } catch (\Throwable) {
                // Redis unavailable
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load site settings', ['error' => $e->getMessage()]);
            $this->cache = [];
        }

        return $this->cache;
    }
}
