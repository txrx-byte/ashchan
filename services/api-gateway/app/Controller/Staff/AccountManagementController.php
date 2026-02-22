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

#[Controller(prefix: '/staff/accounts')]
final class AccountManagementController
{
    public function __construct(
        private HttpResponse $response,
        private RequestInterface $request,
        private ViewService $viewService,
    ) {}

    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $staff = Db::table('staff_users')
            ->select('id', 'username', 'email', 'access_level', 'is_active', 'is_locked', 
                     'last_login_at', 'created_at', 'capcode', 'notes')
            ->orderBy('access_level', 'desc')
            ->orderBy('username')
            ->get();
        $html = $this->viewService->render('staff/accounts/index', ['staff' => $staff]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'create')]
    public function create(): ResponseInterface
    {
        $html = $this->viewService->render('staff/accounts/create', [
            'access_levels' => ['janitor' => 'Janitor', 'mod' => 'Moderator', 'manager' => 'Manager', 'admin' => 'Admin'],
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
        if (strlen((string) ($body['username'] ?? '')) < 3) $errors[] = 'Username must be at least 3 characters';
        if (!filter_var($body['email'] ?? '', FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email';
        if (strlen((string) ($body['password'] ?? '')) < 8) $errors[] = 'Password must be at least 8 characters';
        if (Db::table('staff_users')->where('username', $body['username'] ?? '')->first()) $errors[] = 'Username exists';
        if (Db::table('staff_users')->where('email', $body['email'] ?? '')->first()) $errors[] = 'Email exists';
        if (!empty($errors)) return $this->response->json(['success' => false, 'errors' => $errors], 400);
        Db::table('staff_users')->insertGetId([
            'username' => trim((string) ($body['username'] ?? '')), 'email' => trim((string) ($body['email'] ?? '')),
            'password_hash' => password_hash((string) ($body['password'] ?? ''), PASSWORD_ARGON2ID),
            'access_level' => $body['access_level'] ?? 'janitor', 'board_access' => '{' . implode(',', array_map(fn(mixed $b) => '"' . (string) $b . '"', (array) ($body['boards'] ?? []))) . '}',
            'is_active' => true, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->response->json(['success' => true, 'redirect' => '/staff/accounts']);
    }

    #[GetMapping(path: '{id:\d+}/edit')]
    public function edit(int $id): ResponseInterface
    {
        $user = Db::table('staff_users')->where('id', $id)->first();
        if (!$user) return $this->response->json(['error' => 'Not found'], 404);
        $user->board_access = \App\Helper\PgArrayParser::parse($user->board_access ?? null);
        $html = $this->viewService->render('staff/accounts/edit', [
            'user' => $user,
            'access_levels' => ['janitor' => 'Janitor', 'mod' => 'Moderator', 'manager' => 'Manager', 'admin' => 'Admin'],
            'boards' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'gif', 'h', 'hr', 'k', 'm', 'o', 'p', 'r', 's', 't', 'u', 'v', 'vg', 'vr', 'w', 'wg'],
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: '{id:\d+}/update')]
    public function update(int $id): ResponseInterface
    {
        $user = Db::table('staff_users')->where('id', $id)->first();
        if (!$user) return $this->response->json(['error' => 'Not found'], 404);
        /** @var array<string, mixed> $body */
        $body = (array) $this->request->getParsedBody();
        Db::table('staff_users')->where('id', $id)->update([
            'access_level' => $body['access_level'] ?? $user->access_level,
            'board_access' => '{' . implode(',', array_map(fn(mixed $b) => '"' . (string) $b . '"', (array) ($body['boards'] ?? []))) . '}',
            'is_active' => isset($body['is_active']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if (!empty($body['new_password']) && strlen((string) $body['new_password']) >= 8) {
            Db::table('staff_users')->where('id', $id)->update(['password_hash' => password_hash((string) $body['new_password'], PASSWORD_ARGON2ID)]);
        }
        return $this->response->json(['success' => true, 'redirect' => '/staff/accounts']);
    }

    #[PostMapping(path: '{id:\d+}/delete')]
    public function delete(int $id): ResponseInterface
    {
        $user = Db::table('staff_users')->where('id', $id)->first();
        if (!$user) return $this->response->json(['error' => 'Not found'], 404);
        $current = \Hyperf\Context\Context::get('staff_user');
        if ($current && $current['id'] === $id) return $this->response->json(['error' => 'Cannot delete yourself'], 400);
        Db::table('staff_users')->where('id', $id)->delete();
        return $this->response->json(['success' => true]);
    }

    #[PostMapping(path: '{id:\d+}/reset-password')]
    public function resetPassword(int $id): ResponseInterface
    {
        $user = Db::table('staff_users')->where('id', $id)->first();
        if (!$user) return $this->response->json(['error' => 'Not found'], 404);
        $tempPass = bin2hex(random_bytes(8));
        Db::table('staff_users')->where('id', $id)->update([
            'password_hash' => password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 12]),
            'failed_login_attempts' => 0, 'is_locked' => false, 'locked_until' => null,
        ]);
        return $this->response->json(['success' => true, 'temp_password' => $tempPass]);
    }

    #[PostMapping(path: '{id:\d+}/unlock')]
    public function unlock(int $id): ResponseInterface
    {
        Db::table('staff_users')->where('id', $id)->update(['is_locked' => false, 'locked_until' => null, 'failed_login_attempts' => 0]);
        return $this->response->json(['success' => true]);
    }
}
