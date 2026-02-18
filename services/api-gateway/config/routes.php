<?php
declare(strict_types=1);

use Hyperf\HttpServer\Router\Router;
use App\Controller\HealthController;
use App\Controller\GatewayController;
use App\Controller\FrontendController;

Router::get('/health', [HealthController::class, 'check']);

// Static files (dev mode)
Router::get('/static/{path:.*}', [FrontendController::class, 'staticFile']);

// Media proxy
Router::get('/media/{path:.*}', [GatewayController::class, 'proxyMedia']);

// Homepage
Router::get('/', [FrontendController::class, 'home']);

// Board pages (must be before API catch-all)
Router::get('/{slug}/catalog', [FrontendController::class, 'catalog']);
Router::get('/{slug}/archive', [FrontendController::class, 'archive']);
Router::get('/{slug}/thread/{id:\d+}', [FrontendController::class, 'thread']);
Router::get('/{slug}/', [FrontendController::class, 'board']);
Router::get('/{slug}', [FrontendController::class, 'board']);

// Frontend form submissions (redirect handlers)
Router::post('/{slug}/threads', [FrontendController::class, 'createThread']);
Router::post('/{slug}/thread/{id:\d+}/posts', [FrontendController::class, 'createPost']);

// API proxy – catch-all
Router::addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
    '/api/v1/{path:.*}', [GatewayController::class, 'proxy']);
