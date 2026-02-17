<?php
declare(strict_types=1);

use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Framework\Application;

require_once dirname(__DIR__) . '/vendor/autoload.php';

define('BASE_PATH', dirname(__DIR__));

$container = new Container((new DefinitionSourceFactory(true))());
$app = $container->get(Application::class);
$app->run();
