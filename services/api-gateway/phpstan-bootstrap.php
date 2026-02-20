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


if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require BASE_PATH . '/vendor/autoload.php';

// PHPStan stubs for Hyperf runtime helpers
if (!function_exists('env')) {
    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}
if (!function_exists('now')) {
    function now(): \Carbon\Carbon
    {
        return new \Carbon\Carbon();
    }
}

