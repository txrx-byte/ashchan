<?php
declare(strict_types=1);

use Hyperf\HttpServer\Router\Router;
use App\Controller\HealthController;
use App\Controller\GatewayController;
use App\Controller\FrontendController;

Router::get('/health', [HealthController::class, 'check']);

// Static files (dev mode)
Router::get('/static/{path:.*}', [FrontendController::class, 'staticFile']);

// Homepage
Router::get('/', [FrontendController::class, 'home']);

// API proxy – catch-all
Router::addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
    '/api/v1/{path:.*}', [GatewayController::class, 'proxy']);
