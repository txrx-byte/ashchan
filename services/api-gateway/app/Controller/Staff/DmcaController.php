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

#[Controller(prefix: '/staff/dmca')]
final class DmcaController
{
    public function __construct(
        private HttpResponse $response,
        private RequestInterface $request,
        private ViewService $viewService,
    ) {}

    #[GetMapping(path: '')]
    public function index(): ResponseInterface
    {
        $notices = Db::table('dmca_notices')
            ->orderBy('received_at', 'desc')
            ->get();
        $html = $this->viewService->render('staff/dmca/index', ['notices' => $notices]);
        return $this->response->html($html);
    }

    #[GetMapping(path: 'create')]
    public function create(): ResponseInterface
    {
        $html = $this->viewService->render('staff/dmca/create');
        return $this->response->html($html);
    }

    #[PostMapping(path: 'store')]
    public function store(): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = (array) $this->request->getParsedBody();
        $errors = [];

        if (empty($body['claimant_name'])) {
            $errors[] = 'Claimant name is required';
        }
        if (empty($body['claimant_email'])) {
            $errors[] = 'Claimant email is required';
        } elseif (!filter_var($body['claimant_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address';
        }
        if (empty($body['copyrighted_work'])) {
            $errors[] = 'Copyrighted work description is required';
        }
        if (empty($body['infringing_urls'])) {
            $errors[] = 'At least one infringing URL is required';
        }
        if (empty($body['statement'])) {
            $errors[] = 'Statement is required';
        }

        if (!empty($errors)) {
            return $this->response->json(['success' => false, 'errors' => $errors], 400);
        }

        $user = \Hyperf\Context\Context::get('staff_user');
        $infringingUrls = is_array($body['infringing_urls']) 
            ? $body['infringing_urls'] 
            : array_filter(array_map('trim', explode("\n", (string) ($body['infringing_urls'] ?? ''))));

        Db::table('dmca_notices')->insertGetId([
            'claimant_name' => trim((string) ($body['claimant_name'] ?? '')),
            'claimant_company' => trim((string) ($body['claimant_company'] ?? '')),
            'claimant_email' => trim((string) ($body['claimant_email'] ?? '')),
            'claimant_phone' => trim((string) ($body['claimant_phone'] ?? '')),
            'copyrighted_work' => trim((string) ($body['copyrighted_work'] ?? '')),
            'infringing_urls' => $infringingUrls,
            'statement' => trim((string) ($body['statement'] ?? '')),
            'signature' => trim((string) ($body['signature'] ?? '')),
            'status' => 'pending',
            'received_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->json(['success' => true, 'redirect' => '/staff/dmca']);
    }

    #[GetMapping(path: '{id:\d+}')]
    public function view(int $id): ResponseInterface
    {
        $notice = Db::table('dmca_notices')->where('id', $id)->first();
        if (!$notice) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        $takedowns = Db::table('dmca_takedowns')
            ->where('notice_id', $id)
            ->orderBy('takedown_at', 'desc')
            ->get();

        $html = $this->viewService->render('staff/dmca/view', [
            'notice' => $notice,
            'takedowns' => $takedowns,
        ]);
        return $this->response->html($html);
    }

    #[PostMapping(path: '{id:\d+}/process')]
    public function process(int $id): ResponseInterface
    {
        $notice = Db::table('dmca_notices')->where('id', $id)->first();
        if (!$notice) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        /** @var array<string, mixed> $body */

        $body = (array) $this->request->getParsedBody();
        $user = \Hyperf\Context\Context::get('staff_user');

        Db::table('dmca_notices')->where('id', $id)->update([
            'status' => $body['status'] ?? 'processed',
            'processed_at' => date('Y-m-d H:i:s'),
            'processed_by' => $user['id'] ?? null,
            'notes' => trim((string) ($body['notes'] ?? '')),
        ]);

        // Log takedowns
        $takedowns = $body['takedowns'] ?? [];
        foreach ($takedowns as $takedown) {
            if (!empty($takedown['board']) && !empty($takedown['post_no'])) {
                Db::table('dmca_takedowns')->insert([
                    'notice_id' => $id,
                    'board' => $takedown['board'],
                    'post_no' => (int)$takedown['post_no'],
                    'md5_hash' => $takedown['md5_hash'] ?? null,
                    'takedown_reason' => trim((string) ($takedown['reason'] ?? '')),
                    'takedown_at' => date('Y-m-d H:i:s'),
                    'takedown_by' => $user['id'] ?? null,
                ]);
            }
        }

        return $this->response->json(['success' => true, 'redirect' => '/staff/dmca/' . $id]);
    }

    #[PostMapping(path: '{id:\d+}/status')]
    public function updateStatus(int $id): ResponseInterface
    {
        $notice = Db::table('dmca_notices')->where('id', $id)->first();
        if (!$notice) {
            return $this->response->json(['error' => 'Not found'], 404);
        }

        /** @var array<string, mixed> $body */

        $body = (array) $this->request->getParsedBody();
        $validStatuses = ['pending', 'processed', 'rejected'];
        $status = $body['status'] ?? 'pending';

        if (!in_array($status, $validStatuses)) {
            return $this->response->json(['error' => 'Invalid status'], 400);
        }

        Db::table('dmca_notices')->where('id', $id)->update([
            'status' => $status,
            'processed_at' => in_array($status, ['processed', 'rejected']) ? date('Y-m-d H:i:s') : null,
        ]);

        return $this->response->json(['success' => true]);
    }
}
