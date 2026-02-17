<?php
declare(strict_types=1);

use Hyperf\HttpServer\Router\Router;
use App\Controller\HealthController;
use App\Controller\SearchController;

Router::get('/health', [HealthController::class, 'check']);

// Search
Router::get('/api/v1/search', [SearchController::class, 'search']);
Router::post('/api/v1/search/index', [SearchController::class, 'index']);
Router::delete('/api/v1/search/index', [SearchController::class, 'remove']);
