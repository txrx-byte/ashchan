<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Test double for SiteConfigService (which is final and cannot be mocked by Mockery).
 *
 * Provides configurable key-value storage for tests.
 */
final class FakeSiteConfigService
{
    /** @var array<string, string> */
    private array $settings;

    /**
     * @param array<string, string> $settings
     */
    public function __construct(array $settings = [])
    {
        $this->settings = array_merge($this->defaults(), $settings);
    }

    /**
     * @return array<string, string>
     */
    private function defaults(): array
    {
        return [
            'object_storage_bucket' => 'test-bucket',
            'object_storage_endpoint' => 'http://127.0.0.1:1',
            'object_storage_access_key' => 'testkey',
            'object_storage_secret_key' => 'testsecret',
            'max_file_size' => '4194304',
            'allowed_mimes' => 'image/jpeg,image/png,image/gif,image/webp',
            'thumb_max_width' => '250',
            'thumb_max_height' => '250',
            'upload_connect_timeout' => '1',
            'upload_timeout' => '2',
            'local_storage_path' => sys_get_temp_dir() . '/media-test-' . getmypid(),
        ];
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->settings[$key] ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return $value !== '' ? (int) $value : $default;
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);
        return $value !== '' ? (float) $value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default ? 'true' : 'false');
        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }

    /**
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
     * Override a setting for the current test.
     */
    public function set(string $key, string $value): void
    {
        $this->settings[$key] = $value;
    }
}
