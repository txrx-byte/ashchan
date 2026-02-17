<?php
declare(strict_types=1);

use Hyperf\HttpServer\Router\Router;
use App\Controller\HealthController;
use App\Controller\MediaController;

Router::get('/health', [HealthController::class, 'check']);

// Media
Router::post('/api/v1/media/upload', [MediaController::class, 'upload']);
Router::post('/api/v1/media/ban', [MediaController::class, 'ban']);
