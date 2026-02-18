<?php
declare(strict_types=1);

use Hyperf\Context\ApplicationContext;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Psr\Container\ContainerInterface;

$container = new Container((new DefinitionSourceFactory())());

ApplicationContext::setContainer($container);

return $container;
