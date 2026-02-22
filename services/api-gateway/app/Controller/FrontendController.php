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

use App\Service\ProxyClient;
use App\Service\TemplateRenderer;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface;
use Hyperf\Logger\LoggerFactory;

/**
 * Serves the frontend HTML pages and static files.
 * Fetches data from backend microservices via ProxyClient, then renders PHP templates.
 */
final class FrontendController
{
    private const COMMON_DATA_CACHE_TTL = 60; // Cache boards/blotter for 60 seconds

    public function __construct(
        private HttpResponse $response,
        private ProxyClient $proxyClient,
        private TemplateRenderer $renderer,
        private LoggerFactory $loggerFactory,
        private Redis $redis,
    ) {}

    /** GET /about – About page */
    public function about(): ResponseInterface
    {
        $common = $this->getCommonData();
        $html = $this->renderer->render('about', $common);
        return $this->html($html);
    }

    /** GET /rules – Rules page */
    public function rules(): ResponseInterface
    {
        $common = $this->getCommonData();
        $html = $this->renderer->render('rules', $common);
        return $this->html($html);
    }

    /** GET /feedback – Feedback form page */
    public function feedback(): ResponseInterface
    {
        $common = $this->getCommonData();
        $html = $this->renderer->render('feedback', $common);
        return $this->html($html);
    }

    /** GET /legal – Legal hub page */
    public function legal(): ResponseInterface
    {
        $common = $this->getCommonData();
        $html = $this->renderer->render('legal', $common);
        return $this->html($html);
    }

    /** GET /legal/privacy – Privacy policy */
    public function legalPrivacy(): ResponseInterface
    {
        $common = $this->getCommonData();
        $html = $this->renderer->render('legal-privacy', $common);
        return $this->html($html);
    }

    /** GET /legal/terms – Terms of service */
    public function legalTerms(): ResponseInterface
    {
        $common = $this->getCommonData();
        $html = $this->renderer->render('legal-terms', $common);
        return $this->html($html);
    }

    /** GET /legal/cookies – Cookie policy */
    public function legalCookies(): ResponseInterface
    {
        $common = $this->getCommonData();
        $html = $this->renderer->render('legal-cookies', $common);
        return $this->html($html);
    }

    /** GET /legal/rights – Privacy rights center (GDPR/CCPA) */
    public function legalRights(): ResponseInterface
    {
        $common = $this->getCommonData();
        $html = $this->renderer->render('legal-rights', $common);
        return $this->html($html);
    }

    /** GET /legal/contact – Contact page */
    public function legalContact(): ResponseInterface
    {
        $common = $this->getCommonData();
        $html = $this->renderer->render('legal-contact', $common);
        return $this->html($html);
    }

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

        // Fetch common data + board data + threads in parallel
        $common = [];
        $boardData = [];
        $threadsData = [];
        $fetchErrors = [];
        $encodedSlug = urlencode($slug);
        $logger = $this->loggerFactory->get('frontend');
        $wg = new \Swoole\Coroutine\WaitGroup();
        $wg->add(3);
        go(function () use (&$common, &$fetchErrors, $wg, $logger) {
            try {
                $common = $this->getCommonData();
            } catch (\Throwable $e) {
                $fetchErrors['common'] = $e->getMessage();
                $logger->error('Coroutine failed fetching common data: ' . $e->getMessage());
            } finally {
                $wg->done();
            }
        });
        go(function () use (&$boardData, &$fetchErrors, $encodedSlug, $wg, $logger) {
            try {
                $boardData = $this->fetchJson('boards', '/api/v1/boards/' . $encodedSlug);
            } catch (\Throwable $e) {
                $fetchErrors['board'] = $e->getMessage();
                $logger->error('Coroutine failed fetching board data: ' . $e->getMessage());
            } finally {
                $wg->done();
            }
        });
        go(function () use (&$threadsData, &$fetchErrors, $encodedSlug, $page, $wg, $logger) {
            try {
                $threadsData = $this->fetchJson('boards', '/api/v1/boards/' . $encodedSlug . '/threads?page=' . $page);
            } catch (\Throwable $e) {
                $fetchErrors['threads'] = $e->getMessage();
                $logger->error('Coroutine failed fetching threads: ' . $e->getMessage());
            } finally {
                $wg->done();
            }
        });
        if (!$wg->wait(5)) {
            $logger->error('Upstream fetch timeout for board page /' . $slug . '/');
            return $this->html('<h1>Service Unavailable</h1><p>Upstream services did not respond in time. Please try again.</p>', 503);
        }

        if (isset($fetchErrors['board'])) {
            return $this->html('<h1>Service Error</h1><p>Could not load board data.</p>', 502);
        }

        $board = $boardData['board'] ?? null;
        if (!$board) {
            return $this->html('<h1>Board not found</h1>', 404);
        }

        $denied = $this->checkStaffOnlyAccess($request, $board);
        if ($denied) {
            return $denied;
        }
        $threads = $threadsData['threads'] ?? [];
        $threads = $this->rewriteMediaUrls($threads);
        $totalPages = max(1, (int) ($threadsData['total_pages'] ?? 1));

        $html = $this->renderer->render('board', array_merge($common, [
            'board_slug'     => $slug,
            'board_title'    => $board['title'],
            'board_subtitle' => $board['subtitle'],
            'page_title'     => '/' . $slug . '/ - ' . (string) ($board['title'] ?? ''),
            'page_num'       => $page,
            'total_pages'    => $totalPages,
            'threads'        => $threads,
            'thread_id'      => '',
            'user_ids'       => !empty($board['user_ids']),
            'country_flags'  => !empty($board['country_flags']),
            'is_staff'       => $this->isStaff($request),
            'staff_level'    => $this->getStaffLevel(),
            'extra_css'      => $this->getExtraCss($board),
        ]));

        return $this->html($html);
    }

    /** GET /{slug}/catalog – Board catalog view */
    public function catalog(RequestInterface $request, string $slug): ResponseInterface
    {
        $common = [];
        $boardData = [];
        $catalogData = [];
        $fetchErrors = [];
        $encodedSlug = urlencode($slug);
        $logger = $this->loggerFactory->get('frontend');
        $wg = new \Swoole\Coroutine\WaitGroup();
        $wg->add(3);
        go(function () use (&$common, &$fetchErrors, $wg, $logger) {
            try {
                $common = $this->getCommonData();
            } catch (\Throwable $e) {
                $fetchErrors['common'] = $e->getMessage();
                $logger->error('Coroutine failed fetching common data: ' . $e->getMessage());
            } finally {
                $wg->done();
            }
        });
        go(function () use (&$boardData, &$fetchErrors, $encodedSlug, $wg, $logger) {
            try {
                $boardData = $this->fetchJson('boards', '/api/v1/boards/' . $encodedSlug);
            } catch (\Throwable $e) {
                $fetchErrors['board'] = $e->getMessage();
                $logger->error('Coroutine failed fetching board data: ' . $e->getMessage());
            } finally {
                $wg->done();
            }
        });
        go(function () use (&$catalogData, &$fetchErrors, $encodedSlug, $wg, $logger) {
            try {
                $catalogData = $this->fetchJson('boards', '/api/v1/boards/' . $encodedSlug . '/catalog');
            } catch (\Throwable $e) {
                $fetchErrors['catalog'] = $e->getMessage();
                $logger->error('Coroutine failed fetching catalog: ' . $e->getMessage());
            } finally {
                $wg->done();
            }
        });
        if (!$wg->wait(5)) {
            $logger->error('Upstream fetch timeout for catalog /' . $slug . '/catalog');
            return $this->html('<h1>Service Unavailable</h1><p>Upstream services did not respond in time. Please try again.</p>', 503);
        }

        if (isset($fetchErrors['board'])) {
            return $this->html('<h1>Service Error</h1><p>Could not load board data.</p>', 502);
        }

        $board = $boardData['board'] ?? null;
        if (!$board) {
            return $this->html('<h1>Board not found</h1>', 404);
        }

        $denied = $this->checkStaffOnlyAccess($request, $board);
        if ($denied) {
            return $denied;
        }
        $threads = $catalogData['threads'] ?? [];
        $threads = $this->rewriteMediaUrls($threads);

        $html = $this->renderer->render('catalog', array_merge($common, [
            'board_slug'  => $slug,
            'board_title' => $board['title'],
            'page_title'  => '/' . $slug . '/ - Catalog',
            'threads'     => $threads,
            'thread_id'   => '',
            'page_num'    => 1,
            'is_staff'    => $this->isStaff($request),
            'staff_level' => $this->getStaffLevel(),
            'extra_css'   => $this->getExtraCss($board),
        ]));

        return $this->html($html);
    }

    /** GET /{slug}/archive – Board archive */
    public function archive(RequestInterface $request, string $slug): ResponseInterface
    {
        $common = [];
        $boardData = [];
        $archiveData = [];
        $fetchErrors = [];
        $encodedSlug = urlencode($slug);
        $logger = $this->loggerFactory->get('frontend');
        $wg = new \Swoole\Coroutine\WaitGroup();
        $wg->add(3);
        go(function () use (&$common, &$fetchErrors, $wg, $logger) {
            try {
                $common = $this->getCommonData();
            } catch (\Throwable $e) {
                $fetchErrors['common'] = $e->getMessage();
                $logger->error('Coroutine failed fetching common data: ' . $e->getMessage());
            } finally {
                $wg->done();
            }
        });
        go(function () use (&$boardData, &$fetchErrors, $encodedSlug, $wg, $logger) {
            try {
                $boardData = $this->fetchJson('boards', '/api/v1/boards/' . $encodedSlug);
            } catch (\Throwable $e) {
                $fetchErrors['board'] = $e->getMessage();
                $logger->error('Coroutine failed fetching board data: ' . $e->getMessage());
            } finally {
                $wg->done();
            }
        });
        go(function () use (&$archiveData, &$fetchErrors, $encodedSlug, $wg, $logger) {
            try {
                $archiveData = $this->fetchJson('boards', '/api/v1/boards/' . $encodedSlug . '/archive');
            } catch (\Throwable $e) {
                $fetchErrors['archive'] = $e->getMessage();
                $logger->error('Coroutine failed fetching archive: ' . $e->getMessage());
            } finally {
                $wg->done();
            }
        });
        if (!$wg->wait(5)) {
            $logger->error('Upstream fetch timeout for archive /' . $slug . '/archive');
            return $this->html('<h1>Service Unavailable</h1><p>Upstream services did not respond in time. Please try again.</p>', 503);
        }

        if (isset($fetchErrors['board'])) {
            return $this->html('<h1>Service Error</h1><p>Could not load board data.</p>', 502);
        }

        $board = $boardData['board'] ?? null;
        if (!$board) {
            return $this->html('<h1>Board not found</h1>', 404);
        }

        $denied = $this->checkStaffOnlyAccess($request, $board);
        if ($denied) {
            return $denied;
        }
        $archived = $archiveData['archived_threads'] ?? $archiveData['threads'] ?? [];

        $html = $this->renderer->render('archive', array_merge($common, [
            'board_slug'       => $slug,
            'board_title'      => $board['title'],
            'page_title'       => '/' . $slug . '/ - Archive',
            'archived_threads' => $archived,
            'thread_id'        => '',
            'page_num'         => 1,
            'is_staff'         => $this->isStaff($request),
            'staff_level'      => $this->getStaffLevel(),
            'extra_css'        => $this->getExtraCss($board),
        ]));

        return $this->html($html);
    }

    /** GET /{slug}/thread/{id} – Thread view */
    public function thread(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        $common = [];
        $boardData = [];
        $threadData = [];
        $fetchErrors = [];
        $encodedSlug = urlencode($slug);
        $logger = $this->loggerFactory->get('frontend');
        $wg = new \Swoole\Coroutine\WaitGroup();
        $wg->add(3);
        go(function () use (&$common, &$fetchErrors, $wg, $logger) {
            try {
                $common = $this->getCommonData();
            } catch (\Throwable $e) {
                $fetchErrors['common'] = $e->getMessage();
                $logger->error('Coroutine failed fetching common data: ' . $e->getMessage());
            } finally {
                $wg->done();
            }
        });
        go(function () use (&$boardData, &$fetchErrors, $encodedSlug, $wg, $logger) {
            try {
                $boardData = $this->fetchJson('boards', '/api/v1/boards/' . $encodedSlug);
            } catch (\Throwable $e) {
                $fetchErrors['board'] = $e->getMessage();
                $logger->error('Coroutine failed fetching board data: ' . $e->getMessage());
            } finally {
                $wg->done();
            }
        });
        go(function () use (&$threadData, &$fetchErrors, $encodedSlug, $id, $wg, $logger) {
            try {
                $threadData = $this->fetchJson('boards', '/api/v1/boards/' . $encodedSlug . '/threads/' . $id);
            } catch (\Throwable $e) {
                $fetchErrors['thread'] = $e->getMessage();
                $logger->error('Coroutine failed fetching thread: ' . $e->getMessage());
            } finally {
                $wg->done();
            }
        });
        if (!$wg->wait(5)) {
            $logger->error('Upstream fetch timeout for thread /' . $slug . '/thread/' . $id);
            return $this->html('<h1>Service Unavailable</h1><p>Upstream services did not respond in time. Please try again.</p>', 503);
        }

        if (isset($fetchErrors['board'])) {
            return $this->html('<h1>Service Error</h1><p>Could not load board data.</p>', 502);
        }

        $board = $boardData['board'] ?? null;
        if (!$board) {
            return $this->html('<h1>Board not found</h1>', 404);
        }

        $denied = $this->checkStaffOnlyAccess($request, $board);
        if ($denied) {
            return $denied;
        }
        if (isset($threadData['error'])) {
            return $this->html('<h1>Thread not found</h1>', 404);
        }

        $op = $threadData['op'] ?? null;
        if (is_array($op)) {
            $op = $this->rewritePostMedia($op);
        }
        $replies = $threadData['replies'] ?? [];
        foreach ($replies as &$reply) {
            if (!is_array($reply)) {
                continue;
            }
            $reply = $this->rewritePostMedia($reply);
        }

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
            'user_ids'      => !empty($board['user_ids']),
            'country_flags' => !empty($board['country_flags']),
            'is_staff'      => $this->isStaff($request),
            'staff_level'   => $this->getStaffLevel(),
            'extra_css'     => $this->getExtraCss($board),
        ]));

        return $this->html($html);
    }

    private function isStaff(RequestInterface $request): bool
    {
        $staffInfo = \Hyperf\Context\Context::get('staff_info', []);
        if (!empty($staffInfo) && !empty($staffInfo['level'])) {
            return true;
        }
        // Fallback to AuthMiddleware role attribute
        $role = $request->getAttribute('role');
        return in_array($role, ['admin', 'manager', 'moderator', 'janitor'], true);
    }

    private function getStaffLevel(): string
    {
        /** @var array<string, mixed> $staffInfo */
        $staffInfo = \Hyperf\Context\Context::get('staff_info', []);
        return (string) ($staffInfo['level'] ?? '');
    }

    /**
     * Check if a board requires staff access and deny non-staff users.
     * Returns a redirect response if denied, or null if access is granted.
     *
     * @param array<string, mixed> $board
     */
    private function checkStaffOnlyAccess(RequestInterface $request, array $board): ?ResponseInterface
    {
        if (!empty($board['staff_only']) && !$this->isStaff($request)) {
            /** @var ResponseInterface */
            return $this->response->withStatus(303)->withHeader('Location', '/staff/login');
        }
        return null;
    }

    /**
     * Get extra CSS link tag for staff-only boards (janichan.css).
     *
     * @param array<string, mixed> $board
     */
    private function getExtraCss(array $board): string
    {
        if (!empty($board['staff_only'])) {
            return '<link rel="stylesheet" href="/static/css/janichan.css">';
        }
        return '';
    }

    /**
     * Get the capcode value for the current staff member on a staff-only board.
     *
     * @param array<string, mixed> $board
     */
    private function getStaffCapcode(array $board): ?string
    {
        if (empty($board['staff_only'])) {
            return null;
        }
        $level = $this->getStaffLevel();
        return match ($level) {
            'admin' => 'Admin',
            'manager' => 'Manager',
            'mod' => 'Mod',
            'janitor' => 'Janitor',
            default => null,
        };
    }

    /** POST /{slug}/threads – Create new thread (from form) */
    public function createThread(RequestInterface $request, string $slug): ResponseInterface
    {
        // Check staff-only board access
        $boardData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug));
        $board = $boardData['board'] ?? null;
        if ($board) {
            $denied = $this->checkStaffOnlyAccess($request, $board);
            if ($denied) {
                return $denied;
            }
        }

        $input = $request->all();

        // Auto-capcode on staff-only boards
        $capcode = $this->getStaffCapcode($board ?? []);
        if ($capcode) {
            $input['capcode'] = $capcode;
        }

        $mediaResult = $this->handleMediaUpload($request);

        if ($mediaResult && isset($mediaResult['error'])) {
            return $this->html('<h1>Upload Error</h1><p>' . htmlspecialchars((string) $mediaResult['error']) . '</p>', 400);
        }

        if ($mediaResult) {
            $input = array_merge($input, $mediaResult);
        }

        $headers = [
            'Content-Type'    => 'application/json',
            'X-Forwarded-For' => $request->getHeaderLine('X-Forwarded-For') ?: $request->server('remote_addr', ''),
            'User-Agent'      => $request->getHeaderLine('User-Agent'),
        ];

        $result = $this->proxyClient->forward('boards', 'POST', "/api/v1/boards/{$slug}/threads", $headers, (string) (json_encode($input) ?: '{}'));

        if ($result['status'] >= 400) {
            return $this->html('<h1>Post Error</h1><p>' . htmlspecialchars((string) $result['body']) . '</p>', $result['status']);
        }

        $data = json_decode((string) $result['body'], true);
        $threadId = $data['thread_id'] ?? null;

        if ($threadId) {
            /** @var ResponseInterface */
            return $this->response->withStatus(303)->withHeader('Location', "/{$slug}/thread/{$threadId}");
        }

        /** @var ResponseInterface */
        return $this->response->withStatus(303)->withHeader('Location', "/{$slug}/");
    }

    /** POST /{slug}/thread/{id}/posts – Create new reply (from form) */
    public function createPost(RequestInterface $request, string $slug, int $id): ResponseInterface
    {
        // Check staff-only board access
        $boardData = $this->fetchJson('boards', '/api/v1/boards/' . urlencode($slug));
        $board = $boardData['board'] ?? null;
        if ($board) {
            $denied = $this->checkStaffOnlyAccess($request, $board);
            if ($denied) {
                return $denied;
            }
        }

        $input = $request->all();

        // Auto-capcode on staff-only boards
        $capcode = $this->getStaffCapcode($board ?? []);
        if ($capcode) {
            $input['capcode'] = $capcode;
        }

        $mediaResult = $this->handleMediaUpload($request);

        if ($mediaResult && isset($mediaResult['error'])) {
            return $this->html('<h1>Upload Error</h1><p>' . htmlspecialchars((string) $mediaResult['error']) . '</p>', 400);
        }

        if ($mediaResult) {
            $input = array_merge($input, $mediaResult);
        }

        $headers = [
            'Content-Type'    => 'application/json',
            'X-Forwarded-For' => $request->getHeaderLine('X-Forwarded-For') ?: $request->server('remote_addr', ''),
            'User-Agent'      => $request->getHeaderLine('User-Agent'),
        ];

        $result = $this->proxyClient->forward('boards', 'POST', "/api/v1/boards/{$slug}/threads/{$id}/posts", $headers, (string) (json_encode($input) ?: '{}'));

        if ($result['status'] >= 400) {
            return $this->html('<h1>Post Error</h1><p>' . htmlspecialchars((string) $result['body']) . '</p>', $result['status']);
        }

        $data = json_decode((string) $result['body'], true);
        $postId = $data['post_id'] ?? null;

        if ($postId) {
            /** @var ResponseInterface */
            return $this->response->withStatus(303)->withHeader('Location', "/{$slug}/thread/{$id}#p{$postId}");
        }

        /** @var ResponseInterface */
        return $this->response->withStatus(303)->withHeader('Location', "/{$slug}/thread/{$id}");
    }

    /** POST /{slug}/delete – Delete posts (classic imageboard form) */
    public function deletePosts(RequestInterface $request, string $slug): ResponseInterface
    {
        $all = $request->all();
        $referer = $request->getHeaderLine('Referer');

        // Extract post IDs from form: checkboxes named by post ID with value "delete"
        $ids = [];
        foreach ($all as $key => $value) {
            if (is_numeric($key) && $value === 'delete') {
                $ids[] = (int) $key;
            }
        }

        if (empty($ids)) {
            return $this->html('<h1>Error</h1><p>No posts selected for deletion.</p>', 400);
        }

        $password = is_string($all['pwd'] ?? null) ? $all['pwd'] : '';
        $imageOnly = isset($all['onlyimgdel']) && $all['onlyimgdel'] === 'on';

        // Check if current user is staff — staff can delete without password
        $staffInfo = \Hyperf\Context\Context::get('staff_info', []);
        $isStaff = !empty($staffInfo) && !empty($staffInfo['level']);

        if ($isStaff) {
            // Staff delete — call staff endpoint for each post
            $deleted = 0;
            foreach ($ids as $postId) {
                $fileOnlyParam = $imageOnly ? '?file_only=1' : '';
                $result = $this->proxyClient->forward(
                    'boards',
                    'DELETE',
                    "/api/v1/boards/{$slug}/posts/{$postId}{$fileOnlyParam}"
                );
                if ($result['status'] < 400) {
                    $deleted++;
                }
            }
        } else {
            // User delete — needs password
            if (empty($password)) {
                return $this->html('<h1>Error</h1><p>Password is required to delete posts.</p>', 400);
            }

            $payload = json_encode([
                'ids' => $ids,
                'password' => $password,
                'image_only' => $imageOnly,
            ]) ?: '{}';

            $result = $this->proxyClient->forward('boards', 'POST', '/api/v1/posts/delete', [
                'Content-Type' => 'application/json',
            ], $payload);
        }

        // Redirect back
        if ($referer && str_contains($referer, "/{$slug}/")) {
            /** @var ResponseInterface */
            return $this->response->withStatus(303)->withHeader('Location', $referer);
        }
        /** @var ResponseInterface */
        return $this->response->withStatus(303)->withHeader('Location', "/{$slug}/");
    }

    /**
     * Handle media upload if present in the request.
     * @return array<string, mixed>|null Media metadata or ['error' => '...']
     */
    private function handleMediaUpload(RequestInterface $request): ?array
    {
        $file = $request->file('upfile');
        if (!$file) {
            return null;
        }
        
        if (is_array($file)) {
            $file = $file[0];
        }

        if (!$file->isValid()) {
            // UPLOAD_ERR_NO_FILE = 4
            if ($file->getError() === 4) {
                return null;
            }
            return ['error' => 'File upload failed (code ' . $file->getError() . ')'];
        }

        $tmpPath = $file->getRealPath();
        if (!$tmpPath) {
            return ['error' => 'Could not process uploaded file'];
        }

        $body = [
            'upfile' => new \CURLFile(
                $tmpPath,
                (string) $file->getClientMediaType(),
                (string) $file->getClientFilename()
            ),
        ];

        $result = $this->proxyClient->forward('media', 'POST', '/api/v1/media/upload', [], $body);

        $data = json_decode((string) $result['body'], true);
        if ($result['status'] === 200 && is_array($data) && !isset($data['error'])) {
            /** @var array<string, mixed> $data */
            return $data;
        }

        $errorMsg = $data['error'] ?? 'Media service error (' . $result['status'] . ')';
        return ['error' => $errorMsg];
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
     *  board?: array<string, mixed>,
     *  threads?: array<mixed>,
     *  total_pages?: int,
     *  op?: array<mixed>,
     *  replies?: array<mixed>,
     *  image_count?: int,
     *  locked?: bool,
     *  sticky?: bool,
     *  archived_threads?: array<mixed>,
     *  blotter?: array<mixed>,
     *  error?: string,
     *  status?: int
     * }
     */
    private function fetchJson(string $service, string $path): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        // Forward staff level so backends can include staff-only data (e.g. IP hashes)
        $staffInfo = \Hyperf\Context\Context::get('staff_info', []);
        if (!empty($staffInfo['level'])) {
            $headers['X-Staff-Level'] = (string) $staffInfo['level'];
        }

        $result = $this->proxyClient->forward($service, 'GET', $path, $headers);

        if ($result['status'] >= 400) {
            // Log the error but don't return an error array that breaks destructuring
            // For example, if boards backend returns 404 for a specific board,
            // we want to display "Board not found" without crashing.
            // Other errors (e.g. 5xx) will result in empty data and generic error messages.
            // If the service is completely down, it'll still return empty data.
            $this->loggerFactory->get('default')->error(sprintf(
                'Upstream service "%s" returned error status %d for path "%s". Body: %s',
                $service,
                $result['status'],
                $path,
                (string) ($result['body'] ?: 'N/A')
            ));
            return [];
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
         *  board?: array<string, mixed>,
         *  threads?: array<mixed>,
         *  total_pages?: int,
         *  op?: array<mixed>,
         *  replies?: array<mixed>,
         *  image_count?: int,
         *  locked?: bool,
         *  sticky?: bool,
         *  archived_threads?: array<mixed>,
         *  blotter?: array<mixed>,
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
     * Cached in Redis to avoid hitting the boards backend on every page render.
     * @return array<string, mixed>
     */
    private function getCommonData(): array
    {
        $cacheKey = 'frontend:common_data';
        try {
            $cached = $this->redis->get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    return $decoded;
                }
            }
        } catch (\Throwable) {
            // Redis down — fall through to fresh fetch
        }

        // Fetch boards and blotter in parallel using Swoole coroutines
        $boardsData = [];
        $blotterData = [];
        $logger = $this->loggerFactory->get('frontend');
        $wg = new \Swoole\Coroutine\WaitGroup();
        $wg->add(2);
        go(function () use (&$boardsData, $wg, $logger) {
            try {
                $boardsData = $this->fetchJson('boards', '/api/v1/boards');
            } catch (\Throwable $e) {
                $logger->error('Coroutine failed fetching boards list: ' . $e->getMessage());
            } finally {
                $wg->done();
            }
        });
        go(function () use (&$blotterData, $wg, $logger) {
            try {
                $blotterData = $this->fetchJson('boards', '/api/v1/blotter');
            } catch (\Throwable $e) {
                $logger->error('Coroutine failed fetching blotter: ' . $e->getMessage());
            } finally {
                $wg->done();
            }
        });
        if (!$wg->wait(5)) {
            $logger->warning('Timeout fetching common data (boards/blotter); serving with partial data');
        }

        $boards = $boardsData['boards'] ?? [];
        
        // Group boards for the navbar
        $grouped = [];
        foreach ($boards as $b) {
            $cat = $b['category'];
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

        $result = [
            'boards'     => $boards,
            'nav_groups' => $nav_groups,
            'blotter'    => $blotterData['blotter'] ?? [],
        ];

        // Cache for 60 seconds
        try {
            $this->redis->setex($cacheKey, self::COMMON_DATA_CACHE_TTL, (string) json_encode($result));
        } catch (\Throwable) {
            // Non-critical
        }

        return $result;
    }

    /**
     * Rewrite internal MinIO URLs to external gateway URLs.
     * @param array<mixed> $threads
     * @return array<mixed>
     */
    private function rewriteMediaUrls(array $threads): array
    {
        foreach ($threads as &$thread) {
            if (!is_array($thread)) {
                continue;
            }
            if (isset($thread['op']) && is_array($thread['op'])) {
                $thread['op'] = $this->rewritePostMedia($thread['op']);
            }
            if (isset($thread['latest_replies']) && is_array($thread['latest_replies'])) {
                foreach ($thread['latest_replies'] as &$reply) {
                    if (!is_array($reply)) {
                        continue;
                    }
                    $reply = $this->rewritePostMedia($reply);
                }
            }
        }
        return $threads;
    }

    /**
     * Rewrite media URLs in a single post.
     * @param array<mixed> $post
     * @return array<mixed>
     */
    private function rewritePostMedia(array $post): array
    {
        $bucket = (string) \Hyperf\Support\env('OBJECT_STORAGE_BUCKET', 'ashchan');
        // Match any MinIO hostname pattern and rewrite to /media/ proxy path
        $patterns = [
            "http://minio:9000/{$bucket}/",
            "http://localhost:9000/{$bucket}/",
            "http://127.0.0.1:9000/{$bucket}/",
        ];
        $replace = "/media/";

        if (!empty($post['media_url'])) {
            $post['media_url'] = str_replace($patterns, $replace, (string) $post['media_url']);
        }
        if (!empty($post['thumb_url'])) {
            $post['thumb_url'] = str_replace($patterns, $replace, (string) $post['thumb_url']);
        }
        return $post;
    }

    /** GET /staff/css/{path} - Serve staff CSS files */
    public function staffCss(RequestInterface $request, string $path): ResponseInterface
    {
        if (empty($path)) {
            return $this->response->json(['error' => 'File not found'], 404);
        }
        $filePath = __DIR__ . '/../../public/staff/css/' . basename($path);
        
        if (!file_exists($filePath)) {
            return $this->response->json(['error' => 'File not found'], 404);
        }
        
        $content = file_get_contents($filePath);
        return $this->response->raw($content)->withHeader('Content-Type', 'text/css');
    }

    /** GET /staff/js/{path} - Serve staff JS files */
    public function staffJs(RequestInterface $request, string $path): ResponseInterface
    {
        if (empty($path)) {
            return $this->response->json(['error' => 'File not found'], 404);
        }
        $filePath = __DIR__ . '/../../public/staff/js/' . basename($path);
        
        if (!file_exists($filePath)) {
            return $this->response->json(['error' => 'File not found'], 404);
        }
        
        $content = file_get_contents($filePath);
        return $this->response->raw($content)->withHeader('Content-Type', 'application/javascript');
    }

    /** GET /staff/favicon.ico - Serve staff favicon */
    public function staffFavicon(): ResponseInterface
    {
        $filePath = __DIR__ . '/../../public/staff/favicon.ico';
        
        if (!file_exists($filePath)) {
            // Return empty ico
            return $this->response->raw('')->withHeader('Content-Type', 'image/x-icon');
        }
        
        $content = file_get_contents($filePath);
        return $this->response->raw($content)->withHeader('Content-Type', 'image/x-icon');
    }
}
