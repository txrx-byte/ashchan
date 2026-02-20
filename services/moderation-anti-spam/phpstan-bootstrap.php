<?php

declare(strict_types=1);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}
