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

use App\Service\ProxyClient;
use App\Service\ViewService;
use App\Service\AuthenticationService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * BoardConfigController - Board configuration management for managers/admins
 */
#[Controller(prefix: '/staff/boards')]
class BoardConfigController
{
    use RequiresAccessLevel;

    public function __construct(
        private ProxyClient $proxyClient,
        private ViewService $viewService,
        private HttpResponse $response,
        private AuthenticationService $authService,
        private RequestInterface $request,
    ) {}

    /**
     * GET /staff/boards - List all boards
     */
    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $user = $this->getStaffUser();
        if (!$user) {
            return $this->response->redirect('/staff/login');
        }
        $denied = $this->requireAccessLevel('manager');
        if ($denied) return $denied;

        $result = $this->proxyClient->forward('boards', 'GET', '/api/v1/admin/boards');
        $data = json_decode((string) $result['body'], true);
        $boards = $data['boards'] ?? [];

        // Group boards by category
        $categories = [];
        foreach ($boards as $board) {
            $cat = $board['category'] ?: 'Uncategorized';
            $categories[$cat][] = $board;
        }
        ksort($categories);

        $html = $this->viewService->render('staff/boards/index', [
            'boards' => $boards,
            'categories' => $categories,
            'username' => $user['username'],
            'level' => $user['access_level'],
            'isManager' => in_array($user['access_level'], ['manager', 'admin']),
            'isAdmin' => $user['access_level'] === 'admin',
            'csrf_token' => $this->authService->generateCsrfToken((int) $user['id']),
        ]);
        return $this->response->html($html);
    }

    /**
     * GET /staff/boards/create - Create board form
     */
    #[GetMapping(path: 'create')]
    public function create(): ResponseInterface
    {
        $user = $this->getStaffUser();
        if (!$user) {
            return $this->response->redirect('/staff/login');
        }
        $denied = $this->requireAccessLevel('manager');
        if ($denied) return $denied;

        $html = $this->viewService->render('staff/boards/create', [
            'username' => $user['username'],
            'level' => $user['access_level'],
            'isManager' => true,
            'isAdmin' => $user['access_level'] === 'admin',
            'csrf_token' => $this->authService->generateCsrfToken((int) $user['id']),
        ]);
        return $this->response->html($html);
    }

    /**
     * POST /staff/boards/store - Create new board
     */
    #[PostMapping(path: 'store')]
    public function store(RequestInterface $request): ResponseInterface
    {
        $user = $this->getStaffUser();
        if (!$user) {
            return $this->response->redirect('/staff/login');
        }
        $denied = $this->requireAccessLevel('manager');
        if ($denied) return $denied;

        $input = $request->all();

        $payload = [
            'slug'             => trim((string) ($input['slug'] ?? '')),
            'title'            => trim((string) ($input['title'] ?? '')),
            'subtitle'         => trim((string) ($input['subtitle'] ?? '')),
            'category'         => trim((string) ($input['category'] ?? '')),
            'nsfw'             => isset($input['nsfw']),
            'max_threads'      => (int) ($input['max_threads'] ?? 200),
            'bump_limit'       => (int) ($input['bump_limit'] ?? 300),
            'image_limit'      => (int) ($input['image_limit'] ?? 150),
            'cooldown_seconds' => (int) ($input['cooldown_seconds'] ?? 60),
            'text_only'        => isset($input['text_only']),
            'require_subject'  => isset($input['require_subject']),
            'rules'            => trim((string) ($input['rules'] ?? '')),
        ];

        $result = $this->proxyClient->forward(
            'boards',
            'POST',
            '/api/v1/admin/boards',
            ['Content-Type' => 'application/json'],
            (string) json_encode($payload)
        );

        if ($result['status'] >= 400) {
            $error = json_decode((string) $result['body'], true);
            $html = $this->viewService->render('staff/boards/create', [
                'error' => $error['error'] ?? 'Failed to create board',
                'input' => $payload,
                'username' => $user['username'],
                'level' => $user['access_level'],
                'isManager' => true,
                'isAdmin' => $user['access_level'] === 'admin',
                'csrf_token' => $this->authService->generateCsrfToken((int) $user['id']),
            ]);
            return $this->response->html($html)->withStatus($result['status']);
        }

        $this->logStaffAction('board_create', "Created board /{$payload['slug']}/", $payload['slug']);

        return $this->response->redirect('/staff/boards');
    }

    /**
     * GET /staff/boards/{slug}/edit - Edit board form
     */
    #[GetMapping(path: '{slug}/edit')]
    public function edit(string $slug): ResponseInterface
    {
        $user = $this->getStaffUser();
        if (!$user) {
            return $this->response->redirect('/staff/login');
        }
        $denied = $this->requireAccessLevel('manager');
        if ($denied) return $denied;

        $result = $this->proxyClient->forward('boards', 'GET', "/api/v1/admin/boards/{$slug}");
        if ($result['status'] >= 400) {
            return $this->response->html('<h1>Board not found</h1>')->withStatus(404);
        }

        $data = json_decode((string) $result['body'], true);
        $board = $data['board'] ?? [];

        $html = $this->viewService->render('staff/boards/edit', [
            'board' => $board,
            'username' => $user['username'],
            'level' => $user['access_level'],
            'isManager' => true,
            'isAdmin' => $user['access_level'] === 'admin',
            'csrf_token' => $this->authService->generateCsrfToken((int) $user['id']),
        ]);
        return $this->response->html($html);
    }

    /**
     * POST /staff/boards/{slug}/update - Update board settings
     */
    #[PostMapping(path: '{slug}/update')]
    public function update(RequestInterface $request, string $slug): ResponseInterface
    {
        $user = $this->getStaffUser();
        if (!$user) {
            return $this->response->redirect('/staff/login');
        }
        $denied = $this->requireAccessLevel('manager');
        if ($denied) return $denied;

        $input = $request->all();

        $payload = [
            'title'            => trim((string) ($input['title'] ?? '')),
            'subtitle'         => trim((string) ($input['subtitle'] ?? '')),
            'category'         => trim((string) ($input['category'] ?? '')),
            'nsfw'             => isset($input['nsfw']),
            'max_threads'      => (int) ($input['max_threads'] ?? 200),
            'bump_limit'       => (int) ($input['bump_limit'] ?? 300),
            'image_limit'      => (int) ($input['image_limit'] ?? 150),
            'cooldown_seconds' => (int) ($input['cooldown_seconds'] ?? 60),
            'text_only'        => isset($input['text_only']),
            'require_subject'  => isset($input['require_subject']),
            'archived'         => isset($input['archived']),
            'rules'            => trim((string) ($input['rules'] ?? '')),
        ];

        $result = $this->proxyClient->forward(
            'boards',
            'POST',
            "/api/v1/admin/boards/{$slug}",
            ['Content-Type' => 'application/json'],
            (string) json_encode($payload)
        );

        if ($result['status'] >= 400) {
            $error = json_decode((string) $result['body'], true);
            // Re-fetch board to show form again
            $boardResult = $this->proxyClient->forward('boards', 'GET', "/api/v1/admin/boards/{$slug}");
            $boardData = json_decode((string) $boardResult['body'], true);

            $html = $this->viewService->render('staff/boards/edit', [
                'board' => $boardData['board'] ?? $payload,
                'error' => $error['error'] ?? 'Failed to update board',
                'username' => $user['username'],
                'level' => $user['access_level'],
                'isManager' => true,
                'isAdmin' => $user['access_level'] === 'admin',
                'csrf_token' => $this->authService->generateCsrfToken((int) $user['id']),
            ]);
            return $this->response->html($html)->withStatus($result['status']);
        }

        $this->logStaffAction('board_update', "Updated board /{$slug}/ settings", $slug);

        return $this->response->redirect('/staff/boards');
    }

    /**
     * POST /staff/boards/{slug}/delete - Delete board
     */
    #[PostMapping(path: '{slug}/delete')]
    public function delete(string $slug): ResponseInterface
    {
        $user = $this->getStaffUser();
        if (!$user) {
            return $this->response->redirect('/staff/login');
        }
        $denied = $this->requireAccessLevel('admin');
        if ($denied) return $denied;

        $result = $this->proxyClient->forward('boards', 'DELETE', "/api/v1/admin/boards/{$slug}");

        if ($result['status'] >= 400) {
            return $this->response->json(['error' => 'Failed to delete board'])->withStatus($result['status']);
        }

        $this->logStaffAction('board_delete', "Deleted board /{$slug}/", $slug);

        return $this->response->redirect('/staff/boards');
    }

    private function logStaffAction(string $action, string $details, ?string $board = null): void
    {
        try {
            $user = $this->getStaffUser();
            if ($user) {
                $this->authService->logAuditAction(
                    (int) $user['id'],
                    (string) ($user['username'] ?? 'system'),
                    $action,
                    'board_config',
                    null,
                    null,
                    $details,
                    (string) ($this->request->getHeaderLine('X-Real-IP') ?: $this->request->getServerParams()['remote_addr'] ?? '0.0.0.0'),
                    $this->request->getHeaderLine('User-Agent'),
                    null,
                    null,
                    '',
                    $board
                );
            }
        } catch (\Throwable $e) {
            // Don't let logging failures break the main operation
        }
    }
}
