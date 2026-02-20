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

use App\Service\StopForumSpamService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Internal API for SFS checks and reporting.
 */
#[Controller(prefix: '/internal/spam')]
final class StopForumSpamController
{
    public function __construct(
        private StopForumSpamService $sfsService,
        private HttpResponse $response
    ) {}

    /**
     * Check if an entity is spam.
     */
    #[PostMapping(path: 'check')]
    public function check(RequestInterface $request): ResponseInterface
    {
        $ip = (string) $request->input('ip', '');
        $email = $request->input('email');
        $username = $request->input('username');

        if (empty($ip)) {
            return $this->response->json(['is_spam' => false]);
        }

        $isSpam = $this->sfsService->check(
            $ip,
            is_string($email) ? $email : null,
            is_string($username) ? $username : null
        );

        return $this->response->json(['is_spam' => $isSpam]);
    }

    /**
     * Report a spammer.
     */
    #[PostMapping(path: 'report')]
    public function report(RequestInterface $request): ResponseInterface
    {
        $ip = (string) $request->input('ip', '');
        $email = (string) $request->input('email', '');
        $username = (string) $request->input('username', 'Anonymous');
        $evidence = (string) $request->input('evidence', '');

        if (empty($ip)) {
            return $this->response->json(['error' => 'IP required'], 400);
        }

        // Ideally queue this, but for now run synchronously with short timeout in service
        $this->sfsService->report($ip, $email, $username, $evidence);

        return $this->response->json(['status' => 'reported']);
    }
}