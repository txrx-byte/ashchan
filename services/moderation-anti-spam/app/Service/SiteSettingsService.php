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
use Psr\Log\LoggerInterface;

/**
 * Service for managing runtime site settings (feature toggles).
 *
 * Provides a database-backed key-value store that admins can update
 * at runtime without requiring service restarts or env var changes.
 * All changes are audit-logged.
 */
class SiteSettingsService
{
    private LoggerInterface $logger;

    /**
     * In-memory cache of settings for the current request lifecycle.
     *
     * @var array<string, string>|null
     */
    private ?array $cache = null;

    public function __construct(
        LoggerFactory $loggerFactory
    ) {
        $this->logger = $loggerFactory->get('site-settings');
    }

    /**
     * Get a setting value by key.
     *
     * @param string $key The setting key
     * @param string $default Default value if not found
     * @return string The setting value
     */
    public function get(string $key, string $default = ''): string
    {
        $settings = $this->loadAll();
        return $settings[$key] ?? $default;
    }

    /**
     * Check if a boolean feature toggle is enabled.
     *
     * @param string $key The feature toggle key
     * @param bool $default Default state if not found
     * @return bool Whether the feature is enabled
     */
    public function isFeatureEnabled(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default ? 'true' : 'false');
        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Set a setting value.
     *
     * @param string $key The setting key
     * @param string $value The new value
     * @param int|null $changedBy Staff user ID who made the change
     * @param string $reason Optional reason for the change
     */
    public function set(string $key, string $value, ?int $changedBy = null, string $reason = ''): void
    {
        $oldValue = $this->get($key);

        Db::table('site_settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => $value,
                'updated_by' => $changedBy,
                'updated_at' => now(),
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

        // Invalidate cache
        $this->cache = null;

        $this->logger->info('Site setting updated', [
            'key' => $key,
            'old_value' => $oldValue,
            'new_value' => $value,
            'changed_by' => $changedBy,
            'reason' => $reason,
        ]);
    }

    /**
     * Get all site settings.
     *
     * @return array<string, array{value: string, description: string, updated_at: string|null}>
     */
    public function getAll(): array
    {
        $rows = Db::table('site_settings')
            ->select(['key', 'value', 'description', 'updated_at', 'updated_by'])
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->key] = [
                'value' => $row->value,
                'description' => $row->description ?? '',
                'updated_at' => $row->updated_at,
                'updated_by' => $row->updated_by,
            ];
        }
        return $result;
    }

    /**
     * Get audit history for a setting.
     *
     * @param string $key The setting key
     * @param int $limit Number of records to return
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
            $result[] = [
                'old_value' => $row->old_value,
                'new_value' => $row->new_value,
                'changed_by' => $row->changed_by,
                'changed_at' => $row->changed_at,
                'reason' => $row->reason ?? '',
            ];
        }
        return $result;
    }

    /**
     * Load all settings into the in-memory cache.
     *
     * @return array<string, string>
     */
    private function loadAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            $rows = Db::table('site_settings')->select(['key', 'value'])->get();
            $this->cache = [];
            foreach ($rows as $row) {
                $this->cache[$row->key] = $row->value;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load site settings, using defaults', [
                'error' => $e->getMessage(),
            ]);
            $this->cache = [];
        }

        return $this->cache;
    }
}
