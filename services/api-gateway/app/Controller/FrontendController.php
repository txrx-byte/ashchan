<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ProxyClient;
use App\Service\TemplateRenderer;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Serves the frontend HTML pages and static files.
 * Fetches data from backend microservices via ProxyClient, then renders PHP templates.
 */
final class FrontendController
{
    public function __construct(
        private HttpResponse $response,
        private ProxyClient $proxyClient,
        private TemplateRenderer $renderer,
    ) {}

    /** GET / – Homepage with board listing */
    public function home(): ResponseInterface
    {
        $common = $this->getCommonData();
        $boards = $common['boards'] ?? [];

        // Group boards by category for the home page display
        $grouped = [];
        foreach ($boards as $b) {
            $cat = $b['category'];
            $grouped[$cat][] = $b;
        }

        $categories = [];
        $order = ['Japanese Culture', 'Video Games', 'Interests', 'Creative', 'Other', 'Misc. (NSFW)', 'Adult (NSFW)'];
        foreach ($order as $catName) {
            if (isset($grouped[$catName])) {
                $categories[] = ['name' => $catName, 'boards' => $grouped[$catName]];
                unset($grouped[$catName]);
            }
        }
        foreach ($grouped as $catName => $catBoards) {
            $categories[] = ['name' => $catName, 'boards' => $catBoards];
        }

        $html = $this->renderer->render('home', array_merge($common, [
            'categories'     => $categories,
            'total_posts'    => 0,
            'active_threads' => 0,
            'active_users'   => 0,
        ]));

        return $this->html($html);
    }

    /** GET /{slug}/ – Board index (paginated thread list) */
    public function board(RequestInterface $request, string $slug): ResponseInterface
    {
        $pageRaw = $request->getQueryParams()['page'] ?? '1';
        $page = is_numeric($pageRaw) ? max(1, (int) $pageRaw) : 1;
        
        $common = $this->getCommonData();
        $boardData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug));
        $board = $boardData['board'] ?? null;
        if (!$board) {
            return $this->html('<h1>Board not found</h1>', 404);
        }

        $threadsData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug) . '/threads?page=' . $page);
        $threads = $threadsData['threads'] ?? [];
        $totalPages = max(1, (int) ($threadsData['total_pages'] ?? 1));

        $html = $this->renderer->render('board', array_merge($common, [
            'board_slug'     => $slug,
            'board_title'    => $board['title'],
            'board_subtitle' => $board['subtitle'],
            'page_title'     => '/' . $slug . '/ - ' . $board['title'],
            'page_num'       => $page,
            'total_pages'    => $totalPages,
            'threads'        => $threads,
            'thread_id'      => '',
        ]));

        return $this->html($html);
    }

    /** GET /{slug}/catalog – Board catalog view */
    public function catalog(string $slug): ResponseInterface
    {
        $common = $this->getCommonData();
        $boardData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug));
        $board = $boardData['board'] ?? null;
        if (!$board) {
            return $this->html('<h1>Board not found</h1>', 404);
        }

        $catalogData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug) . '/catalog');
        $threads = $catalogData['threads'] ?? [];

        $html = $this->renderer->render('catalog', array_merge($common, [
            'board_slug'  => $slug,
            'board_title' => $board['title'],
            'page_title'  => '/' . $slug . '/ - Catalog',
            'threads'     => $threads,
            'thread_id'   => '',
            'page_num'    => 1,
        ]));

        return $this->html($html);
    }

    /** GET /{slug}/archive – Board archive */
    public function archive(string $slug): ResponseInterface
    {
        $common = $this->getCommonData();
        $boardData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug));
        $board = $boardData['board'] ?? null;
        if (!$board) {
            return $this->html('<h1>Board not found</h1>', 404);
        }

        $archiveData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug) . '/archive');
        $archived = $archiveData['archived_threads'] ?? $archiveData['threads'] ?? [];

        $html = $this->renderer->render('archive', array_merge($common, [
            'board_slug'       => $slug,
            'board_title'      => $board['title'],
            'page_title'       => '/' . $slug . '/ - Archive',
            'archived_threads' => $archived,
            'thread_id'        => '',
            'page_num'         => 1,
        ]));

        return $this->html($html);
    }

    /** GET /{slug}/thread/{id} – Thread view */
    public function thread(string $slug, int $id): ResponseInterface
    {
        $common = $this->getCommonData();
        $boardData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug));
        $board = $boardData['board'] ?? null;
        if (!$board) {
            return $this->html('<h1>Board not found</h1>', 404);
        }

        $threadData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug) . '/threads/' . $id);
        if (isset($threadData['error'])) {
            return $this->html('<h1>Thread not found</h1>', 404);
        }

        $op = $threadData['op'] ?? null;
        $replies = $threadData['replies'] ?? [];

        $html = $this->renderer->render('thread', array_merge($common, [
            'board_slug'    => $slug,
            'board_title'   => $board['title'],
            'page_title'    => '/' . $slug . '/ - Thread No.' . $id,
            'thread_id'     => $id,
            'page_num'      => 1,
            'op'            => $op,
            'replies'       => $replies,
            'image_count'   => $threadData['image_count'] ?? 0,
            'thread_locked' => $threadData['locked'] ?? false,
            'thread_sticky' => $threadData['sticky'] ?? false,
        ]));

        return $this->html($html);
    }

    /** POST /{slug}/threads – Create new thread (from form) */
    public function createThread(RequestInterface $request, string $slug): ResponseInterface
    {
        $input = $request->all();
        $mediaMetadata = $this->handleMediaUpload($request);

        if ($mediaMetadata) {
            $input = array_merge($input, $mediaMetadata);
        }

        $headers = [
            'Content-Type'    => 'application/json',
            'X-Forwarded-For' => $request->getHeaderLine('X-Forwarded-For') ?: $request->server('remote_addr', ''),
            'User-Agent'      => $request->getHeaderLine('User-Agent'),
        ];

        $result = $this->proxyClient->forward('boards', 'POST', "/api/v1/boards/{$slug}/threads", $headers, json_encode($input));

        if ($result['status'] >= 400) {
            return $this->html('<h1>Post Error</h1><p>' . htmlspecialchars($result['body']) . '</p>', $result['status']);
        }

        $data = json_decode($result['body'], true);
        $threadId = $data['thread_id'] ?? null;

        if ($threadId) {
            return $this->response->withStatus(303)->withHeader('Location', "/{$slug}/thread/{$threadId}");
        }

        return $this->response->withStatus(303)->withHeader('Location', "/{$slug}/");
    }

    /** POST /{slug}/thread/{id}/posts – Create new reply (from form) */
    public function createPost(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $input = $request->all();
        $mediaMetadata = $this->handleMediaUpload($request);

        if ($mediaMetadata) {
            $input = array_merge($input, $mediaMetadata);
        }

        $headers = [
            'Content-Type'    => 'application/json',
            'X-Forwarded-For' => $request->getHeaderLine('X-Forwarded-For') ?: $request->server('remote_addr', ''),
            'User-Agent'      => $request->getHeaderLine('User-Agent'),
        ];

        $result = $this->proxyClient->forward('boards', 'POST', "/api/v1/boards/{$slug}/threads/{$id}/posts", $headers, json_encode($input));

        if ($result['status'] >= 400) {
            return $this->html('<h1>Post Error</h1><p>' . htmlspecialchars($result['body']) . '</p>', $result['status']);
        }

        $data = json_decode($result['body'], true);
        $postId = $data['post_id'] ?? null;

        if ($postId) {
            return $this->response->withStatus(303)->withHeader('Location', "/{$slug}/thread/{$id}#p{$postId}");
        }

        return $this->response->withStatus(303)->withHeader('Location', "/{$slug}/thread/{$id}");
    }

    /**
     * Handle media upload if present in the request.
     * @return array<string, mixed>|null Media metadata or null
     */
    private function handleMediaUpload(RequestInterface $request): ?array
    {
        $file = $request->file('upfile');
        if (!$file || is_array($file) || !$file->isValid()) {
            return null;
        }

        $tmpPath = $file->getRealPath();
        if (!$tmpPath) {
            return null;
        }

        $body = [
            'upfile' => new \CURLFile(
                $tmpPath,
                (string) $file->getClientMediaType(),
                (string) $file->getClientFilename()
            ),
        ];

        $result = $this->proxyClient->forward('media', 'POST', '/api/v1/media/upload', [], $body);

        if ($result['status'] === 200) {
            return json_decode($result['body'], true);
        }

        return null;
    }

    /** Serve static files (development only). */
    public function staticFile(string $path): ResponseInterface
    {
        $candidates = [
            '/app/frontend/static/',
            dirname(__DIR__, 2) . '/frontend/static/',
            dirname(__DIR__, 3) . '/../frontend/static/',
        ];

        $filePath = null;
        foreach ($candidates as $basePath) {
            $realBase = realpath($basePath);
            if (!$realBase) continue;

            $candidate = realpath($basePath . $path);
            if ($candidate && str_starts_with($candidate, $realBase)) {
                $filePath = $candidate;
                break;
            }
        }

        if (!$filePath) {
            return $this->response->raw('Not found')->withStatus(404);
        }

        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $mimeTypes = [
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
        ];

        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
        $content = file_get_contents($filePath);

        return $this->response->raw($content)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /**
     * @return array{
     *  boards?: array<int, array{category: string, title: string, subtitle: string}>,
     *  board?: array{title: string, subtitle: string},
     *  threads?: array<mixed>,
     *  total_pages?: int,
     *  op?: array<mixed>,
     *  replies?: array<mixed>,
     *  image_count?: int,
     *  locked?: bool,
     *  sticky?: bool,
     *  archived_threads?: array<mixed>,
     *  error?: string,
     *  status?: int
     * }
     */
    private function fetchJson(string $service, string $path): array
    {
        $result = $this->proxyClient->forward($service, 'GET', $path, [
            'Content-Type' => 'application/json',
        ]);

        if ($result['status'] >= 400) {
            return ['error' => 'Upstream error', 'status' => $result['status']];
        }

        $body = $result['body'];
        if (!is_string($body)) {
            return ['error' => 'Invalid response body', 'status' => 500];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => 'Invalid JSON', 'status' => 500];
        }

        /**
         * @var array{
         *  boards?: array<int, array{category: string, title: string, subtitle: string}>,
         *  board?: array{title: string, subtitle: string},
         *  threads?: array<mixed>,
         *  total_pages?: int,
         *  op?: array<mixed>,
         *  replies?: array<mixed>,
         *  image_count?: int,
         *  locked?: bool,
         *  sticky?: bool,
         *  archived_threads?: array<mixed>,
         *  error?: string,
         *  status?: int
         * }
         */
        return $decoded;
    }

    /** Return an HTML response. */
    private function html(string $body, int $status = 200): ResponseInterface
    {
        return $this->response->raw($body)
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Fetch common data (boards, blotter) used on all pages.
     * @return array<string, mixed>
     */
    private function getCommonData(): array
    {
        $boardsData = $this->fetchJson('boards', '/api/v1/boards');
        $blotterData = $this->fetchJson('boards', '/api/v1/blotter');

        $boards = $boardsData['boards'] ?? [];
        
        // Group boards for the navbar
        $grouped = [];
        foreach ($boards as $b) {
            $cat = $b['category'] ?? 'Other';
            $grouped[$cat][] = $b;
        }

        $nav_groups = [];
        $order = ['Japanese Culture', 'Video Games', 'Interests', 'Creative', 'Other', 'Misc. (NSFW)', 'Adult (NSFW)'];
        foreach ($order as $catName) {
            if (isset($grouped[$catName])) {
                $nav_groups[] = ['name' => $catName, 'boards' => $grouped[$catName]];
                unset($grouped[$catName]);
            }
        }
        foreach ($grouped as $catName => $catBoards) {
            $nav_groups[] = ['name' => $catName, 'boards' => $catBoards];
        }

        return [
            'boards'     => $boards,
            'nav_groups' => $nav_groups,
            'blotter'    => $blotterData['blotter'] ?? [],
        ];
    }
}
