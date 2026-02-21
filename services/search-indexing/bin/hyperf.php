#!/usr/bin/env php
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


ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
error_reporting(E_ALL);

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

require BASE_PATH . '/vendor/autoload.php';

// Load .env if present (for local dev; podman-compose provides env_file)
if (file_exists(BASE_PATH . '/.env')) {
    (Dotenv\Dotenv::createUnsafeMutable(BASE_PATH))->safeLoad();
}

$container = require BASE_PATH . '/config/container.php';

$application = $container->get(\Hyperf\Contract\ApplicationInterface::class);
$application->run();
