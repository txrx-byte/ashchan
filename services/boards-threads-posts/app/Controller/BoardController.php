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

final class BoardController
{
    public function __construct(
        private BoardService $boardService,
        private HttpResponse $response,
    ) {}

    /** GET /api/v1/boards */
    public function list(): ResponseInterface
    {
        $boards = $this->boardService->listBoards();
        return $this->response->json(['boards' => $boards]);
    }

    /** GET /api/v1/boards/{slug} */
    public function show(string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }
        return $this->response->json(['board' => $board->toArray()]);
    }

    /** GET /api/v1/blotter */
    public function blotter(): ResponseInterface
    {
        $blotter = $this->boardService->getBlotter();
        return $this->response->json(['blotter' => $blotter]);
    }

    /* ──────────────────────────────────────────────
     * Admin Board Management
     * ────────────────────────────────────────────── */

    /** GET /api/v1/admin/boards - List all boards including archived */
    public function listAll(): ResponseInterface
    {
        $boards = $this->boardService->listAllBoards();
        return $this->response->json(['boards' => $boards]);
    }

    /** GET /api/v1/admin/boards/{slug} - Get single board for editing */
    public function adminShow(string $slug): ResponseInterface
    {
        $board = Board::query()->where('slug', $slug)->first();
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }
        return $this->response->json(['board' => $board->toArray()]);
    }

    /** POST /api/v1/admin/boards - Create a new board */
    public function store(RequestInterface $request): ResponseInterface
    {
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
            return $this->response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    /** POST /api/v1/admin/boards/{slug} - Update board settings */
    public function update(RequestInterface $request, string $slug): ResponseInterface
    {
        $board = Board::query()->where('slug', $slug)->first();
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }

        $data = $request->all();

        try {
            $board = $this->boardService->updateBoard($board, $data);
            return $this->response->json(['board' => $board->toArray()]);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    /** DELETE /api/v1/admin/boards/{slug} - Delete board */
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
            return $this->response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }
}
