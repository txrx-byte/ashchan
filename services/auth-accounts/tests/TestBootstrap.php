<?php
declare(strict_types=1);

namespace App\Tests;

use Hyperf\Context\ApplicationContext;
use Psr\Container\ContainerInterface;

/**
 * Shared test bootstrap — sets up a minimal Hyperf container so that
 * Eloquent model constructors (which fire events via the container) work
 * without a full Swoole runtime.
 */
final class TestBootstrap
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return null;
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        ApplicationContext::setContainer($container);
        self::$initialized = true;
    }
}
