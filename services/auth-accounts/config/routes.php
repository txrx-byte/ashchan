<?php
declare(strict_types=1);

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
