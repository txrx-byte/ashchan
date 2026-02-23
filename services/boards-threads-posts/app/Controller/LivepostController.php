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

use App\Model\Thread;
use App\Service\BoardService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Liveposting API endpoints for open post lifecycle.
 *
 * These endpoints are called by the api-gateway's WebSocket handlers
 * via mTLS. They are not exposed to end users directly.
 *
 * @see docs/LIVEPOSTING.md §5.7, Phase 2
 */
final class LivepostController
{
    public function __construct(
        private BoardService $boardService,
        private HttpResponse $response,
    ) {
    }

    /**
     * POST /api/v1/boards/{slug}/threads/{id}/open-post
     *
     * Allocate an open (editing) post for liveposting.
     * Called by the gateway InsertPostHandler when a client starts typing.
     */
    public function openPost(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        try {
            $board = $this->boardService->getBoard($slug);
            if ($board === null) {
                return $this->response->json(['error' => 'Board not found'])->withStatus(404);
            }

            /** @var Thread|null $thread */
            $thread = Thread::find($id);
            if ($thread === null) {
                return $this->response->json(['error' => 'Thread not found'])->withStatus(404);
            }

            $data = $request->all();

            // Validate name length
            $name = (string) ($data['name'] ?? '');
            if (mb_strlen($name) > 100) {
                return $this->response->json(['error' => 'Name too long (max 100 characters)'])->withStatus(400);
            }

            // Extract IP from forwarded headers
            $ip = (string) ($request->getHeaderLine('X-Forwarded-For')
                ?: $request->getHeaderLine('X-Real-IP')
                ?: ($request->getServerParams()['remote_addr'] ?? ''));
            $data['ip_address'] = $ip;

            $result = $this->boardService->createOpenPost($thread, $data);

            return $this->response->json($result)->withStatus(201);
        } catch (\RuntimeException $e) {
            return $this->response->json(['error' => $e->getMessage()])->withStatus(422);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'Internal error'])->withStatus(500);
        }
    }

    /**
     * POST /api/v1/posts/{id}/close
     *
     * Close (finalize) an open post. Copies body to posts.content,
     * parses markup, invalidates caches.
     */
    public function closePost(RequestInterface $request, int $id): ResponseInterface
    {
        try {
            $result = $this->boardService->closeOpenPost($id);
            if ($result === null) {
                return $this->response->json(['error' => 'Post not found or not editing'])->withStatus(404);
            }

            return $this->response->json($result)->withStatus(200);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'Internal error'])->withStatus(500);
        }
    }

    /**
     * PUT /api/v1/posts/{id}/body
     *
     * Update the body of an open post (debounced persistence).
     * Called by the gateway's write debouncer every ~1 second.
     */
    public function updateBody(RequestInterface $request, int $id): ResponseInterface
    {
        try {
            $body = (string) ($request->input('body') ?? '');

            $ok = $this->boardService->setOpenBody($id, $body);
            if (!$ok) {
                return $this->response->json(['error' => 'Post not found or not editing'])->withStatus(404);
            }

            return $this->response->json(['ok' => true])->withStatus(200);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'Internal error'])->withStatus(500);
        }
    }

    /**
     * POST /api/v1/posts/{id}/reclaim
     *
     * Reclaim an open post after disconnection using the reclaim password.
     */
    public function reclaimPost(RequestInterface $request, int $id): ResponseInterface
    {
        try {
            $password = (string) ($request->input('password') ?? '');
            if ($password === '') {
                return $this->response->json(['error' => 'Password required'])->withStatus(400);
            }

            $result = $this->boardService->reclaimPost($id, $password);
            if ($result === null) {
                return $this->response->json(['error' => 'Reclaim failed'])->withStatus(403);
            }

            return $this->response->json($result)->withStatus(200);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'Internal error'])->withStatus(500);
        }
    }

    /**
     * POST /api/v1/posts/close-expired
     *
     * Force-close all expired open posts. Called by a scheduled timer.
     */
    public function closeExpired(RequestInterface $request): ResponseInterface
    {
        try {
            $count = $this->boardService->closeExpiredPosts();

            return $this->response->json([
                'closed' => $count,
            ])->withStatus(200);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'Internal error'])->withStatus(500);
        }
    }
}
