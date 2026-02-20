<?php

declare(strict_types=1);

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

