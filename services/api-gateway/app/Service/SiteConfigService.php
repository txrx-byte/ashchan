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
 * All site-configurable settings are stored in the `site_settings` table.
 * Values are cached in Redis (60s TTL) and in-memory per-request.
 * Changes via the admin panel take effect within 60 seconds without restart.
 *
 * Every change is audit-logged to `site_settings_audit_log`.
 */
final class SiteConfigService
{
    private const CACHE_KEY = 'site_config:all';
    private const CACHE_TTL = 60; // 60 seconds Redis cache

    private LoggerInterface $logger;

    /** @var array<string, string>|null In-memory cache for current request */
    private ?array $cache = null;

    public function __construct(
        LoggerFactory $loggerFactory,
        private Redis $redis,
    ) {
        $this->logger = $loggerFactory->get('site-config');
    }

    /**
     * Get a string setting value.
     */
    public function get(string $key, string $default = ''): string
    {
        $all = $this->loadAll();
        return $all[$key] ?? $default;
    }

    /**
     * Get an integer setting value.
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return $value !== '' ? (int) $value : $default;
    }

    /**
     * Get a float setting value.
     */
    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);
        return $value !== '' ? (float) $value : $default;
    }

    /**
     * Get a boolean setting value.
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default ? 'true' : 'false');
        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Get a comma-separated list as an array.
     *
     * @return string[]
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
     * Set a setting value with audit logging.
     */
    public function set(string $key, string $value, ?int $changedBy = null, string $reason = ''): void
    {
        $oldValue = $this->get($key);

        Db::table('site_settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => $value,
                'updated_by' => $changedBy,
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        // Audit log
        Db::table('site_settings_audit_log')->insert([
            'setting_key' => $key,
            'old_value' => $oldValue,
            'new_value' => $value,
            'changed_by' => $changedBy,
            'reason' => $reason,
        ]);

        // Invalidate caches
        $this->cache = null;
        try {
            $this->redis->del(self::CACHE_KEY);
        } catch (\Throwable) {
            // Redis unavailable — in-memory cache already cleared
        }

        $this->logger->info('Site setting updated', [
            'key' => $key,
            'old_value' => $oldValue,
            'new_value' => $value,
            'changed_by' => $changedBy,
            'reason' => $reason,
        ]);
    }

    /**
     * Get all settings with metadata, grouped by category.
     *
     * @return array<string, array{value: string, description: string, category: string, value_type: string, updated_at: string|null}>
     */
    public function getAll(): array
    {
        $rows = Db::table('site_settings')
            ->select(['key', 'value', 'description', 'category', 'value_type', 'updated_at', 'updated_by'])
            ->orderBy('category')
            ->orderBy('key')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            /** @var object{key: string, value: string, description: string|null, category: string, value_type: string, updated_at: string|null, updated_by: string|null} $row */
            $result[(string) $row->key] = [
                'value' => (string) $row->value,
                'description' => (string) ($row->description ?? ''),
                'category' => (string) $row->category,
                'value_type' => (string) $row->value_type,
                'updated_at' => $row->updated_at,
                'updated_by' => $row->updated_by,
            ];
        }
        return $result;
    }

    /**
     * Get audit history for a setting.
     *
     * @return array<int, array{old_value: string|null, new_value: string, changed_by: int|null, changed_at: string, reason: string}>
     */
    public function getAuditLog(string $key, int $limit = 50): array
    {
        $rows = Db::table('site_settings_audit_log')
            ->where('setting_key', $key)
            ->orderByDesc('changed_at')
            ->limit($limit)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            /** @var object{old_value: string|null, new_value: string, changed_by: int|null, changed_at: string, reason: string|null} $row */
            $result[] = [
                'old_value' => $row->old_value,
                'new_value' => (string) $row->new_value,
                'changed_by' => $row->changed_by,
                'changed_at' => (string) $row->changed_at,
                'reason' => (string) ($row->reason ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Load all settings into the in-memory cache (Redis-backed).
     *
     * @return array<string, string>
     */
    private function loadAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        // Try Redis cache first
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
            // Redis unavailable — fall through to DB
        }

        // Load from database
        try {
            $rows = Db::table('site_settings')->select(['key', 'value'])->get();
            $this->cache = [];
            foreach ($rows as $row) {
                /** @var object{key: string, value: string} $row */
                $this->cache[(string) $row->key] = (string) $row->value;
            }

            // Store in Redis cache
            try {
                $json = json_encode($this->cache);
                if (is_string($json)) {
                    $this->redis->setex(self::CACHE_KEY, self::CACHE_TTL, $json);
                }
            } catch (\Throwable) {
                // Redis unavailable — DB-only mode
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load site settings from DB, using defaults', [
                'error' => $e->getMessage(),
            ]);
            $this->cache = [];
        }

        return $this->cache;
    }
}
