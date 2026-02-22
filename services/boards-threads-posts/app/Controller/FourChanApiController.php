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
use App\Service\FourChanApiService;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Controller that serves data in the exact 4chan API JSON format.
 *
 * All endpoints are read-only and match the 4chan API specification:
 * https://github.com/4chan/4chan-API
 *
 * Endpoint mapping:
 *   GET /api/4chan/boards.json              → boards.json
 *   GET /api/4chan/{board}/threads.json     → threads.json (threadlist)
 *   GET /api/4chan/{board}/catalog.json     → catalog.json
 *   GET /api/4chan/{board}/{page}.json      → index page
 *   GET /api/4chan/{board}/thread/{no}.json → full thread
 *   GET /api/4chan/{board}/archive.json     → archive.json
 */
final class FourChanApiController
{
    public function __construct(
        private FourChanApiService $apiService,
        private BoardService $boardService,
        private HttpResponse $response,
    ) {}

    /**
     * GET /api/4chan/boards.json
     *
     * Returns a comprehensive list of all boards and their attributes.
     */
    public function boards(): ResponseInterface
    {
        $data = $this->apiService->getBoards();
        return $this->jsonResponse($data);
    }

    /**
     * GET /api/4chan/{board}/threads.json
     *
     * Returns a summarized list of all threads on a board including
     * thread numbers, modification time, and reply count, grouped by page.
     */
    public function threadList(string $board): ResponseInterface
    {
        $boardModel = $this->boardService->getBoard($board);
        if (!$boardModel) {
            return $this->notFound();
        }
        $data = $this->apiService->getThreadList($boardModel);
        return $this->jsonResponse($data);
    }

    /**
     * GET /api/4chan/{board}/catalog.json
     *
     * Returns all threads and their preview replies grouped by page.
     * Includes OP attributes and last_replies array.
     */
    public function catalog(string $board): ResponseInterface
    {
        $boardModel = $this->boardService->getBoard($board);
        if (!$boardModel) {
            return $this->notFound();
        }
        $data = $this->apiService->getCatalog($boardModel);
        return $this->jsonResponse($data);
    }

    /**
     * GET /api/4chan/{board}/{page}.json
     *
     * Returns threads on a specific index page with OP and preview replies.
     * Pages start at 1.
     */
    public function indexPage(string $board, int $page): ResponseInterface
    {
        $boardModel = $this->boardService->getBoard($board);
        if (!$boardModel) {
            return $this->notFound();
        }
        $data = $this->apiService->getIndexPage($boardModel, $page);
        if ($data === null) {
            return $this->notFound();
        }
        return $this->jsonResponse($data);
    }

    /**
     * GET /api/4chan/{board}/thread/{no}.json
     *
     * Returns a full thread with OP and all replies.
     */
    public function thread(string $board, int $no): ResponseInterface
    {
        $boardModel = $this->boardService->getBoard($board);
        if (!$boardModel) {
            return $this->notFound();
        }
        $data = $this->apiService->getThread($boardModel, $no);
        if ($data === null) {
            return $this->notFound();
        }
        return $this->jsonResponse($data);
    }

    /**
     * GET /api/4chan/{board}/archive.json
     *
     * Returns an array of archived thread OP numbers.
     */
    public function archive(string $board): ResponseInterface
    {
        $boardModel = $this->boardService->getBoard($board);
        if (!$boardModel) {
            return $this->notFound();
        }
        $data = $this->apiService->getArchive($boardModel);
        return $this->jsonResponse($data);
    }

    /**
     * Send a JSON response with proper content-type header.
     *
     * @param array<string|int, mixed> $data
     */
    private function jsonResponse(array $data): ResponseInterface
    {
        return $this->response->json($data)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS, HEAD')
            ->withHeader('Cache-Control', 'public, max-age=10');
    }

    private function notFound(): ResponseInterface
    {
        return $this->response->json(['error' => 'Not Found'])->withStatus(404);
    }
}
