<?php
declare(strict_types=1);

use Hyperf\HttpServer\Router\Router;
use App\Controller\HealthController;
use App\Controller\BoardController;
use App\Controller\ThreadController;

Router::get('/health', [HealthController::class, 'check']);

// Boards
Router::get('/api/v1/boards', [BoardController::class, 'list']);
Router::get('/api/v1/boards/{slug}', [BoardController::class, 'show']);
Router::get('/api/v1/blotter', [BoardController::class, 'blotter']);

// Threads
Router::get('/api/v1/boards/{slug}/threads', [ThreadController::class, 'index']);
Router::get('/api/v1/boards/{slug}/catalog', [ThreadController::class, 'catalog']);
Router::get('/api/v1/boards/{slug}/archive', [ThreadController::class, 'archive']);
Router::get('/api/v1/boards/{slug}/threads/{id:\d+}', [ThreadController::class, 'show']);
Router::post('/api/v1/boards/{slug}/threads', [ThreadController::class, 'create']);
Router::post('/api/v1/boards/{slug}/threads/{id:\d+}/posts', [ThreadController::class, 'reply']);
Router::get('/api/v1/boards/{slug}/threads/{id:\d+}/posts', [ThreadController::class, 'newPosts']);

// Post actions
Router::post('/api/v1/posts/delete', [ThreadController::class, 'deletePost']);
