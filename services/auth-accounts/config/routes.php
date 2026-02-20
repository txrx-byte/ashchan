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
use App\Controller\AuthController;

Router::get('/health', [HealthController::class, 'check']);

// Auth
Router::post('/api/v1/auth/login', [AuthController::class, 'login']);
Router::post('/api/v1/auth/logout', [AuthController::class, 'logout']);
Router::get('/api/v1/auth/validate', [AuthController::class, 'validate']);
Router::post('/api/v1/auth/register', [AuthController::class, 'register']);
Router::post('/api/v1/auth/ban', [AuthController::class, 'ban']);
Router::post('/api/v1/auth/unban', [AuthController::class, 'unban']);

// Consent & Data Rights
Router::post('/api/v1/consent', [AuthController::class, 'recordConsent']);
Router::post('/api/v1/auth/data-request', [AuthController::class, 'dataRequest']);
