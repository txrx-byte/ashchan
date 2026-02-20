<?php
declare(strict_types=1);

return [
    'http' => [
        \App\Middleware\SecurityHeadersMiddleware::class,
        \App\Middleware\CorsMiddleware::class,
        \App\Middleware\RateLimitMiddleware::class,
        \App\Middleware\AuthMiddleware::class,
        \App\Middleware\StaffAuthMiddleware::class,
    ],
];
