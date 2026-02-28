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

/**
 * Contract for site configuration access.
 *
 * @see SiteConfigService For concrete implementation
 */
interface SiteConfigServiceInterface
{
    /**
     * Get a string configuration value.
     *
     * @param string $key Configuration key
     * @param string $default Default value if key not found
     * @return string Configuration value or default
     */
    public function get(string $key, string $default = ''): string;

    /**
     * Get an integer configuration value.
     *
     * @param string $key Configuration key
     * @param int $default Default value if key not found
     * @return int Configuration value or default
     */
    public function getInt(string $key, int $default = 0): int;

    /**
     * Get a float configuration value.
     *
     * @param string $key Configuration key
     * @param float $default Default value if key not found
     * @return float Configuration value or default
     */
    public function getFloat(string $key, float $default = 0.0): float;

    /**
     * Get a boolean configuration value.
     *
     * @param string $key Configuration key
     * @param bool $default Default value if key not found
     * @return bool Configuration value or default
     */
    public function getBool(string $key, bool $default = false): bool;

    /**
     * Get a list configuration value (comma-separated).
     *
     * @param string $key Configuration key
     * @param string $default Default value if key not found
     * @return string[] Array of values
     */
    public function getList(string $key, string $default = ''): array;
}
