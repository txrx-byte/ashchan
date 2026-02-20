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


namespace App\Controller;

use App\Service\SiteSettingsService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PutMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Admin API for managing site settings (feature toggles).
 *
 * These endpoints are intended for authenticated admin/staff users
 * to enable or disable features at runtime.
 */
#[Controller(prefix: '/api/v1/admin/settings')]
final class SiteSettingsController
{
    public function __construct(
        private SiteSettingsService $settingsService,
        private HttpResponse $response
    ) {}

    /**
     * List all site settings.
     *
     * GET /api/v1/admin/settings
     */
    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $settings = $this->settingsService->getAll();

        return $this->response->json([
            'settings' => $settings,
        ]);
    }

    /**
     * Update a site setting.
     *
     * PUT /api/v1/admin/settings/{key}
     * Body: { "value": "true", "reason": "Enabling Spur integration" }
     */
    #[PutMapping(path: '{key}')]
    public function update(RequestInterface $request, string $key): ResponseInterface
    {
        $value = $request->input('value');
        $reason = (string) $request->input('reason', '');

        if ($value === null) {
            return $this->response->json(['error' => 'Value is required'], 400);
        }

        // Extract staff user ID from request context (set by auth middleware)
        $staffUserId = $request->input('staff_user_id');
        $changedBy = is_numeric($staffUserId) ? (int) $staffUserId : null;

        $this->settingsService->set($key, (string) $value, $changedBy, $reason);

        return $this->response->json([
            'key' => $key,
            'value' => (string) $value,
            'status' => 'updated',
        ]);
    }

    /**
     * Get a specific setting.
     *
     * GET /api/v1/admin/settings/{key}
     */
    #[GetMapping(path: '{key}')]
    public function show(string $key): ResponseInterface
    {
        $value = $this->settingsService->get($key);

        return $this->response->json([
            'key' => $key,
            'value' => $value,
        ]);
    }

    /**
     * Get audit history for a setting.
     *
     * GET /api/v1/admin/settings/{key}/audit
     */
    #[GetMapping(path: '{key}/audit')]
    public function audit(string $key): ResponseInterface
    {
        $log = $this->settingsService->getAuditLog($key);

        return $this->response->json([
            'key' => $key,
            'history' => $log,
        ]);
    }
}
