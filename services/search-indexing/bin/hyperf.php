#!/usr/bin/env php
<?php
declare(strict_types=1);

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
error_reporting(E_ALL);

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

require BASE_PATH . '/vendor/autoload.php';

// Load .env if present (for local dev; docker-compose provides env_file)
if (file_exists(BASE_PATH . '/.env')) {
    (Dotenv\Dotenv::createMutable(BASE_PATH))->safeLoad();
}

$container = require BASE_PATH . '/config/container.php';

$application = $container->get(\Hyperf\Contract\ApplicationInterface::class);
$application->run();
