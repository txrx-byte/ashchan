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
 * Controller for thread and post operations.
 *
 * Handles all thread-related CRUD operations including:
 * - Thread listing, catalog, and archive views
 * - Thread creation and replies
 * - Post deletion (user and staff)
 * - Staff moderation actions (lock, sticky, permasage, spoiler)
 * - IP hash lookups for moderation
 * - Bulk post media lookups for report queue
 *
 * All endpoints support both anonymous users and authenticated staff.
 * Staff members receive additional data (IP hashes) based on their access level.
 *
 * @see docs/API.md §Thread and Post Endpoints
 * @see BoardService For business logic implementation
 */
final class ThreadController
{
    /**
     * @param BoardService $boardService Service for board and thread operations
     * @param HttpResponse $response HTTP response builder
     */
    public function __construct(
        private BoardService $boardService,
        private HttpResponse $response,
    ) {}

    /**
     * GET /api/v1/boards/{slug}/threads?page=1
     *
     * Get paginated thread index for a board.
     *
     * Returns threads sorted by bump order (sticky threads first, then by
     * last reply time). Each thread includes the OP and up to 5 latest replies.
     *
     * Staff moderators (level >= 1) receive IP hash data for each post.
     *
     * @param RequestInterface $request HTTP request with query parameters
     * @param string $slug Board slug identifier
     * @return ResponseInterface JSON response with paginated threads or 404
     *
     * @example GET /api/v1/boards/b/threads?page=1
     * @example Response: {"threads": [...], "page": 1, "total_pages": 10, "total": 150}
     *
     * @see GET /api/v1/boards/{slug}/threads
     * @see BoardService::getThreadIndex()
     */
    public function index(RequestInterface $request, string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }
        $pageInput = $request->query('page', '1');
        $page = max(1, is_numeric($pageInput) ? (int) $pageInput : 1);
        $includeIpHash = $this->isStaffMod($request);
        $data = $this->boardService->getThreadIndex($board, $page, 15, $includeIpHash);
        return $this->response->json($data);
    }

    /**
     * GET /api/v1/boards/{slug}/catalog
     *
     * Get board catalog for grid-style browsing.
     *
     * Returns all active threads with OP preview data including:
     * - Subject and content preview
     * - Thumbnail URL
     * - Reply and image counts
     * - Last bump time
     *
     * The catalog is optimized for visual browsing interfaces.
     *
     * @param string $slug Board slug identifier
     * @return ResponseInterface JSON response with catalog data or 404
     *
     * @example GET /api/v1/boards/b/catalog
     * @example Response: {"threads": [{"id": 12345, "op": {"subject": "...", "thumb_url": "..."}, ...}]}
     *
     * @see GET /api/v1/boards/{slug}/catalog
     * @see BoardService::getCatalog()
     */
    public function catalog(string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }
        $data = $this->boardService->getCatalog($board);
        return $this->response->json(['threads' => $data]);
    }

    /**
     * GET /api/v1/boards/{slug}/archive
     *
     * Get archived threads for a board.
     *
     * Returns threads that have been archived due to age or lack of activity.
     * Archived threads are read-only and may be purged after extended periods.
     *
     * Each entry includes:
     * - Thread ID
     * - Excerpt (subject or content preview)
     * - Lowercase excerpt for search indexing
     *
     * @param string $slug Board slug identifier
     * @return ResponseInterface JSON response with archived threads or 404
     *
     * @example GET /api/v1/boards/b/archive
     * @example Response: {"archived_threads": [{"id": 12000, "excerpt": "...", ...}]}
     *
     * @see GET /api/v1/boards/{slug}/archive
     * @see BoardService::getArchive()
     */
    public function archive(string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }
        $data = $this->boardService->getArchive($board);
        return $this->response->json(['archived_threads' => $data]);
    }

    /**
     * GET /api/v1/boards/{slug}/threads/{id}
     *
     * Get full thread with all posts.
     *
     * Returns the complete thread including:
     * - OP post with all attributes
     * - All non-deleted replies in chronological order
     * - Thread metadata (sticky, locked, archived)
     * - Backlinks (posts that quote this post)
     *
     * Staff moderators receive IP hash data for each post.
     *
     * @param RequestInterface $request HTTP request for staff level check
     * @param string $slug Board slug identifier
     * @param int $id Thread ID
     * @return ResponseInterface JSON response with full thread or 404
     *
     * @example GET /api/v1/boards/b/threads/12345
     * @example Response: {"thread_id": 12345, "op": {...}, "replies": [...]}
     *
     * @see GET /api/v1/boards/{slug}/threads/{id}
     * @see BoardService::getThread()
     */
    public function show(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $includeIpHash = $this->isStaffMod($request);
        $data = $this->boardService->getThread($id, $includeIpHash);
        if (!$data) {
            return $this->response->json(['error' => 'Thread not found'])->withStatus(404);
        }
        return $this->response->json($data);
    }

    /**
     * POST /api/v1/boards/{slug}/threads
     *
     * Create a new thread.
     *
     * Creates a new thread with an OP (original poster) post.
     * The thread ID is allocated from the same sequence as post IDs.
     *
     * Required fields:
     * - content (com): Post body text (required for text-only boards)
     * - media_url: Image/media URL (required for non-text-only boards)
     *
     * Optional fields:
     * - name: Display name (may include tripcode with #password)
     * - email: Email field (often "sage" to not bump)
     * - subject (sub): Thread subject
     * - password (pwd): Deletion password
     * - spoiler: Whether image is spoilered
     *
     * Media fields (injected by API gateway after upload):
     * - media_url, thumb_url, media_filename, media_size, media_dimensions, media_hash
     *
     * @param RequestInterface $request HTTP request with thread data
     * @param string $slug Board slug identifier
     * @return ResponseInterface JSON response with created thread (201) or error
     *
     * @example POST /api/v1/boards/b/threads
     * @example Request: {"com": "Hello world", "media_url": "/img/abc.jpg", ...}
     * @example Response (201): {"id": 12345, "posts": [{"no": 12345, ...}]}
     * @example Response (400): {"error": "An image is required"}
     *
     * @see POST /api/v1/boards/{slug}/threads
     * @see BoardService::createThread()
     */
    public function create(RequestInterface $request, string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }

        $input = $request->all();
        $name = isset($input['name']) && is_string($input['name']) ? $input['name'] : '';
        $email = isset($input['email']) && is_string($input['email']) ? $input['email'] : '';
        $subject = isset($input['sub']) && is_string($input['sub']) ? $input['sub'] : '';
        $content = isset($input['com']) && is_string($input['com']) ? $input['com'] : '';
        $password = isset($input['pwd']) && is_string($input['pwd']) ? $input['pwd'] : '';
        $spoiler = isset($input['spoiler']) ? (bool) $input['spoiler'] : false;

        $data = [
            'name'            => $name,
            'email'           => $email,
            'subject'         => $subject,
            'content'         => $content,
            'password'        => $password,
            'spoiler'         => $spoiler,
            'ip_address'      => $this->getClientIp($request),
            // Media fields injected by API gateway after upload
            'media_url'       => $input['media_url'] ?? null,
            'thumb_url'       => $input['thumb_url'] ?? null,
            'media_filename'  => $input['media_filename'] ?? null,
            'media_size'      => $input['media_size'] ?? null,
            'media_dimensions'=> $input['media_dimensions'] ?? null,
            'media_hash'      => $input['media_hash'] ?? null,
        ];

        // Enforce field length limits
        if (mb_strlen($name) > 100) {
            return $this->response->json(['error' => 'Name must not exceed 100 characters'])->withStatus(400);
        }
        if (mb_strlen($subject) > 100) {
            return $this->response->json(['error' => 'Subject must not exceed 100 characters'])->withStatus(400);
        }
        if (mb_strlen($content) > 20000) {
            return $this->response->json(['error' => 'Comment must not exceed 20000 characters'])->withStatus(400);
        }

        // Validate content is not blank (whitespace-only counts as blank)
        $trimmedContent = trim($data['content']);
        // All boards require an image upload
        if (!$board->text_only && empty($data['media_url'])) {
            return $this->response->json(['error' => 'An image is required'])->withStatus(400);
        }
        // Even with media, if text_only board, require actual text
        if ($board->text_only && $trimmedContent === '') {
            return $this->response->json(['error' => 'A comment is required'])->withStatus(400);
        }
        $data['content'] = $trimmedContent;

        try {
            $result = $this->boardService->createThread($board, $data);
            return $this->response->json($result)->withStatus(201);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'An internal error occurred'])->withStatus(500);
        }
    }

    /**
     * POST /api/v1/boards/{slug}/threads/{id}/posts
     *
     * Reply to a thread.
     *
     * Creates a new reply post in an existing thread.
     *
     * Required fields:
     * - content (com): Post body text
     * - media_url: Image/media URL
     *
     * Optional fields:
     * - name, email, subject, password, spoiler (same as thread creation)
     *
     * Special email values:
     * - "sage": Do not bump the thread
     * - Empty: Default (bumps thread)
     *
     * @param RequestInterface $request HTTP request with post data
     * @param string $slug Board slug identifier
     * @param int $id Thread ID to reply to
     * @return ResponseInterface JSON response with created post (201) or error
     *
     * @example POST /api/v1/boards/b/threads/12345/posts
     * @example Request: {"com": "Reply content", "media_url": "/img/def.jpg"}
     * @example Response (201): {"id": 12346, "no": 12346, "resto": 12345, ...}
     * @example Response (404): {"error": "Thread not found"}
     * @example Response (400): {"error": "An image is required"}
     * @example Response (422): {"error": "Thread is locked"}
     *
     * @see POST /api/v1/boards/{slug}/threads/{id}/posts
     * @see BoardService::createPost()
     */
    public function reply(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $thread = Thread::find($id);
        if (!$thread) {
            return $this->response->json(['error' => 'Thread not found'])->withStatus(404);
        }

        $input = $request->all();
        $name = isset($input['name']) && is_string($input['name']) ? $input['name'] : '';
        $email = isset($input['email']) && is_string($input['email']) ? $input['email'] : '';
        $subject = isset($input['sub']) && is_string($input['sub']) ? $input['sub'] : '';
        $content = isset($input['com']) && is_string($input['com']) ? $input['com'] : '';
        $password = isset($input['pwd']) && is_string($input['pwd']) ? $input['pwd'] : '';
        $spoiler = isset($input['spoiler']) ? (bool) $input['spoiler'] : false;

        $data = [
            'name'            => $name,
            'email'           => $email,
            'subject'         => $subject,
            'content'         => $content,
            'password'        => $password,
            'spoiler'         => $spoiler,
            'ip_address'      => $this->getClientIp($request),
            'media_url'       => $input['media_url'] ?? null,
            'thumb_url'       => $input['thumb_url'] ?? null,
            'media_filename'  => $input['media_filename'] ?? null,
            'media_size'      => $input['media_size'] ?? null,
            'media_dimensions'=> $input['media_dimensions'] ?? null,
            'media_hash'      => $input['media_hash'] ?? null,
        ];

        // Enforce field length limits
        if (mb_strlen($name) > 100) {
            return $this->response->json(['error' => 'Name must not exceed 100 characters'])->withStatus(400);
        }
        if (mb_strlen($subject) > 100) {
            return $this->response->json(['error' => 'Subject must not exceed 100 characters'])->withStatus(400);
        }
        if (mb_strlen($content) > 20000) {
            return $this->response->json(['error' => 'Comment must not exceed 20000 characters'])->withStatus(400);
        }

        // Validate content is not blank (whitespace-only counts as blank)
        $trimmedContent = trim($data['content']);
        // All posts require an image upload
        if (empty($data['media_url'])) {
            return $this->response->json(['error' => 'An image is required'])->withStatus(400);
        }
        $data['content'] = $trimmedContent;

        try {
            $result = $this->boardService->createPost($thread, $data);
            return $this->response->json($result)->withStatus(201);
        } catch (\RuntimeException $e) {
            return $this->response->json(['error' => $e->getMessage()])->withStatus(422);
        } catch (\Throwable $e) {
            return $this->response->json(['error' => 'An internal error occurred'])->withStatus(500);
        }
    }

    /**
     * GET /api/v1/boards/{slug}/threads/{id}/posts?after=0
     *
     * Get new posts in a thread since a given post ID.
     *
     * Used for real-time thread updates (polling or long-polling).
     * Returns all posts with ID greater than the specified "after" value.
     *
     * @param RequestInterface $request HTTP request with query parameters
     * @param string $slug Board slug identifier
     * @param int $id Thread ID
     * @return ResponseInterface JSON response with new posts
     *
     * @example GET /api/v1/boards/b/threads/12345/posts?after=12350
     * @example Response: {"posts": [{"no": 12351, ...}, {"no": 12352, ...}]}
     *
     * @see GET /api/v1/boards/{slug}/threads/{id}/posts
     * @see BoardService::getPostsAfter()
     */
    public function newPosts(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $afterInput = $request->query('after', '0');
        $after = is_numeric($afterInput) ? (int) $afterInput : 0;
        $includeIpHash = $this->isStaffMod($request);
        $posts = $this->boardService->getPostsAfter($id, $after, $includeIpHash);
        return $this->response->json(['posts' => $posts]);
    }

    /**
     * POST /api/v1/posts/delete
     *
     * Delete own posts using deletion password.
     *
     * Allows users to delete their own posts by providing the password
     * that was set when the post was created.
     *
     * Options:
     * - ids: Array of post IDs to delete
     * - password: Deletion password
     * - image_only: If true, only delete the image (not the post)
     *
     * @param RequestInterface $request HTTP request with deletion data
     * @return ResponseInterface JSON response with deletion count
     *
     * @example POST /api/v1/posts/delete
     * @example Request: {"ids": [12345, 12346], "password": "mypassword"}
     * @example Response: {"deleted": 2}
     * @example Response (400): {"error": "Missing required fields"}
     *
     * @see POST /api/v1/posts/delete
     * @see BoardService::deletePost()
     */
    public function deletePost(RequestInterface $request): ResponseInterface
    {
        $idsInput = $request->input('ids', []);
        $ids = is_array($idsInput) ? $idsInput : [];
        $passwordInput = $request->input('password', '');
        $password = is_string($passwordInput) ? $passwordInput : '';
        $imageOnly = (bool) $request->input('image_only', false);

        if (empty($ids) || empty($password)) {
            return $this->response->json(['error' => 'Missing required fields'])->withStatus(400);
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if (is_numeric($id) && $this->boardService->deletePost((int) $id, $password, $imageOnly)) {
                $deleted++;
            }
        }

        return $this->response->json(['deleted' => $deleted]);
    }

    /**
     * Extract client IP address from request headers.
     *
     * Handles proxied requests by checking forwarded headers in order:
     * 1. X-Forwarded-For (leftmost IP = original client)
     * 2. X-Real-IP
     * 3. remote_addr (direct connection)
     *
     * Validates IP format and falls back to 127.0.0.1 if invalid.
     *
     * @param RequestInterface $request HTTP request
     * @return string Validated client IP address
     */
    private function getClientIp(RequestInterface $request): string
    {
        $ip = $request->getHeaderLine('X-Forwarded-For')
            ?: $request->getHeaderLine('X-Real-IP')
            ?: $request->server('remote_addr', '127.0.0.1');

        if (!is_string($ip) || $ip === '') {
            return '127.0.0.1';
        }

        // X-Forwarded-For may contain a comma-separated list; take the leftmost (client) IP
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip, 2)[0]);
        }

        // Validate the IP address format
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '127.0.0.1';
    }

    /**
     * Check if the requesting staff user is at least a moderator.
     *
     * The API gateway forwards X-Staff-Level for authenticated staff.
     * Levels: admin(3), manager(2), mod(1), janitor(0).
     * Only mod+ (level >= 1) can see IP hashes.
     *
     * @param RequestInterface $request HTTP request with staff headers
     * @return bool True if user is moderator or higher
     */
    private function isStaffMod(RequestInterface $request): bool
    {
        $level = $request->getHeaderLine('X-Staff-Level');
        if ($level === '') {
            return false;
        }
        // Accept both numeric ("1") and named ("mod", "manager", "admin") levels
        $numericLevel = match (strtolower($level)) {
            'admin'    => 3,
            'manager'  => 2,
            'mod', 'moderator' => 1,
            'janitor'  => 0,
            default    => is_numeric($level) ? (int) $level : -1,
        };
        return $numericLevel >= 1;
    }

    /**
     * GET /api/v1/posts/by-ip-hash/{hash}
     *
     * Staff IP hash history lookup.
     *
     * Allows moderators to find all posts by a specific IP hash.
     * Used for investigating spam, raids, or rule violations.
     *
     * Requires moderator access level (level >= 1).
     *
     * @param RequestInterface $request HTTP request for auth check
     * @param string $hash 16-character hex IP hash
     * @return ResponseInterface JSON response with posts or error
     *
     * @example GET /api/v1/posts/by-ip-hash/a1b2c3d4e5f67890?limit=50
     * @example Response: {"ip_hash": "a1b2c3d4e5f67890", "count": 5, "posts": [...]}
     * @example Response (403): {"error": "Forbidden"}
     * @example Response (400): {"error": "Invalid IP hash format"}
     *
     * @see GET /api/v1/posts/by-ip-hash/{hash}
     * @see BoardService::getPostsByIpHash()
     */
    public function postsByIpHash(RequestInterface $request, string $hash): ResponseInterface
    {
        // Validate staff access (mod+)
        if (!$this->isStaffMod($request)) {
            return $this->response->json(['error' => 'Forbidden'])->withStatus(403);
        }

        // Validate hash format (16-char hex)
        if (!preg_match('/^[0-9a-f]{16}$/', $hash)) {
            return $this->response->json(['error' => 'Invalid IP hash format'])->withStatus(400);
        }

        $limitInput = $request->query('limit', '100');
        $limit = min(500, max(1, is_numeric($limitInput) ? (int) $limitInput : 100));

        $posts = $this->boardService->getPostsByIpHash($hash, $limit);

        return $this->response->json([
            'ip_hash' => $hash,
            'count'   => count($posts),
            'posts'   => $posts,
        ]);
    }

    /**
     * DELETE /api/v1/boards/{slug}/posts/{id}
     *
     * Staff delete post.
     *
     * Allows staff to delete any post regardless of deletion password.
     * Authentication is handled by the API gateway.
     *
     * @param RequestInterface $request HTTP request with query parameters
     * @param string $slug Board slug identifier
     * @param int $id Post ID to delete
     * @return ResponseInterface JSON response with deletion status
     *
     * @example DELETE /api/v1/boards/b/posts/12345?file_only=true
     * @example Response: {"success": true}
     * @example Response (500): {"error": "Failed to delete post"}
     *
     * @see DELETE /api/v1/boards/{slug}/posts/{id}
     * @see BoardService::staffDeletePost()
     */
    public function staffDeletePost(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $imageOnly = (bool) $request->query('file_only', false);

        // In production, authentication is handled by the Gateway.
        // We assume if this endpoint is reached, the user is authorized.

        $success = $this->boardService->staffDeletePost($id, $imageOnly);

        if ($success) {
            return $this->response->json(['success' => true]);
        }

        return $this->response->json(['error' => 'Failed to delete post'])->withStatus(500);
    }

    /**
     * POST /api/v1/boards/{slug}/threads/{id}/options
     *
     * Staff thread options (sticky, lock, permasage).
     *
     * Allows staff to toggle thread moderation options:
     * - sticky: Pin thread to top of board
     * - lock: Prevent new replies
     * - permasage: Prevent thread from bumping (always sage)
     *
     * @param RequestInterface $request HTTP request with option parameter
     * @param string $slug Board slug identifier
     * @param int $id Thread ID
     * @return ResponseInterface JSON response with toggle status
     *
     * @example POST /api/v1/boards/b/threads/12345/options
     * @example Request: {"option": "sticky"}
     * @example Response: {"success": true}
     * @example Response (400): {"error": "Invalid option"}
     *
     * @see POST /api/v1/boards/{slug}/threads/{id}/options
     * @see BoardService::toggleThreadOption()
     */
    public function staffThreadOptions(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $option = $request->input('option');

        if (!is_string($option) || !in_array($option, ['sticky', 'lock', 'permasage'])) {
            return $this->response->json(['error' => 'Invalid option'])->withStatus(400);
        }

        $success = $this->boardService->toggleThreadOption($id, $option);

        if ($success) {
            return $this->response->json(['success' => true]);
        }

        return $this->response->json(['error' => 'Failed to toggle option'])->withStatus(500);
    }

    /**
     * POST /api/v1/boards/{slug}/posts/{id}/spoiler
     *
     * Staff toggle spoiler on post image.
     *
     * Allows staff to mark a post's image as spoilered (hidden by default).
     *
     * @param RequestInterface $request HTTP request (body not used)
     * @param string $slug Board slug identifier
     * @param int $id Post ID
     * @return ResponseInterface JSON response with toggle status
     *
     * @example POST /api/v1/boards/b/posts/12345/spoiler
     * @example Response: {"success": true}
     *
     * @see POST /api/v1/boards/{slug}/posts/{id}/spoiler
     * @see BoardService::toggleSpoiler()
     */
    public function staffSpoiler(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $success = $this->boardService->toggleSpoiler($id);

        if ($success) {
            return $this->response->json(['success' => true]);
        }

        return $this->response->json(['error' => 'Failed to toggle spoiler'])->withStatus(500);
    }

    /**
     * GET /api/v1/boards/{slug}/threads/{id}/ips
     *
     * Staff IP lookup for thread.
     *
     * Returns IP hash data for all posts in a thread.
     * Used by moderators to identify patterns (spam, raids, etc.).
     *
     * Requires staff authentication (handled by gateway).
     *
     * @param RequestInterface $request HTTP request (auth via gateway)
     * @param string $slug Board slug identifier
     * @param int $id Thread ID
     * @return ResponseInterface JSON response with IP data
     *
     * @example GET /api/v1/boards/b/threads/12345/ips
     * @example Response: {"posts": [{"no": 12345, "ip_hash": "..."}, ...]}
     *
     * @see GET /api/v1/boards/{slug}/threads/{id}/ips
     * @see BoardService::getThreadIps()
     */
    public function staffThreadIps(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        // Auth check assumed (gateway)
        $data = $this->boardService->getThreadIps($id);
        return $this->response->json($data);
    }

    /**
     * POST /api/v1/posts/lookup
     *
     * Bulk post media lookup for report queue.
     *
     * Expects JSON body: { "posts": [ {"board":"b","no":123}, ... ] }
     * Returns: { "results": { "b:123": { "thumb_url":"...", "media_url":"...", ... }, ... } }
     *
     * Used by the moderation report queue to quickly fetch media information
     * for reported posts without loading full post data.
     *
     * Limited to 50 lookups per call to prevent abuse.
     *
     * @param RequestInterface $request HTTP request with JSON body
     * @return ResponseInterface JSON response with media data
     *
     * @example POST /api/v1/posts/lookup
     * @example Request: {"posts": [{"board": "b", "no": 12345}]}
     * @example Response: {"results": {"b:12345": {"thumb_url": "...", "media_url": "..."}}}
     *
     * @see POST /api/v1/posts/lookup
     */
    public function bulkLookup(RequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $body */
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return $this->response->json(['results' => []]);
        }
        $posts = $body['posts'] ?? [];

        if (!is_array($posts) || count($posts) === 0) {
            return $this->response->json(['results' => []]);
        }

        // Limit to 50 lookups per call
        $posts = array_slice($posts, 0, 50);

        // Group by board slug, collect unique post IDs per board
        $boardPostIds = [];
        $keyMap = []; // maps "board:no" to avoid duplicates
        foreach ($posts as $item) {
            /** @var array<string, mixed> $item */
            $board = (string) ($item['board'] ?? '');
            $no = (int) ($item['no'] ?? 0);
            if ($board === '' || $no === 0) {
                continue;
            }
            $key = $board . ':' . $no;
            if (isset($keyMap[$key])) {
                continue;
            }
            $keyMap[$key] = true;
            $boardPostIds[$board][] = $no;
        }

        $results = [];

        // Batch query per board slug (typically 1-3 boards per call)
        foreach ($boardPostIds as $boardSlug => $postIds) {
            /** @var \Hyperf\Database\Model\Collection<int, \App\Model\Post> $foundPosts */
            $foundPosts = \App\Model\Post::query()
                ->join('threads', 'posts.thread_id', '=', 'threads.id')
                ->join('boards', 'threads.board_id', '=', 'boards.id')
                ->where('boards.slug', $boardSlug)
                ->whereIn('posts.id', $postIds)
                ->select([
                    'posts.id',
                    'posts.thumb_url',
                    'posts.media_url',
                    'posts.media_filename',
                    'posts.media_dimensions',
                    'posts.spoiler_image',
                    'posts.subject',
                    'posts.content',
                    'posts.content_html',
                ])
                ->get();

            foreach ($foundPosts as $post) {
                $postId = (int) $post->getAttribute('id');
                $key = $boardSlug . ':' . $postId;
                $results[$key] = [
                    'thumb_url' => $post->getAttribute('thumb_url'),
                    'media_url' => $post->getAttribute('media_url'),
                    'media_filename' => $post->getAttribute('media_filename'),
                    'media_dimensions' => $post->getAttribute('media_dimensions'),
                    'spoiler_image' => (bool) $post->getAttribute('spoiler_image'),
                    'sub' => $post->getAttribute('subject') ?: null,
                    'com' => $post->getAttribute('content') ?: null,
                ];
            }
        }

        return $this->response->json(['results' => $results]);
    }
}
