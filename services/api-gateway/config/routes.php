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

// Board pages (must be before API catch-all)
Router::get('/{slug}/catalog', [FrontendController::class, 'catalog']);
Router::get('/{slug}/archive', [FrontendController::class, 'archive']);
Router::get('/{slug}/thread/{id:\d+}', [FrontendController::class, 'thread']);
Router::get('/{slug}/', [FrontendController::class, 'board']);
Router::get('/{slug}', [FrontendController::class, 'board']);

// API proxy – catch-all
Router::addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
    '/api/v1/{path:.*}', [GatewayController::class, 'proxy']);
