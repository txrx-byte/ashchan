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
use App\Controller\ModerationController;

Router::get('/health', [HealthController::class, 'check']);

// Reports
Router::post('/api/v1/reports', [ModerationController::class, 'createReport']);
Router::get('/api/v1/reports', [ModerationController::class, 'listReports']);
Router::post('/api/v1/reports/{id:\d+}/decide', [ModerationController::class, 'decide']);
Router::post('/api/v1/reports/{id:\d+}/dismiss', [ModerationController::class, 'dismiss']);

// Spam
Router::post('/api/v1/spam/check', [ModerationController::class, 'spamCheck']);

// Captcha
Router::get('/api/v1/captcha', [ModerationController::class, 'captcha']);
Router::post('/api/v1/captcha/verify', [ModerationController::class, 'verifyCaptcha']);

// StopForumSpam
use App\Controller\StopForumSpamController;
Router::post('/api/v1/spam/sfs-check', [StopForumSpamController::class, 'check']);
Router::post('/api/v1/spam/sfs-report', [StopForumSpamController::class, 'report']);

// Spur IP Intelligence
use App\Controller\SpurController;
Router::post('/api/v1/spam/spur-lookup', [SpurController::class, 'lookup']);
Router::post('/api/v1/spam/spur-evaluate', [SpurController::class, 'evaluate']);
Router::get('/api/v1/spam/spur-status', [SpurController::class, 'status']);

// Site Settings (Admin Feature Toggles)
use App\Controller\SiteSettingsController;
Router::get('/api/v1/admin/settings', [SiteSettingsController::class, 'index']);
Router::get('/api/v1/admin/settings/{key}', [SiteSettingsController::class, 'show']);
Router::put('/api/v1/admin/settings/{key}', [SiteSettingsController::class, 'update']);
Router::get('/api/v1/admin/settings/{key}/audit', [SiteSettingsController::class, 'audit']);
