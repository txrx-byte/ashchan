<?php
declare(strict_types=1);

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
