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

use App\Service\BoardService;
use App\Model\Board;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Controller for board management operations.
 *
 * Handles public board listing, blotter announcements, and admin board CRUD.
 * All methods return JSON responses with appropriate HTTP status codes.
 *
 * Board operations are cached in Redis for performance:
 * - Board list: 300 seconds
 * - Individual board: 300 seconds
 * - Blotter entries: 120 seconds
 *
 * @see docs/API.md §Board Endpoints
 * @see BoardService For business logic implementation
 */
final class BoardController
{
    /**
     * @param BoardService $boardService Service for board operations
     * @param HttpResponse $response HTTP response builder
     */
    public function __construct(
        private BoardService $boardService,
        private HttpResponse $response,
    ) {}

    /**
     * List all active boards.
     *
     * Returns all non-archived, non-staff-only boards ordered by category and slug.
     * Results are cached in Redis for 300 seconds to reduce database load.
     *
     * @return ResponseInterface JSON response with boards array
     *
     * @example GET /api/v1/boards
     * @example Response: {"boards": [{"id": 1, "slug": "b", "title": "Random", ...}]}
     *
     * @see GET /api/v1/boards
     * @see BoardService::listBoards()
     */
    public function list(): ResponseInterface
    {
        $boards = $this->boardService->listBoards();
        return $this->response->json(['boards' => $boards]);
    }

    /**
     * Get a single board by slug.
     *
     * Retrieves board configuration and settings by its unique slug identifier.
     * Board data is cached in Redis for 300 seconds.
     *
     * @param string $slug Board slug identifier (e.g., "b", "g", "v")
     * @return ResponseInterface JSON response with board data or 404 error
     *
     * @example GET /api/v1/boards/b
     * @example Response: {"board": {"id": 1, "slug": "b", "title": "Random", ...}}
     *
     * @see GET /api/v1/boards/{slug}
     * @see BoardService::getBoard()
     */
    public function show(string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }
        return $this->response->json(['board' => $board->toArray()]);
    }

    /**
     * Get recent blotter announcements.
     *
     * Returns the most recent site announcements, ordered by ID descending.
     * The limit is configured via SiteConfigService (default: 5 entries).
     * Results are cached in Redis for 120 seconds.
     *
     * @return ResponseInterface JSON response with blotter entries
     *
     * @example GET /api/v1/blotter
     * @example Response: {"blotter": [{"id": 1, "content": "Site update...", ...}]}
     *
     * @see GET /api/v1/blotter
     * @see BoardService::getBlotter()
     * @see \App\Model\Blotter
     */
    public function blotter(): ResponseInterface
    {
        $blotter = $this->boardService->getBlotter();
        return $this->response->json(['blotter' => $blotter]);
    }

    /* ──────────────────────────────────────────────
     * Admin Board Management
     * ────────────────────────────────────────────── */

    /**
     * List all boards including archived (admin only).
     *
     * Returns all boards regardless of archived or staff_only status.
     * This endpoint requires authentication and admin authorization.
     * Results are not cached to ensure admin sees real-time data.
     *
     * @return ResponseInterface JSON response with all boards
     *
     * @example GET /api/v1/admin/boards
     * @example Response: {"boards": [{"id": 1, "slug": "b", "archived": false, ...}]}
     *
     * @see GET /api/v1/admin/boards
     * @see BoardService::listAllBoards()
     */
    public function listAll(): ResponseInterface
    {
        $boards = $this->boardService->listAllBoards();
        return $this->response->json(['boards' => $boards]);
    }

    /**
     * Get single board for admin editing.
     *
     * Retrieves complete board data for admin panel editing.
     * Unlike the public show() method, this returns all fields including
     * internal settings and flags.
     *
     * @param string $slug Board slug identifier
     * @return ResponseInterface JSON response with board data or 404 error
     *
     * @example GET /api/v1/admin/boards/b
     * @example Response: {"board": {"id": 1, "slug": "b", "title": "Random", ...}}
     *
     * @see GET /api/v1/admin/boards/{slug}
     */
    public function adminShow(string $slug): ResponseInterface
    {
        $board = Board::query()->where('slug', $slug)->first();
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }
        return $this->response->json(['board' => $board->toArray()]);
    }

    /**
     * Create a new board (admin only).
     *
     * Validates slug format (1-32 lowercase alphanumeric), checks uniqueness,
     * and delegates creation to BoardService.
     *
     * Validation rules:
     * - slug: required, 1-32 lowercase alphanumeric characters
     * - title: optional, used as board name
     * - Other fields: optional with defaults from SiteConfigService
     *
     * @param RequestInterface $request HTTP request with board data
     * @return ResponseInterface JSON response with created board (201) or error
     *
     * @example POST /api/v1/admin/boards
     * @example Request: {"slug": "newboard", "title": "New Board", ...}
     * @example Response (201): {"board": {"id": 10, "slug": "newboard", ...}}
     * @example Response (400): {"error": "Slug must be 1-32 lowercase alphanumeric characters"}
     * @example Response (409): {"error": "Board slug already exists"}
     *
     * @see POST /api/v1/admin/boards
     * @see BoardService::createBoard()
     */
    public function store(RequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $data */
        $data = $request->all();

        if (empty($data['slug']) || !is_string($data['slug'])) {
            return $this->response->json(['error' => 'Board slug is required'])->withStatus(400);
        }

        // Validate slug format
        if (!preg_match('/^[a-z0-9]{1,32}$/', $data['slug'])) {
            return $this->response->json(['error' => 'Slug must be 1-32 lowercase alphanumeric characters'])->withStatus(400);
        }

        // Check uniqueness
        $existing = Board::query()->where('slug', $data['slug'])->first();
        if ($existing) {
            return $this->response->json(['error' => 'Board slug already exists'])->withStatus(409);
        }

        try {
            $board = $this->boardService->createBoard($data);
            return $this->response->json(['board' => $board->toArray()])->withStatus(201);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'An internal error occurred'])->withStatus(500);
        }
    }

    /**
     * Update board settings (admin only).
     *
     * Updates board configuration with the provided fields.
     * Only specified fields are updated; others remain unchanged.
     *
     * Updatable fields:
     * - title, subtitle, category, rules (string)
     * - nsfw, text_only, require_subject, archived, staff_only, user_ids, country_flags (boolean)
     * - max_threads, bump_limit, image_limit, cooldown_seconds (integer)
     *
     * @param RequestInterface $request HTTP request with updated board data
     * @param string $slug Board slug identifier
     * @return ResponseInterface JSON response with updated board or error
     *
     * @example POST /api/v1/admin/boards/b
     * @example Request: {"title": "Updated Title", "nsfw": true}
     * @example Response: {"board": {"id": 1, "slug": "b", "title": "Updated Title", ...}}
     *
     * @see POST /api/v1/admin/boards/{slug}
     * @see BoardService::updateBoard()
     */
    public function update(RequestInterface $request, string $slug): ResponseInterface
    {
        $board = Board::query()->where('slug', $slug)->first();
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }

        /** @var array<string, mixed> $data */
        $data = $request->all();

        try {
            $board = $this->boardService->updateBoard($board, $data);
            return $this->response->json(['board' => $board->toArray()]);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'An internal error occurred'])->withStatus(500);
        }
    }

    /**
     * Delete a board and all its threads/posts (admin only).
     *
     * Deletion cascades to all related threads and posts via foreign key constraints.
     * Board caches are invalidated after deletion to ensure consistency.
     *
     * WARNING: This operation is irreversible. All threads, posts, and associated
     * media references will be permanently deleted.
     *
     * @param string $slug Board slug identifier
     * @return ResponseInterface JSON response with deletion status or error
     *
     * @example DELETE /api/v1/admin/boards/b
     * @example Response: {"status": "deleted"}
     *
     * @see DELETE /api/v1/admin/boards/{slug}
     * @see BoardService::deleteBoard()
     */
    public function destroy(string $slug): ResponseInterface
    {
        $board = Board::query()->where('slug', $slug)->first();
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }

        try {
            $this->boardService->deleteBoard($board);
            return $this->response->json(['status' => 'deleted']);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'An internal error occurred'])->withStatus(500);
        }
    }
}
