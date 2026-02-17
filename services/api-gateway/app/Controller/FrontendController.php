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
        $boardsData = $this->fetchJson('boards', '/api/v1/boards');
        $boards = $boardsData['boards'] ?? [];

        // Group boards by category
        $grouped = [];
        foreach ($boards as $b) {
            $cat = $b['category'] ?? 'Other';
            $grouped[$cat][] = $b;
        }

        $categories = [];
        $order = ['Japanese Culture', 'Interests', 'Creative', 'Other'];
        foreach ($order as $catName) {
            if (isset($grouped[$catName])) {
                $categories[] = ['name' => $catName, 'boards' => $grouped[$catName]];
                unset($grouped[$catName]);
            }
        }
        foreach ($grouped as $catName => $catBoards) {
            $categories[] = ['name' => $catName, 'boards' => $catBoards];
        }

        $html = $this->renderer->render('home', [
            'boards'         => $boards,
            'categories'     => $categories,
            'total_posts'    => 0,
            'active_threads' => 0,
            'active_users'   => 0,
        ]);

        return $this->html($html);
    }

    /** GET /{slug}/ – Board index (paginated thread list) */
    public function board(RequestInterface $request, string $slug): ResponseInterface
    {
        $page = max(1, (int) $request->query('page', '1'));
        $boardsData = $this->fetchJson('boards', '/api/v1/boards');
        $boards = $boardsData['boards'] ?? [];

        $boardData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug));
        $board = $boardData['board'] ?? null;
        if (!$board) {
            return $this->html('<h1>Board not found</h1>', 404);
        }

        $threadsData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug) . '/threads?page=' . $page);
        $threads = $threadsData['threads'] ?? [];
        $totalPages = max(1, (int) ($threadsData['total_pages'] ?? 1));

        $html = $this->renderer->render('board', [
            'boards'         => $boards,
            'board_slug'     => $slug,
            'board_title'    => $board['title'] ?? $slug,
            'board_subtitle' => $board['subtitle'] ?? '',
            'page_title'     => '/' . $slug . '/ - ' . ($board['title'] ?? $slug),
            'page_num'       => $page,
            'total_pages'    => $totalPages,
            'threads'        => $threads,
            'thread_id'      => '',
        ]);

        return $this->html($html);
    }

    /** GET /{slug}/catalog – Board catalog view */
    public function catalog(string $slug): ResponseInterface
    {
        $boardsData = $this->fetchJson('boards', '/api/v1/boards');
        $boards = $boardsData['boards'] ?? [];

        $boardData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug));
        $board = $boardData['board'] ?? null;
        if (!$board) {
            return $this->html('<h1>Board not found</h1>', 404);
        }

        $catalogData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug) . '/catalog');
        $threads = $catalogData['threads'] ?? [];

        $html = $this->renderer->render('catalog', [
            'boards'      => $boards,
            'board_slug'  => $slug,
            'board_title' => $board['title'] ?? $slug,
            'page_title'  => '/' . $slug . '/ - Catalog',
            'threads'     => $threads,
            'thread_id'   => '',
            'page_num'    => 1,
        ]);

        return $this->html($html);
    }

    /** GET /{slug}/archive – Board archive */
    public function archive(string $slug): ResponseInterface
    {
        $boardsData = $this->fetchJson('boards', '/api/v1/boards');
        $boards = $boardsData['boards'] ?? [];

        $boardData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug));
        $board = $boardData['board'] ?? null;
        if (!$board) {
            return $this->html('<h1>Board not found</h1>', 404);
        }

        $archiveData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug) . '/archive');
        $archived = $archiveData['archived_threads'] ?? $archiveData['threads'] ?? [];

        $html = $this->renderer->render('archive', [
            'boards'           => $boards,
            'board_slug'       => $slug,
            'board_title'      => $board['title'] ?? $slug,
            'page_title'       => '/' . $slug . '/ - Archive',
            'archived_threads' => $archived,
            'thread_id'        => '',
            'page_num'         => 1,
        ]);

        return $this->html($html);
    }

    /** GET /{slug}/thread/{id} – Thread view */
    public function thread(string $slug, int $id): ResponseInterface
    {
        $boardsData = $this->fetchJson('boards', '/api/v1/boards');
        $boards = $boardsData['boards'] ?? [];

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

        $html = $this->renderer->render('thread', [
            'boards'        => $boards,
            'board_slug'    => $slug,
            'board_title'   => $board['title'] ?? $slug,
            'page_title'    => '/' . $slug . '/ - Thread No.' . $id,
            'thread_id'     => $id,
            'page_num'      => 1,
            'op'            => $op,
            'replies'       => $replies,
            'image_count'   => $threadData['image_count'] ?? 0,
            'thread_locked' => $threadData['locked'] ?? false,
            'thread_sticky' => $threadData['sticky'] ?? false,
        ]);

        return $this->html($html);
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

    /** Fetch JSON from a backend service. */
    private function fetchJson(string $service, string $path): array
    {
        $result = $this->proxyClient->forward($service, 'GET', $path, [
            'Content-Type' => 'application/json',
        ]);

        if ($result['status'] >= 400) {
            return ['error' => 'Upstream error', 'status' => $result['status']];
        }

        return json_decode($result['body'] ?? '{}', true) ?: [];
    }

    /** Return an HTML response. */
    private function html(string $body, int $status = 200): ResponseInterface
    {
        return $this->response->raw($body)
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
