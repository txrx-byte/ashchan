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

#[Controller(prefix: '/staff/capcodes')]
final class CapcodeController
{
    public function __construct(
        private HttpResponse $response,
        private RequestInterface $request,
        private ViewService $viewService,
    ) {}

    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $capcodes = Db::table('capcodes')
            ->orderBy('is_active', 'desc')
            ->orderBy('name')
            ->get();
        $capcodes = \App\Helper\PgArrayParser::parseCollection($capcodes, 'boards');
        $html = $this->viewService->render('staff/capcodes/index', ['capcodes' => $capcodes]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'create')]
    public function create(): ResponseInterface
    {
        $html = $this->viewService->render('staff/capcodes/create', [
            'boards' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: 'store')]
    public function store(): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = (array) $this->request->getParsedBody();
        $errors = [];

        if (strlen((string) ($body['name'] ?? '')) < 2) {
            $errors[] = 'Name must be at least 2 characters';
        }
        if (empty($body['label'])) {
            $errors[] = 'Label is required';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        // Generate secure tripcode
        $tripcode = '!!' . bin2hex(random_bytes(16));

        $user = \Hyperf\Context\Context::get('staff_user');
        Db::table('capcodes')->insert([
            'name' => trim((string) ($body['name'] ?? '')),
            'tripcode' => $tripcode,
            'label' => trim((string) ($body['label'] ?? '')),
            'color' => $body['color'] ?? '#0000FF',
            'boards' => '{' . implode(',', array_map(fn($b) => '"' . $b . '"', (array) ($body['boards'] ?? []))) . '}',
            'is_active' => isset($body['is_active']),
            'created_by' => $user['id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/capcodes']);
    }

    #[GetMapping(path: '{id:\d+}/edit')]
    public function edit(int $id): ResponseInterface
    {
        $capcode = Db::table('capcodes')->where('id', $id)->first();
        if (!$capcode) {
            return $this->response->json(['error' => 'Not found'], 404);
        }
        $capcode->boards = \App\Helper\PgArrayParser::parse($capcode->boards ?? null);

        $html = $this->viewService->render('staff/capcodes/edit', [
            'capcode' => $capcode,
            'boards' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: '{id:\d+}/update')]
    public function update(int $id): ResponseInterface
    {
        $capcode = Db::table('capcodes')->where('id', $id)->first();
        if (!$capcode) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        /** @var array<string, mixed> $body */

        $body = (array) $this->request->getParsedBody();
        $errors = [];

        if (strlen((string) ($body['name'] ?? '')) < 2) {
            $errors[] = 'Name must be at least 2 characters';
        }
        if (empty($body['label'])) {
            $errors[] = 'Label is required';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        Db::table('capcodes')->where('id', $id)->update([
            'name' => trim((string) ($body['name'] ?? '')),
            'label' => trim((string) ($body['label'] ?? '')),
            'color' => $body['color'] ?? '#0000FF',
            'boards' => '{' . implode(',', array_map(fn($b) => '"' . $b . '"', (array) ($body['boards'] ?? []))) . '}',
            'is_active' => isset($body['is_active']),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/capcodes']);
    }

    #[PostMapping(path: '{id:\d+}/delete')]
    public function delete(int $id): ResponseInterface
    {
        $capcode = Db::table('capcodes')->where('id', $id)->first();
        if (!$capcode) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        Db::table('capcodes')->where('id', $id)->delete();
        return $this->response->json(['success' => true]);
    }

    #[PostMapping(path: 'test')]
    public function test(): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = (array) $this->request->getParsedBody();
        $tripcode = $body['tripcode'] ?? '';

        // Check if tripcode exists
        $capcode = Db::table('capcodes')
            ->where('tripcode', $tripcode)
            ->where('is_active', true)
            ->first();

        if ($capcode) {
            return $this->response->json([
                'valid' => true,
                'name' => $capcode->name,
                'label' => $capcode->label,
                'color' => $capcode->color,
            ]);
        }

        return $this->response->json(['valid' => false]);
    }
}
