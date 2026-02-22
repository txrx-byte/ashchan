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

final class ThreadController
{
    public function __construct(
        private BoardService $boardService,
        private HttpResponse $response,
    ) {}

    /** GET /api/v1/boards/{slug}/threads?page=1 */
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

    /** GET /api/v1/boards/{slug}/catalog */
    public function catalog(string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }
        $data = $this->boardService->getCatalog($board);
        return $this->response->json(['threads' => $data]);
    }

    /** GET /api/v1/boards/{slug}/archive */
    public function archive(string $slug): ResponseInterface
    {
        $board = $this->boardService->getBoard($slug);
        if (!$board) {
            return $this->response->json(['error' => 'Board not found'])->withStatus(404);
        }
        $data = $this->boardService->getArchive($board);
        return $this->response->json(['archived_threads' => $data]);
    }

    /** GET /api/v1/boards/{slug}/threads/{id} */
    public function show(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $includeIpHash = $this->isStaffMod($request);
        $data = $this->boardService->getThread($id, $includeIpHash);
        if (!$data) {
            return $this->response->json(['error' => 'Thread not found'])->withStatus(404);
        }
        return $this->response->json($data);
    }

    /** POST /api/v1/boards/{slug}/threads – Create new thread */
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

        // Validate content is not blank (whitespace-only counts as blank)
        $trimmedContent = trim($data['content']);
        if (!$board->text_only && $trimmedContent === '' && empty($data['media_url'])) {
            return $this->response->json(['error' => 'A comment or image is required'])->withStatus(400);
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

    /** POST /api/v1/boards/{slug}/threads/{id}/posts – Reply to thread */
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

        // Validate content is not blank (whitespace-only counts as blank)
        $trimmedContent = trim($data['content']);
        if ($trimmedContent === '' && empty($data['media_url'])) {
            return $this->response->json(['error' => 'A comment or image is required'])->withStatus(400);
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

    /** GET /api/v1/boards/{slug}/threads/{id}/posts?after=0 – New posts */
    public function newPosts(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $afterInput = $request->query('after', '0');
        $after = is_numeric($afterInput) ? (int) $afterInput : 0;
        $includeIpHash = $this->isStaffMod($request);
        $posts = $this->boardService->getPostsAfter($id, $after, $includeIpHash);
        return $this->response->json(['posts' => $posts]);
    }

    /** POST /api/v1/posts/delete – Delete own posts */
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

    /** GET /api/v1/posts/by-ip-hash/{hash} – Staff IP hash history lookup */
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

    /** DELETE /api/v1/boards/{slug}/posts/{id} – Staff delete post */
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

    /** POST /api/v1/boards/{slug}/threads/{id}/options – Staff thread options */
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

    /** POST /api/v1/boards/{slug}/posts/{id}/spoiler – Staff toggle spoiler */
    public function staffSpoiler(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $success = $this->boardService->toggleSpoiler($id);
        
        if ($success) {
            return $this->response->json(['success' => true]);
        }
        
        return $this->response->json(['error' => 'Failed to toggle spoiler'])->withStatus(500);
    }

    /** GET /api/v1/boards/{slug}/threads/{id}/ips – Staff IP lookup */
    public function staffThreadIps(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        // Auth check assumed (gateway)
        $data = $this->boardService->getThreadIps($id);
        return $this->response->json($data);
    }

    /**
     * POST /api/v1/posts/lookup – Bulk post media lookup for report queue.
     *
     * Expects JSON body: { "posts": [ {"board":"b","no":123}, ... ] }
     * Returns: { "results": { "b:123": { "thumb_url":"...", "media_url":"...", ... }, ... } }
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
