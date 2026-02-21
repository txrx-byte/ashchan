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
Router::post('/api/v1/posts/lookup', [ThreadController::class, 'bulkLookup']);

// Staff actions
Router::delete('/api/v1/boards/{slug}/posts/{id:\d+}', [ThreadController::class, 'staffDeletePost']);
Router::post('/api/v1/boards/{slug}/threads/{id:\d+}/options', [ThreadController::class, 'staffThreadOptions']);
Router::post('/api/v1/boards/{slug}/posts/{id:\d+}/spoiler', [ThreadController::class, 'staffSpoiler']);
Router::get('/api/v1/boards/{slug}/threads/{id:\d+}/ips', [ThreadController::class, 'staffThreadIps']);

// Admin board management
Router::get('/api/v1/admin/boards', [BoardController::class, 'listAll']);
Router::post('/api/v1/admin/boards', [BoardController::class, 'store']);
Router::get('/api/v1/admin/boards/{slug}', [BoardController::class, 'adminShow']);
Router::post('/api/v1/admin/boards/{slug}', [BoardController::class, 'update']);
Router::delete('/api/v1/admin/boards/{slug}', [BoardController::class, 'destroy']);
