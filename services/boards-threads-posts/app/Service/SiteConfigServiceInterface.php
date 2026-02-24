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
 */
interface SiteConfigServiceInterface
{
    public function get(string $key, string $default = ''): string;

    public function getInt(string $key, int $default = 0): int;

    public function getFloat(string $key, float $default = 0.0): float;

    public function getBool(string $key, bool $default = false): bool;

    /**
     * @return string[]
     */
    public function getList(string $key, string $default = ''): array;
}
