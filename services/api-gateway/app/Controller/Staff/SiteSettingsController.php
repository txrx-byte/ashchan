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


namespace App\Controller\Staff;

use App\Service\SiteConfigService;
use App\Service\TemplateRenderer;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Admin controller for managing all site settings via the staff panel.
 *
 * Settings are stored in the `site_settings` table, grouped by category.
 * All changes are audit-logged and take effect within 60s (Redis cache TTL).
 */
final class SiteSettingsController
{
    public function __construct(
        private SiteConfigService $configService,
        private TemplateRenderer $renderer,
        private HttpResponse $response,
    ) {}

    /**
     * GET /staff/site-settings — Render the settings management page.
     */
    public function index(RequestInterface $request): ResponseInterface
    {
        $user = Context::get('staff_user');
        $level = $user['access_level'] ?? '';
        if (!$user || !in_array($level, ['admin'], true)) {
            return $this->response->redirect('/staff/login');
        }

        $settings = $this->configService->getAll();

        // Group by category
        $grouped = [];
        foreach ($settings as $key => $meta) {
            $cat = $meta['category'] ?? 'general';
            $grouped[$cat][$key] = $meta;
        }
        ksort($grouped);

        $html = $this->renderer->render('staff/site-settings/index.html', [
            'title' => 'Site Settings',
            'username' => $user['username'] ?? 'Admin',
            'level' => $level,
            'isAdmin' => true,
            'isManager' => true,
            'banRequestCount' => 0,
            'grouped' => $grouped,
        ]);

        return $this->response->html($html);
    }

    /**
     * POST /staff/site-settings/update — Bulk update settings.
     */
    public function update(RequestInterface $request): ResponseInterface
    {
        $user = Context::get('staff_user');
        $level = $user['access_level'] ?? '';
        if (!$user || !in_array($level, ['admin'], true)) {
            return $this->response->json(['error' => 'Forbidden'], 403);
        }

        $staffId = isset($user['id']) ? (int) $user['id'] : null;
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->response->json(['error' => 'Invalid request body'], 400);
        }

        $settings = $body['settings'] ?? [];
        if (!is_array($settings)) {
            return $this->response->json(['error' => 'Settings must be an array'], 400);
        }

        $updated = 0;
        foreach ($settings as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $this->configService->set($key, (string) $value, $staffId, 'Updated via admin panel');
            $updated++;
        }

        // Redirect back with success message
        return $this->response->redirect('/staff/site-settings?saved=' . $updated);
    }

    /**
     * GET /staff/site-settings/{key}/audit — View audit history for a setting.
     */
    public function audit(RequestInterface $request, string $key): ResponseInterface
    {
        $user = Context::get('staff_user');
        $level = $user['access_level'] ?? '';
        if (!$user || !in_array($level, ['admin'], true)) {
            return $this->response->redirect('/staff/login');
        }

        $history = $this->configService->getAuditLog($key);
        $currentValue = $this->configService->get($key);

        $html = $this->renderer->render('staff/site-settings/audit.html', [
            'title' => 'Setting Audit: ' . $key,
            'username' => $user['username'] ?? 'Admin',
            'level' => $level,
            'isAdmin' => true,
            'isManager' => true,
            'banRequestCount' => 0,
            'key' => $key,
            'currentValue' => $currentValue,
            'history' => $history,
        ]);

        return $this->response->html($html);
    }
}
