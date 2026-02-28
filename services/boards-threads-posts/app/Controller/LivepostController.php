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
 * Liveposting API endpoints for open post lifecycle management.
 *
 * These endpoints are called by the api-gateway's WebSocket handlers
 * via mTLS (mutual TLS). They are not exposed to end users directly.
 *
 * Liveposting enables real-time collaborative post editing where:
 * 1. A user starts typing in a post form
 * 2. An "open post" is allocated with a unique editing session
 * 3. Content is synced via WebSocket with debounced persistence
 * 4. Post is finalized (closed) when user submits or session expires
 *
 * Security:
 * - All endpoints require mTLS client certificate authentication
 * - IP addresses are extracted from forwarded headers
 * - Reclaim operations require password verification
 *
 * @see docs/LIVEPOSTING.md §5.7, Phase 2
 * @see BoardService For open post business logic
 */
final class LivepostController
{
    /**
     * @param BoardService $boardService Service for board and post operations
     * @param HttpResponse $response HTTP response builder
     */
    public function __construct(
        private BoardService $boardService,
        private HttpResponse $response,
    ) {
    }

    /**
     * POST /api/v1/boards/{slug}/threads/{id}/open-post
     *
     * Allocate an open (editing) post for liveposting.
     *
     * Called by the gateway InsertPostHandler when a client starts typing.
     * Creates a new post record in "editing" state with:
     * - Unique post ID allocated from sequence
     * - Edit password for reclaim capability
     * - Expiration timestamp (default: 30 minutes)
     * - Initial body stored in open_post_bodies table
     *
     * @param RequestInterface $request HTTP request with post data
     * @param string $slug Board slug identifier
     * @param int $id Thread ID
     * @return ResponseInterface JSON response with allocated post data or error
     *
     * @example POST /api/v1/boards/b/threads/12345/open-post
     * @example Request: {"name": "Anonymous", "email": "", "subject": ""}
     * @example Response (201): {"id": 67890, "edit_password": "...", "expires_at": "..."}
     * @example Response (404): {"error": "Board not found"}
     * @example Response (422): {"error": "Thread is locked"}
     *
     * @see POST /api/v1/boards/{slug}/threads/{id}/open-post
     * @see BoardService::createOpenPost()
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
            // Priority: X-Forwarded-For > X-Real-IP > remote_addr
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
     * Close (finalize) an open post.
     *
     * This operation:
     * 1. Copies body from open_post_bodies to posts.content
     * 2. Parses markup (greentext, quotes, spoilers) via ContentFormatter
     * 3. Generates content_html for display
     * 4. Updates thread reply/image counts
     * 5. Bumps thread timestamp (if not sage)
     * 6. Invalidates thread cache
     * 7. Publishes PostCreated CloudEvent
     * 8. Deletes open_post_bodies record
     *
     * @param RequestInterface $request HTTP request (body not used)
     * @param int $id Post ID to close
     * @return ResponseInterface JSON response with closed post data or error
     *
     * @example POST /api/v1/posts/67890/close
     * @example Response: {"id": 67890, "thread_id": 12345, "content_html": "..."}
     * @example Response (404): {"error": "Post not found or not editing"}
     *
     * @see POST /api/v1/posts/{id}/close
     * @see BoardService::closeOpenPost()
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
     *
     * Called by the gateway's write debouncer every ~1 second while user types.
     * Updates only the open_post_bodies table to avoid write amplification
     * on the main posts table during active editing.
     *
     * The body is stored as raw text without markup parsing. Parsing occurs
     * only when the post is closed.
     *
     * @param RequestInterface $request HTTP request with body content
     * @param int $id Post ID to update
     * @return ResponseInterface JSON response with success status or error
     *
     * @example PUT /api/v1/posts/67890/body
     * @example Request: {"body": "This is my post content..."}
     * @example Response: {"ok": true}
     * @example Response (404): {"error": "Post not found or not editing"}
     *
     * @see PUT /api/v1/posts/{id}/body
     * @see BoardService::setOpenBody()
     * @see \App\Model\OpenPostBody
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
     *
     * If a user's connection drops during liveposting, they can reclaim
     * their editing session by providing the password that was returned
     * when the open post was initially allocated.
     *
     * The reclaim operation:
     * 1. Verifies the password against edit_password_hash
     * 2. Extends the edit_expires_at timestamp
     * 3. Returns the post data for client re-sync
     *
     * @param RequestInterface $request HTTP request with password
     * @param int $id Post ID to reclaim
     * @return ResponseInterface JSON response with post data or error
     *
     * @example POST /api/v1/posts/67890/reclaim
     * @example Request: {"password": "abc123..."}
     * @example Response: {"id": 67890, "body": "...", "expires_at": "..."}
     * @example Response (400): {"error": "Password required"}
     * @example Response (403): {"error": "Reclaim failed"}
     *
     * @see POST /api/v1/posts/{id}/reclaim
     * @see BoardService::reclaimPost()
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
     * Force-close all expired open posts.
     *
     * Called by a scheduled timer (every 5 minutes) to clean up abandoned
     * editing sessions. Posts that have exceeded their edit_expires_at
     * timestamp are closed with whatever content exists in their body.
     *
     * Expired posts are closed with:
     * - Current body content (may be empty)
     * - Default "Anonymous" name if not set
     * - Standard markup parsing
     *
     * This prevents resource leaks from abandoned editing sessions.
     *
     * @param RequestInterface $request HTTP request (body not used)
     * @return ResponseInterface JSON response with count of closed posts
     *
     * @example POST /api/v1/posts/close-expired
     * @example Response: {"closed": 3}
     *
     * @see POST /api/v1/posts/close-expired
     * @see BoardService::closeExpiredPosts()
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
