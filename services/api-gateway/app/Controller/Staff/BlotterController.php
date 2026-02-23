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

use App\Service\ViewService;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/staff/blotter')]
final class BlotterController
{
    public function __construct(
        private HttpResponse $response,
        private RequestInterface $request,
        private ViewService $viewService,
    ) {}

    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $messages = Db::table('blotter_messages')
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
        $user = \Hyperf\Context\Context::get('staff_user');
        $level = $user['access_level'] ?? '';
        $html = $this->viewService->render('staff/blotter/index', [
            'messages' => $messages,
            'username' => $user['username'] ?? 'Admin',
            'level' => $level,
            'isAdmin' => $level === 'admin',
            'isManager' => in_array($level, ['manager', 'admin'], true),
        ]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'create')]
    public function create(): ResponseInterface
    {
        $user = \Hyperf\Context\Context::get('staff_user');
        $level = $user['access_level'] ?? '';
        $html = $this->viewService->render('staff/blotter/create', [
            'username' => $user['username'] ?? 'Admin',
            'level' => $level,
            'isAdmin' => $level === 'admin',
            'isManager' => in_array($level, ['manager', 'admin'], true),
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: 'store')]
    public function store(): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = (array) $this->request->getParsedBody();
        $errors = [];

        if (empty($body['message'])) {
            $errors[] = 'Message is required';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        $user = \Hyperf\Context\Context::get('staff_user');
        Db::table('blotter_messages')->insert([
            'message' => $this->sanitizeHtmlContent(
                trim((string) ($body['message'] ?? '')),
                isset($body['is_html'])
            ),
            'is_html' => isset($body['is_html']),
            'is_active' => isset($body['is_active']),
            'priority' => (int)($body['priority'] ?? 0),
            'created_by' => $user['id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/blotter']);
    }

    #[GetMapping(path: '{id:\d+}/edit')]
    public function edit(int $id): ResponseInterface
    {
        $message = Db::table('blotter_messages')->where('id', $id)->first();
        if (!$message) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        $user = \Hyperf\Context\Context::get('staff_user');
        $level = $user['access_level'] ?? '';
        $html = $this->viewService->render('staff/blotter/edit', [
            'message' => $message,
            'username' => $user['username'] ?? 'Admin',
            'level' => $level,
            'isAdmin' => $level === 'admin',
            'isManager' => in_array($level, ['manager', 'admin'], true),
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: '{id:\d+}/update')]
    public function update(int $id): ResponseInterface
    {
        $message = Db::table('blotter_messages')->where('id', $id)->first();
        if (!$message) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        /** @var array<string, mixed> $body */

        $body = (array) $this->request->getParsedBody();
        $errors = [];

        if (empty($body['message'])) {
            $errors[] = 'Message is required';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        Db::table('blotter_messages')->where('id', $id)->update([
            'message' => $this->sanitizeHtmlContent(
                trim((string) ($body['message'] ?? '')),
                isset($body['is_html'])
            ),
            'is_html' => isset($body['is_html']),
            'is_active' => isset($body['is_active']),
            'priority' => (int)($body['priority'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/blotter']);
    }

    #[PostMapping(path: '{id:\d+}/delete')]
    public function delete(int $id): ResponseInterface
    {
        $deleted = Db::table('blotter_messages')->where('id', $id)->delete();
        if ($deleted === 0) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        return $this->response->json(['success' => true]);
    }

    #[PostMapping(path: 'preview')]
    public function preview(): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = (array) $this->request->getParsedBody();
        $message = (string) ($body['message'] ?? '');
        $isHtml = isset($body['is_html']);

        if ($isHtml) {
            // Sanitize HTML — allow only safe tags and strip dangerous attributes
            $message = strip_tags($message, '<p><br><strong><em><a><ul><ol><li>');
            // Remove all on* event handlers and javascript: URIs from remaining tags
            $message = (string) preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']|\s+on\w+\s*=\s*\S+/i', '', $message);
            $message = (string) preg_replace('/href\s*=\s*["\']\s*javascript\s*:[^"\']*["\']/i', 'href="#"', $message);
            $message = (string) preg_replace('/href\s*=\s*["\']\s*data\s*:[^"\']*["\']/i', 'href="#"', $message);
        } else {
            $message = nl2br(htmlspecialchars($message));
        }

        return $this->response->json(['preview' => $message]);
    }

    /**
     * Sanitize HTML content before storage.
     * Strips dangerous tags, event handlers, and javascript: URIs.
     */
    private function sanitizeHtmlContent(string $content, bool $isHtml): string
    {
        if (!$isHtml) {
            return $content;
        }

        $safe = strip_tags($content, '<p><br><strong><em><a><ul><ol><li>');
        // Remove on* event handler attributes
        $safe = (string) preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']|\s+on\w+\s*=\s*\S+/i', '', $safe);
        // Neutralize javascript: and data: URIs in href
        $safe = (string) preg_replace('/href\s*=\s*["\']\s*javascript\s*:[^"\']*["\']/i', 'href="#"', $safe);
        $safe = (string) preg_replace('/href\s*=\s*["\']\s*data\s*:[^"\']*["\']/i', 'href="#"', $safe);

        return $safe;
    }
}
