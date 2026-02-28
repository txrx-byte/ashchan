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
 * This controller provides compatibility with existing 4chan clients and tools
 * by returning data in the exact format expected by those clients.
 *
 * Endpoint mapping:
 *   GET /api/4chan/boards.json              → boards.json (board list)
 *   GET /api/4chan/{board}/threads.json     → threads.json (threadlist)
 *   GET /api/4chan/{board}/catalog.json     → catalog.json (full catalog)
 *   GET /api/4chan/{board}/{page}.json      → index page
 *   GET /api/4chan/{board}/thread/{no}.json → full thread
 *   GET /api/4chan/{board}/archive.json     → archive.json (archived threads)
 *
 * Response headers include CORS headers for browser access and Cache-Control
 * for CDN/proxy caching (10 seconds public cache).
 *
 * @see docs/API.md §4chan-Compatible API
 * @see FourChanApiService For data transformation logic
 * @link https://github.com/4chan/4chan-API 4chan API Specification
 */
final class FourChanApiController
{
    /**
     * @param FourChanApiService $apiService Service for 4chan API data transformation
     * @param BoardService $boardService Service for board lookups
     * @param HttpResponse $response HTTP response builder
     */
    public function __construct(
        private FourChanApiService $apiService,
        private BoardService $boardService,
        private HttpResponse $response,
    ) {}

    /**
     * GET /api/4chan/boards.json
     *
     * Returns a comprehensive list of all boards and their attributes.
     *
     * Response includes board configuration such as:
     * - Board identifier (slug) and title
     * - Content ratings (ws_board for SFW boards)
     * - Posting limits (per_page, pages, max_filesize, etc.)
     * - Cooldown settings
     * - Feature flags (text_only, user_ids, country_flags)
     *
     * @return ResponseInterface JSON response with boards array
     *
     * @example GET /api/4chan/boards.json
     * @example Response: {"boards": [{"board": "b", "title": "Random", "ws_board": 0, ...}]}
     *
     * @see GET /api/4chan/boards.json
     * @see FourChanApiService::getBoards()
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
     *
     * This is the "threadlist" endpoint - it provides lightweight thread
     * stubs for index page navigation without full post content.
     *
     * Each page contains up to 15 threads (configurable via fourchan_per_page).
     * Maximum 10 pages are returned (configurable via fourchan_max_pages).
     *
     * @param string $board Board slug (e.g., "b", "g", "v")
     * @return ResponseInterface JSON response with paginated thread list or 404
     *
     * @example GET /api/4chan/b/threads.json
     * @example Response: [{"page": 1, "threads": [{"no": 12345, "last_modified": 1234567890, "replies": 42}]}]
     *
     * @see GET /api/4chan/{board}/threads.json
     * @see FourChanApiService::getThreadList()
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
     *
     * The catalog provides a complete view of all active threads with:
     * - OP post details (subject, content preview, thumbnail)
     * - Thread statistics (reply count, image count)
     * - Last N replies (configurable, default 5)
     * - Bump/image limit indicators
     *
     * This endpoint is used by catalog-style browsing interfaces.
     *
     * @param string $board Board slug
     * @return ResponseInterface JSON response with catalog data or 404
     *
     * @example GET /api/4chan/b/catalog.json
     * @example Response: [{"page": 1, "threads": [{"no": 12345, "sub": "Thread title", "com": "...", "replies": 42, ...}]}]
     *
     * @see GET /api/4chan/{board}/catalog.json
     * @see FourChanApiService::getCatalog()
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
     *
     * Pages are 1-indexed. Page 1 contains the most recently bumped threads.
     * Each page contains up to 15 threads (configurable).
     *
     * Response includes:
     * - OP post with full details
     * - Last 5 replies (preview)
     * - Omitted posts/images count
     * - Thread metadata (sticky, locked, bump limits)
     *
     * @param string $board Board slug
     * @param int $page Page number (1-indexed, max 10)
     * @return ResponseInterface JSON response with page data or 404
     *
     * @example GET /api/4chan/b/1.json
     * @example Response: {"threads": [{"posts": [{"no": 12345, "resto": 0, ...}]}]}
     *
     * @see GET /api/4chan/{board}/{page}.json
     * @see FourChanApiService::getIndexPage()
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
     *
     * This endpoint returns the complete thread including:
     * - OP post with all attributes
     * - All non-deleted replies in chronological order
     * - Thread metadata (sticky, locked, archived status)
     * - Unique IP count (for non-archived threads)
     * - Bump/image limit indicators
     *
     * @param string $board Board slug
     * @param int $no Thread number (OP post ID)
     * @return ResponseInterface JSON response with full thread or 404
     *
     * @example GET /api/4chan/b/thread/12345.json
     * @example Response: {"posts": [{"no": 12345, "resto": 0, ...}, {"no": 12346, "resto": 12345, ...}]}
     *
     * @see GET /api/4chan/{board}/thread/{no}.json
     * @see FourChanApiService::getThread()
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
     *
     * Archive contains thread IDs (post numbers) that have been archived
     * due to age or lack of activity. Archived threads are read-only
     * and may be purged after extended periods.
     *
     * Maximum 3000 archived threads are returned (configurable).
     *
     * @param string $board Board slug
     * @return ResponseInterface JSON response with array of thread IDs or 404
     *
     * @example GET /api/4chan/b/archive.json
     * @example Response: [12000, 11999, 11998, ...]
     *
     * @see GET /api/4chan/{board}/archive.json
     * @see FourChanApiService::getArchive()
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
     * Sets appropriate headers for 4chan API compatibility:
     * - Content-Type: application/json
     * - Access-Control-Allow-Origin: * (CORS for browser clients)
     * - Access-Control-Allow-Methods: GET, OPTIONS, HEAD
     * - Cache-Control: public, max-age=10 (short cache for freshness)
     *
     * @param array<string|int, mixed> $data Data to encode as JSON
     * @return ResponseInterface HTTP response with JSON body and headers
     */
    private function jsonResponse(array $data): ResponseInterface
    {
        return $this->response->json($data)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS, HEAD')
            ->withHeader('Cache-Control', 'public, max-age=10');
    }

    /**
     * Return a 404 Not Found response.
     *
     * @return ResponseInterface HTTP 404 response with error message
     */
    private function notFound(): ResponseInterface
    {
        return $this->response->json(['error' => 'Not Found'])->withStatus(404);
    }
}
