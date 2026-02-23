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


namespace App\Service;

use App\Model\Board;
use App\Model\OpenPostBody;
use App\Model\Post;
use App\Model\Thread;
use Ashchan\EventBus\CloudEvent;
use Ashchan\EventBus\EventPublisher;
use Ashchan\EventBus\EventTypes;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use function Hyperf\Support\env;
use function Hyperf\Collection\collect;

final class BoardService
{
    private int $blotterLimit;
    private int $archiveLimit;
    private int $ipPostSearchLimit;
    private int $ipPostScanLimit;
    private int $defaultMaxThreads;
    private int $defaultBumpLimit;
    private int $defaultImageLimit;
    private int $defaultCooldownSeconds;
    private string $ipHashSalt;

    public function __construct(
        private ContentFormatter $formatter,
        private Redis $redis,
        private PiiEncryptionServiceInterface $piiEncryption,
        private EventPublisher $eventPublisher,
        SiteConfigService $config,
    ) {
        $this->blotterLimit          = $config->getInt('blotter_display_limit', 5);
        $this->archiveLimit          = $config->getInt('archive_thread_limit', 3000);
        $this->ipPostSearchLimit     = $config->getInt('ip_post_search_limit', 100);
        $this->ipPostScanLimit       = $config->getInt('ip_post_scan_limit', 5000);
        $this->defaultMaxThreads     = $config->getInt('default_max_threads', 200);
        $this->defaultBumpLimit      = $config->getInt('default_bump_limit', 300);
        $this->defaultImageLimit     = $config->getInt('default_image_limit', 150);
        $this->defaultCooldownSeconds = $config->getInt('default_cooldown_seconds', 60);

        $salt = $config->get('ip_hash_salt', '');
        if ($salt === '') {
            $salt = (string) env('IP_HASH_SALT', '');
        }
        $this->ipHashSalt = $salt;
    }

    /* ──────────────────────────────────────────────
     * Boards
     * ────────────────────────────────────────────── */

    /**
     * List all boards, cached.
     * @return array<int, array<string, mixed>>
     */
    public function listBoards(): array
    {
        $key = 'boards:all';
        try {
            // Disable cache in development mode
            if (env('APP_ENV', 'production') !== 'local') {
                $cached = $this->redis->get($key);
                if (is_string($cached)) {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded)) {
                        /** @var array<int, array<string, mixed>> $decoded */
                        return $decoded;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Redis unavailable, fall through to DB
        }
        $boards = Board::query()
            ->where('archived', false)
            ->where('staff_only', false)
            ->orderBy('category')
            ->orderBy('slug')
            ->get()
            ->toArray();
        try {
            if (env('APP_ENV', 'production') !== 'local') {
                $this->redis->setex($key, 300, json_encode($boards) ?: '');
            }
        } catch (\Throwable $e) {
            // Redis unavailable, skip caching
        }
        /** @var array<int, array<string, mixed>> $boards */
        return $boards;
    }

    public function getBoard(string $slug): ?Board
    {
        try {
            $key = "board:{$slug}";
            if (env('APP_ENV', 'production') !== 'local') {
                $cached = $this->redis->get($key);
                if ($cached === 'NOT_FOUND') {
                    return null;
                }
                if (is_string($cached) && $cached !== '') {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded)) {
                        // Hydrate Board model from cache to avoid DB query
                        $board = new Board();
                        $board->forceFill($decoded);
                        $board->exists = true;
                        return $board;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Redis unavailable, fall through to DB
        }
        
        $board = Board::query()->where('slug', $slug)->first();
        
        try {
            if (env('APP_ENV', 'production') !== 'local') {
                if ($board) {
                    $this->redis->setex("board:{$slug}", 300, json_encode($board->toArray()));
                } else {
                    $this->redis->setex("board:{$slug}", 60, 'NOT_FOUND');
                }
            }
        } catch (\Throwable $e) {
            // Redis unavailable
        }
        
        return $board;
    }

    /**
     * Get recent blotter entries.
     * @return array<int, array<string, mixed>>
     */
    public function getBlotter(): array
    {
        $key = 'blotter:recent';
        try {
            if (env('APP_ENV', 'production') !== 'local') {
                $cached = $this->redis->get($key);
                if (is_string($cached)) {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded)) {
                        /** @var array<int, array<string, mixed>> $decoded */
                        return $decoded;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Redis unavailable
        }

        /** @var \Hyperf\Database\Model\Collection<int, \App\Model\Blotter> $rows */
        $rows = \App\Model\Blotter::query()
            ->orderByDesc('id')
            ->limit($this->blotterLimit)
            ->get();

        /** @var array<int, array<string, mixed>> $result */
        $result = $rows->map(fn(\App\Model\Blotter $b): array => [
            'id'           => $b->id,
            'content'      => $b->content,
            'is_important' => $b->is_important,
            'created_at'   => $this->toTimestamp($b->created_at),
        ])->toArray();

        try {
            if (env('APP_ENV', 'production') !== 'local') {
                $this->redis->setex($key, 120, json_encode($result) ?: '');
            }
        } catch (\Throwable $e) {
            // Redis unavailable
        }

        return $result;
    }

    /* ──────────────────────────────────────────────
     * Board Management (Admin CRUD)
     * ────────────────────────────────────────────── */

    /**
     * List all boards (including archived) for admin.
     * @return array<int, array<string, mixed>>
     */
    public function listAllBoards(): array
    {
        /** @var array<int, array<string, mixed>> $boards */
        $boards = Board::query()
            ->orderBy('category')
            ->orderBy('slug')
            ->get()
            ->toArray();
        return $boards;
    }

    /**
     * Create a new board.
     * @param array<string, mixed> $data
     */
    public function createBoard(array $data): Board
    {
        // The DB requires 'name' (NOT NULL), use title as name
        $title = (string) ($data['title'] ?? '');
        
        $board = new Board();
        $board->slug = (string) $data['slug'];
        $board->title = $title;
        $board->setAttribute('name', $title ?: (string) $data['slug']);
        $board->subtitle = (string) ($data['subtitle'] ?? '');
        $board->category = (string) ($data['category'] ?? '');
        $board->nsfw = (bool) ($data['nsfw'] ?? false);
        $board->max_threads = (int) ($data['max_threads'] ?? $this->defaultMaxThreads);
        $board->bump_limit = (int) ($data['bump_limit'] ?? $this->defaultBumpLimit);
        $board->image_limit = (int) ($data['image_limit'] ?? $this->defaultImageLimit);
        $board->cooldown_seconds = (int) ($data['cooldown_seconds'] ?? $this->defaultCooldownSeconds);
        $board->text_only = (bool) ($data['text_only'] ?? false);
        $board->require_subject = (bool) ($data['require_subject'] ?? false);
        $board->staff_only = (bool) ($data['staff_only'] ?? false);
        $board->user_ids = (bool) ($data['user_ids'] ?? false);
        $board->country_flags = (bool) ($data['country_flags'] ?? false);
        $board->rules = (string) ($data['rules'] ?? '');
        $board->save();

        $this->invalidateBoardCaches();
        return $board;
    }

    /**
     * Update an existing board.
     * @param array<string, mixed> $data
     */
    public function updateBoard(Board $board, array $data): Board
    {
        $fillable = [
            'title', 'subtitle', 'category', 'rules',
        ];
        foreach ($fillable as $field) {
            if (array_key_exists($field, $data)) {
                $board->{$field} = (string) $data[$field];
            }
        }

        // Keep name in sync with title
        if (array_key_exists('title', $data)) {
            $board->setAttribute('name', ((string) $data['title']) ?: $board->slug);
        }

        $booleans = ['nsfw', 'text_only', 'require_subject', 'archived', 'staff_only', 'user_ids', 'country_flags'];
        foreach ($booleans as $field) {
            if (array_key_exists($field, $data)) {
                $board->{$field} = (bool) $data[$field];
            }
        }

        $integers = ['max_threads', 'bump_limit', 'image_limit', 'cooldown_seconds'];
        foreach ($integers as $field) {
            if (array_key_exists($field, $data)) {
                $board->{$field} = (int) $data[$field];
            }
        }

        $board->save();
        $this->invalidateBoardCaches();
        return $board;
    }

    /**
     * Delete a board (and all its threads/posts via CASCADE).
     */
    public function deleteBoard(Board $board): void
    {
        $board->delete();
        $this->invalidateBoardCaches();
    }

    /**
     * Invalidate board-related caches.
     */
    private function invalidateBoardCaches(): void
    {
        try {
            $this->redis->del('boards:all');
            // Use SCAN instead of KEYS to avoid blocking Redis
            $cursor = null;
            $keysToDelete = [];
            do {
                /** @var array{0: int|string, 1: array<int, string>}|false $result */
                $result = $this->redis->scan($cursor, 'board:*', 100);
                if ($result !== false && count($result[1]) > 0) {
                    $keysToDelete = array_merge($keysToDelete, $result[1]);
                }
            } while ($cursor > 0);
            if (count($keysToDelete) > 0) {
                $this->redis->del(...$keysToDelete);
            }
        } catch (\Throwable $e) {
            // Redis unavailable
        }
    }

    /* ──────────────────────────────────────────────
     * Threads – Index
     * ────────────────────────────────────────────── */

    /**
     * Board index: threads sorted by bump order, paginated.
     * Returns threads with OP + latest N replies.
     * @return array{threads: array<int, array<string, mixed>>, page: int, total_pages: int, total: int}
     */
    public function getThreadIndex(Board $board, int $page = 1, int $perPage = 15, bool $includeIpHash = false): array
    {
        $threads = Thread::query()
            ->where('board_id', $board->id)
            ->where('archived', false)
            ->orderByDesc('sticky')
            ->orderByDesc('bumped_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $total = Thread::query()
            ->where('board_id', $board->id)
            ->where('archived', false)
            ->count();

        if ($threads->isEmpty()) {
            return [
                'threads'     => [],
                'page'        => $page,
                'total_pages' => (int) ceil($total / $perPage),
                'total'       => $total,
            ];
        }

        // Batch-load OPs for all threads (avoids N+1)
        $threadIds = $threads->pluck('id')->toArray();
        /** @var \Hyperf\Database\Model\Collection<int, Post> $allOps */
        $allOps = Post::query()
            ->whereIn('thread_id', $threadIds)
            ->where('is_op', true)
            ->where('deleted', false)
            ->get()
            ->keyBy('thread_id');

        // Batch-load only the latest 5 replies per thread using a window function
        // This avoids loading ALL replies and then filtering in PHP
        $replyRows = Db::select(
            "SELECT p.* FROM (
                SELECT p2.*, ROW_NUMBER() OVER (PARTITION BY p2.thread_id ORDER BY p2.id DESC) AS rn
                FROM posts p2
                WHERE p2.thread_id = ANY(?)
                AND p2.is_op = false
                AND p2.deleted = false
            ) p WHERE p.rn <= 5",
            ['{' . implode(',', $threadIds) . '}']
        );

        // Hydrate reply rows into Post models grouped by thread
        $allReplies = collect();
        foreach ($replyRows as $row) {
            $post = new Post();
            $post->forceFill((array) $row);
            $post->exists = true;
            $threadId = $post->thread_id;
            if (!$allReplies->has($threadId)) {
                $allReplies->put($threadId, collect());
            }
            $allReplies->get($threadId)->push($post);
        }

        $result = [];
        foreach ($threads as $thread) {
            /** @var Thread $thread */
            /** @var Post|null $op */
            $op = $allOps->get($thread->id);

            /** @var \Hyperf\Database\Model\Collection<int, Post> $threadReplies */
            $threadReplies = $allReplies->get($thread->id, collect());
            $latestReplies = $threadReplies->sortBy('id')->values();

            $shownReplies = $latestReplies->count();
            $totalReplies = $thread->reply_count;
            $omittedPosts = max(0, $totalReplies - $shownReplies);
            $omittedImages = 0;
            if ($omittedPosts > 0) {
                $totalImages = $thread->image_count;
                $shownImages = $latestReplies->filter(fn(Post $r) => (bool) $r->media_url)->count();
                $omittedImages = max(0, $totalImages - $shownImages);
            }

            $result[] = [
                'id'              => $thread->id,
                'sticky'          => $thread->sticky,
                'locked'          => $thread->locked,
                'reply_count'     => $thread->reply_count,
                'image_count'     => $thread->image_count,
                'bumped_at'       => $thread->bumped_at,
                'created_at'      => $thread->created_at,
                'op'              => $this->formatPostOutput($op, [], $includeIpHash),
                'latest_replies'  => $latestReplies->map(function (Post $r) use ($includeIpHash) {
                    return $this->formatPostOutput($r, [], $includeIpHash);
                })->toArray(),
                'omitted_posts'   => $omittedPosts,
                'omitted_images'  => $omittedImages,
            ];
        }

        return [
            'threads'     => $result,
            'page'        => $page,
            'total_pages' => (int) ceil($total / $perPage),
            'total'       => $total,
        ];
    }

    /* ──────────────────────────────────────────────
     * Threads – Full View
     * ────────────────────────────────────────────── */

    /**
     * @return array<string, mixed>|null
     */
    public function getThread(int $threadId, bool $includeIpHash = false): ?array
    {
        // Try cache first (only for non-staff requests without IP hashes)
        if (!$includeIpHash) {
            try {
                $cacheKey = "thread:{$threadId}";
                if (env('APP_ENV', 'production') !== 'local') {
                    $cached = $this->redis->get($cacheKey);
                    if (is_string($cached) && $cached !== '') {
                        $decoded = json_decode($cached, true);
                        if (is_array($decoded)) {
                            /** @var array<string, mixed> $decoded */
                            return $decoded;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Redis unavailable
            }
        }

        /** @var Thread|null $thread */
        $thread = Thread::find($threadId);
        if (!$thread) return null;

        /** @var \Hyperf\Database\Model\Collection<int, Post> $posts */
        $posts = Post::query()
            ->where('thread_id', $threadId)
            ->where('deleted', false)
            ->orderBy('created_at')
            ->get();

        /** @var Post|null $op */
        $op = $posts->first(fn(Post $p) => $p->is_op);
        $replies = $posts->filter(fn(Post $p) => !$p->is_op)->values();

        // Build backlinks map
        $backlinks = [];
        foreach ($posts as $post) {
            /** @var Post $post */
            $quoted = $this->formatter->extractQuotedIds($post->content ?? '');
            foreach ($quoted as $qid) {
                $backlinks[$qid][] = $post->id;
            }
        }

        $result = [
            'thread_id'     => $thread->id,
            'board_id'      => $thread->board_id,
            'sticky'        => $thread->sticky,
            'locked'        => $thread->locked,
            'archived'      => $thread->archived,
            'reply_count'   => $thread->reply_count,
            'image_count'   => $thread->image_count,
            'op'            => $this->formatPostOutput($op, ($op ? ($backlinks[(string)$op->id] ?? []) : []), $includeIpHash),
            'replies'       => $replies->map(function (Post $r) use ($backlinks, $includeIpHash) {
                return $this->formatPostOutput($r, $backlinks[(string)$r->id] ?? [], $includeIpHash);
            })->toArray(),
        ];

        // Cache the result for non-staff requests
        if (!$includeIpHash) {
            try {
                if (env('APP_ENV', 'production') !== 'local') {
                    $this->redis->setex("thread:{$threadId}", 120, json_encode($result) ?: '');
                }
            } catch (\Throwable $e) {
                // Redis unavailable
            }
        }

        return $result;
    }

    /* ──────────────────────────────────────────────
     * Threads – Catalog
     * ────────────────────────────────────────────── */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCatalog(Board $board): array
    {
        // Try cache first
        $cacheKey = "catalog:{$board->slug}";
        try {
            if (env('APP_ENV', 'production') !== 'local') {
                $cached = $this->redis->get($cacheKey);
                if (is_string($cached) && $cached !== '') {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded)) {
                        /** @var array<int, array<string, mixed>> $decoded */
                        return $decoded;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Redis unavailable
        }

        $threads = Thread::query()
            ->where('board_id', $board->id)
            ->where('archived', false)
            ->orderByDesc('sticky')
            ->orderByDesc('bumped_at')
            ->limit($board->max_threads ?: 200)
            ->get();

        if ($threads->isEmpty()) {
            return [];
        }

        // Batch-load all OPs (avoids N+1)
        $threadIds = $threads->pluck('id')->toArray();
        /** @var \Hyperf\Database\Model\Collection<int, Post> $allOps */
        $allOps = Post::query()
            ->whereIn('thread_id', $threadIds)
            ->where('is_op', true)
            ->where('deleted', false)
            ->get()
            ->keyBy('thread_id');

        $result = [];
        foreach ($threads as $thread) {
            /** @var Thread $thread */
            /** @var Post|null $op */
            $op = $allOps->get($thread->id);

            $result[] = [
                'id'          => $thread->id,
                'sticky'      => $thread->sticky,
                'locked'      => $thread->locked,
                'reply_count' => $thread->reply_count,
                'image_count' => $thread->image_count,
                'bumped_at'   => $this->toTimestamp($thread->bumped_at),
                'created_at'  => $this->toTimestamp($thread->created_at),
                'op'          => $op ? [
                    'subject'         => $op->subject,
                    'content_preview' => $op->content_preview,
                    'thumb_url'       => $op->thumb_url,
                ] : null,
            ];
        }

        // Cache catalog for 60 seconds
        try {
            if (env('APP_ENV', 'production') !== 'local') {
                $this->redis->setex($cacheKey, 60, json_encode($result) ?: '');
            }
        } catch (\Throwable $e) {
            // Redis unavailable
        }

        return $result;
    }

    /* ──────────────────────────────────────────────
     * Threads – Archive
     * ────────────────────────────────────────────── */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getArchive(Board $board): array
    {
        $threads = Thread::query()
            ->where('board_id', $board->id)
            ->where('archived', true)
            ->orderByDesc('updated_at')
            ->limit($this->archiveLimit)
            ->get();

        if ($threads->isEmpty()) {
            return [];
        }

        // Batch-load all OPs (avoids N+1)
        $threadIds = $threads->pluck('id')->toArray();
        /** @var \Hyperf\Database\Model\Collection<int, Post> $allOps */
        $allOps = Post::query()
            ->whereIn('thread_id', $threadIds)
            ->where('is_op', true)
            ->select(['id', 'thread_id', 'subject', 'content_html', 'content'])
            ->get()
            ->keyBy('thread_id');

        $result = [];
        foreach ($threads as $thread) {
            /** @var Thread $thread */
            /** @var Post|null $op */
            $op = $allOps->get($thread->id);

            $excerpt = $op ? ($op->subject ?: $op->content_preview) : '';
            $result[] = [
                'id'           => $thread->id,
                'excerpt'      => $excerpt,
                'excerpt_lower' => mb_strtolower($excerpt),
            ];
        }

        return $result;
    }

    /* ──────────────────────────────────────────────
     * Create Thread
     * ────────────────────────────────────────────── */

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public function createThread(Board $board, array $data): array
    {
        if (empty($board->id)) {
            throw new \RuntimeException("Board ID is missing or invalid: " . var_export($board->id, true));
        }

        // Get next Post ID to use as Thread ID + Post ID
        try {
            $nextIdResult = Db::select("SELECT nextval('posts_id_seq') as id");
            /** @var object{id: string|int} $row */
            $row = $nextIdResult[0];
            $nextId = (int) $row->id;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to generate ID: " . $e->getMessage());
        }

        /** @var array<string, int> $result */
        $result = Db::transaction(function () use ($board, $data, $nextId) {
            // Atomically allocate per-board post number
            $boardPostNo = $this->allocateBoardPostNo($board);

            /** @var Thread $thread */
            $thread = Thread::create([
                'id'       => $nextId,
                'board_id' => $board->id,
                'sticky'   => false,
                'locked'   => false,
                'archived' => false,
            ]);

            $thread->update([
                'bumped_at'   => \Carbon\Carbon::now(),
                'reply_count' => 0,
                'image_count' => isset($data['media_url']) ? 1 : 0,
            ]);

            $rawName = $data['name'] ?? '';
            [$name, $trip] = $this->formatter->parseNameTrip(is_string($rawName) ? $rawName : '');
            $rawContent = $data['content'] ?? '';
            $contentHtml = $this->formatter->format(is_string($rawContent) ? $rawContent : '');

            // Generate poster ID and country if board has those features enabled
            $posterId = $board->user_ids ? $this->generatePosterId(
                (string) ($data['ip_address'] ?? ''),
                $nextId
            ) : null;
            [$countryCode, $countryName] = $board->country_flags
                ? $this->resolveCountry((string) ($data['ip_address'] ?? ''))
                : [null, null];

            /** @var Post $post */
            $post = Post::create([
                'id'                   => $nextId,
                'thread_id'            => $thread->id,
                'is_op'                => true,
                'author_name'          => $name,
                'tripcode'             => $trip,
                'email'                => $data['email'] ?? null,
                'subject'              => $data['subject'] ?? null,
                'content'              => $data['content'] ?? '',
                'content_html'         => $contentHtml,
                'ip_address'           => $this->piiEncryption->encrypt((string) ($data['ip_address'] ?? '')),
                'country_code'         => $countryCode,
                'country_name'         => $countryName,
                'poster_id'            => $posterId,
                'board_post_no'        => $boardPostNo,
                'media_url'            => $data['media_url'] ?? null,
                'thumb_url'            => $data['thumb_url'] ?? null,
                'media_filename'       => $data['media_filename'] ?? null,
                'media_size'           => $data['media_size'] ?? null,
                'media_dimensions'     => $data['media_dimensions'] ?? null,
                'media_hash'           => $data['media_hash'] ?? null,
                'spoiler_image'        => $data['spoiler'] ?? false,
                'capcode'              => $data['capcode'] ?? null,
                'delete_password_hash' => (isset($data['password']) && is_string($data['password']) && $data['password'] !== '') ? password_hash($data['password'], PASSWORD_BCRYPT) : null,
            ]);

            // Prune old threads if over limit
            $this->pruneThreads($board);

            // Invalidate caches
            $this->redis->del("board:{$board->slug}:index");
            $this->redis->del("catalog:{$board->slug}");

            return [
                'thread_id' => $thread->id,
                'post_id'   => $post->id,
            ];
        });

        // Emit domain events after successful transaction (fire-and-forget)
        $this->eventPublisher->publish(CloudEvent::create(
            EventTypes::THREAD_CREATED,
            [
                'board_id' => $board->slug,
                'thread_id' => (string) $result['thread_id'],
                'op_post_id' => (string) $result['post_id'],
                'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            ],
        ));
        $this->eventPublisher->publish(CloudEvent::create(
            EventTypes::POST_CREATED,
            [
                'board_id' => $board->slug,
                'thread_id' => (string) $result['thread_id'],
                'post_id' => (string) $result['post_id'],
                'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
                'content' => mb_substr((string) ($data['content'] ?? ''), 0, 10000),
                'media_refs' => array_filter([(string) ($data['media_url'] ?? '')]),
            ],
        ));

        return $result;
    }

    /* ──────────────────────────────────────────────
     * Create Post (Reply)
     * ────────────────────────────────────────────── */

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public function createPost(Thread $thread, array $data): array
    {
        if ($thread->locked) {
            throw new \RuntimeException('Thread is locked');
        }

        // Resolve board for per-board features
        $board = $thread->board;

        /** @var array<string, int> $result */
        $result = Db::transaction(function () use ($thread, $data, $board) {
            // Atomically allocate per-board post number
            $boardPostNo = $board ? $this->allocateBoardPostNo($board) : null;

            $rawName = $data['name'] ?? '';
            [$name, $trip] = $this->formatter->parseNameTrip(is_string($rawName) ? $rawName : '');
            $rawContent = $data['content'] ?? '';
            $contentHtml = $this->formatter->format(is_string($rawContent) ? $rawContent : '');
            $rawEmail = $data['email'] ?? '';
            $email = is_string($rawEmail) ? $rawEmail : '';
            $isSage = strtolower(trim($email)) === 'sage';

            // Generate poster ID and country if board has those features enabled
            $posterId = ($board && $board->user_ids) ? $this->generatePosterId(
                (string) ($data['ip_address'] ?? ''),
                $thread->id
            ) : null;
            [$countryCode, $countryName] = ($board && $board->country_flags)
                ? $this->resolveCountry((string) ($data['ip_address'] ?? ''))
                : [null, null];

            /** @var Post $post */
            $post = Post::create([
                // 'id' is auto-incremented by DB now
                'thread_id'            => $thread->id,
                'is_op'                => false,
                'author_name'          => $name,
                'tripcode'             => $trip,
                'email'                => $data['email'] ?? null,
                'subject'              => $data['subject'] ?? null,
                'content'              => $data['content'] ?? '',
                'content_html'         => $contentHtml,
                'ip_address'           => $this->piiEncryption->encrypt((string) ($data['ip_address'] ?? '')),
                'country_code'         => $countryCode,
                'country_name'         => $countryName,
                'poster_id'            => $posterId,
                'board_post_no'        => $boardPostNo,
                'media_url'            => $data['media_url'] ?? null,
                'thumb_url'            => $data['thumb_url'] ?? null,
                'media_filename'       => $data['media_filename'] ?? null,
                'media_size'           => $data['media_size'] ?? null,
                'media_dimensions'     => $data['media_dimensions'] ?? null,
                'media_hash'           => $data['media_hash'] ?? null,
                'spoiler_image'        => $data['spoiler'] ?? false,
                'capcode'              => $data['capcode'] ?? null,
                'delete_password_hash' => (isset($data['password']) && is_string($data['password']) && $data['password'] !== '') ? password_hash($data['password'], PASSWORD_BCRYPT) : null,
            ]);

            // Update thread counters
            $thread->newQuery()->where('id', $thread->id)->increment('reply_count');
            if ($post->media_url) {
                $thread->newQuery()->where('id', $thread->id)->increment('image_count');
            }

            // Bump thread unless sage
            if (!$isSage) {
                $board = $thread->board;
                $bumpLimit = $board ? $board->bump_limit : 300;
                if ($thread->reply_count <= $bumpLimit) {
                    $thread->update(['bumped_at' => \Carbon\Carbon::now()]);
                }
            }

            // Invalidate caches
            $this->redis->del("thread:{$thread->id}");
            // Invalidate catalog since reply count / last-modified changed
            if ($board) {
                $this->redis->del("catalog:{$board->slug}");
            }

            return [
                'post_id'   => $post->id,
                'thread_id' => $thread->id,
            ];
        });

        // Emit post.created event after successful transaction (fire-and-forget)
        $boardSlug = $board ? $board->slug : '';
        $this->eventPublisher->publish(CloudEvent::create(
            EventTypes::POST_CREATED,
            [
                'board_id' => $boardSlug,
                'thread_id' => (string) $result['thread_id'],
                'post_id' => (string) $result['post_id'],
                'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
                'content' => mb_substr((string) ($data['content'] ?? ''), 0, 10000),
                'media_refs' => array_filter([(string) ($data['media_url'] ?? '')]),
            ],
        ));

        return $result;
    }

    /* ──────────────────────────────────────────────
     * Delete Post
     * ────────────────────────────────────────────── */

    public function deletePost(int $postId, string $password, bool $imageOnly = false): bool
    {
        /** @var Post|null $post */
        $post = Post::find($postId);
        if (!$post || $post->deleted) return false;

        if (!$post->delete_password_hash || !password_verify($password, $post->delete_password_hash)) {
            return false;
        }

        if ($imageOnly) {
            $post->update([
                'media_url'  => null,
                'thumb_url'  => null,
                'media_size' => null,
            ]);
        } else {
            $post->update([
                'deleted'    => true,
                'deleted_at' => \Carbon\Carbon::now(),
                'content'    => '',
                'content_html' => '',
                'media_url'  => null,
                'thumb_url'  => null,
            ]);
        }

        return true;
    }

    /* ──────────────────────────────────────────────
     * Get New Posts (After ID)
     * ────────────────────────────────────────────── */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPostsAfter(int $threadId, int $afterId, bool $includeIpHash = false): array
    {
        // Note: Comparing UUIDs with '>' is not strictly chronological for v4 UUIDs,
        // but 'created_at' > would be better. However, let's keep it consistent with request for now,
        // but prefer created_at sort if we switch logic.
        // Actually, let's switch to created_at logic if we can, but 'afterId' implies we want posts that came after a specific post ID.
        // If IDs are UUIDv4, they are random. So 'id > afterId' is meaningless.
        // We must query posts created after the post with ID = afterId.
        
        $lastPost = Post::find($afterId);
        if (!$lastPost) {
            return [];
        }

        /** @var \Hyperf\Database\Model\Collection<int, Post> $posts */
        $posts = Post::query()
            ->where('thread_id', $threadId)
            ->where('created_at', '>', $lastPost->created_at)
            ->where('deleted', false)
            ->orderBy('created_at')
            ->get();

        /** @var array<int, array<string, mixed>> $result */
        $result = $posts->map(fn(Post $p) => $this->formatPostOutput($p, [], $includeIpHash))
            ->filter()
            ->values()
            ->toArray();
        return $result;
    }

    /* ──────────────────────────────────────────────
     * Private helpers
     * ────────────────────────────────────────────── */

    /**
     * @param array<int> $backlinks
     * @return ($post is null ? null : array<string, mixed>)
     */
    private function formatPostOutput(?Post $post, array $backlinks = [], bool $includeIpHash = false): ?array
    {
        if (!$post) return null;
        $output = [
            'id'               => $post->id,
            'board_post_no'    => $post->board_post_no,
            'author_name'      => $post->author_name,
            'tripcode'         => $post->tripcode,
            'capcode'          => $post->capcode,
            'poster_id'        => $post->poster_id,
            'country_code'     => $post->country_code,
            'country_name'     => $post->country_name,
            'subject'          => $post->subject,
            'content_html'     => $post->content_html,
            'content_preview'  => $post->content_preview,
            'created_at'       => $this->toTimestamp($post->created_at),
            'formatted_time'   => $this->formatTime($post->created_at),
            'media_url'        => $post->media_url,
            'thumb_url'        => $post->thumb_url,
            'media_filename'   => $post->media_filename,
            'media_size'       => $post->media_size,
            'media_size_human' => $post->media_size_human,
            'media_dimensions' => $post->media_dimensions,
            'spoiler_image'    => $post->spoiler_image,
            'backlinks'        => $backlinks,
        ];

        if ($includeIpHash) {
            $output['ip_hash'] = $this->generateGlobalIpHash($post);
        }

        return $output;
    }

    private function toTimestamp(mixed $dt): int
    {
        if ($dt instanceof \DateTimeInterface) {
            return $dt->getTimestamp();
        }
        if (is_string($dt)) {
            $t = strtotime($dt);
            return $t === false ? 0 : $t;
        }
        return time();
    }

    private function formatTime(mixed $datetime): string
    {
        if (!$datetime) return '';
        if ($datetime instanceof \DateTimeInterface) {
            $dt = $datetime;
        } else {
            try {
                $str = is_scalar($datetime) ? (string) $datetime : '';
                $dt = new \DateTimeImmutable($str);
            } catch (\Exception $e) {
                return '';
            }
        }
        $days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        return $dt->format('m/d/y') . '(' . $days[(int) $dt->format('w')] . ')' . $dt->format('H:i:s');
    }

    /**
     * Atomically allocate the next per-board post number.
     *
     * Uses UPDATE ... RETURNING to avoid race conditions.
     */
    private function allocateBoardPostNo(Board $board): int
    {
        $rows = Db::select(
            "UPDATE boards SET next_post_no = next_post_no + 1 WHERE id = ? RETURNING next_post_no - 1 AS post_no",
            [$board->id]
        );
        /** @var object{post_no: string|int} $row */
        $row = $rows[0];
        return (int) $row->post_no;
    }

    /**
     * Generate an 8-char poster ID (like 4chan).
     *
     * Deterministic per IP + thread + daily salt so same user always
     * gets the same ID within a thread on a given day.
     */
    private function generatePosterId(string $ipAddress, int $threadId): string
    {
        $salt = $this->getIpHashSalt();
        $daySalt = date('Y-m-d');
        $raw = hash_hmac('sha256', $ipAddress . '|' . $threadId . '|' . $daySalt, $salt, true);
        // URL-safe base64, 8 chars
        return substr(rtrim(base64_encode($raw), '='), 0, 8);
    }

    /**
     * Resolve country code and name from an IP address.
     *
     * Tries MaxMind GeoLite2-Country via the geoip2/geoip2 package.
     * Falls back gracefully to null if DB or package is missing.
     *
     * @return array{0: string|null, 1: string|null}  [country_code, country_name]
     */
    private function resolveCountry(string $ipAddress): array
    {
        if ($ipAddress === '' || $ipAddress === '127.0.0.1' || $ipAddress === '::1') {
            return [null, null];
        }

        static $reader = null;
        static $readerFailed = false;

        if ($readerFailed) {
            return [null, null];
        }

        if ($reader === null) {
            $paths = [
                '/usr/share/GeoIP/GeoLite2-Country.mmdb',
                '/var/lib/GeoIP/GeoLite2-Country.mmdb',
                BASE_PATH . '/data/GeoLite2-Country.mmdb',
            ];
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    try {
                        $reader = new \GeoIp2\Database\Reader($path);
                    } catch (\Throwable $e) {
                        // Skip
                    }
                    break;
                }
            }
            if ($reader === null) {
                $readerFailed = true;
                return [null, null];
            }
        }

        try {
            /** @var \GeoIp2\Database\Reader $reader */
            $record = $reader->country($ipAddress);
            /** @phpstan-ignore-next-line */
            $code = $record->country->isoCode ?? null;
            /** @phpstan-ignore-next-line */
            $name = $record->country->name ?? null;
            return [is_string($code) ? $code : null, is_string($name) ? $name : null];
        } catch (\Throwable $e) {
            return [null, null];
        }
    }

    /** Remove oldest threads when board exceeds max_threads. */
    private function pruneThreads(Board $board): void
    {
        $max = $board->max_threads ?: 200;
        $current = Thread::query()
            ->where('board_id', $board->id)
            ->where('archived', false)
            ->where('sticky', false)
            ->count();

        if ($current <= $max) return;

        // Bulk archive in a single UPDATE with subquery instead of N individual updates
        Db::statement(
            "UPDATE threads SET archived = true, archived_at = NOW()
             WHERE id IN (
                 SELECT id FROM threads
                 WHERE board_id = ? AND archived = false AND sticky = false
                 ORDER BY bumped_at ASC
                 LIMIT ?
             )",
            [$board->id, $current - $max]
        );
    }

    /* ──────────────────────────────────────────────
     * Staff Actions
     * ────────────────────────────────────────────── */

    public function staffDeletePost(int $postId, bool $imageOnly = false): bool
    {
        /** @var Post|null $post */
        $post = Post::find($postId);
        if (!$post) return false;

        if ($imageOnly) {
            $post->update([
                'media_url'  => null,
                'thumb_url'  => null,
                'media_size' => null,
            ]);
        } else {
            $post->update([
                'deleted'    => true,
                'deleted_at' => \Carbon\Carbon::now(),
                'content'    => '',
                'content_html' => '',
                'media_url'  => null,
                'thumb_url'  => null,
            ]);
        }

        // Invalidate cache
        $this->redis->del("thread:{$post->thread_id}");
        // Invalidate catalog (OP deletion or image removal affects catalog)
        $thread = Thread::with('board')->find($post->thread_id);
        if ($thread && $thread->board) {
            $this->redis->del("catalog:{$thread->board->slug}");
        }

        return true;
    }

    public function toggleThreadOption(int $threadId, string $option): bool
    {
        /** @var Thread|null $thread */
        $thread = Thread::find($threadId);
        if (!$thread) return false;

        if ($option === 'sticky') {
            $thread->sticky = !$thread->sticky;
        } elseif ($option === 'lock') {
            $thread->locked = !$thread->locked;
        } elseif ($option === 'permasage') {
            // Implement if supported
        }

        $thread->save();
        $this->redis->del("thread:{$thread->id}");
        // Need to load board relation to get slug for cache clearing
        $thread->load('board');
        if ($thread->board) {
            $this->redis->del("board:{$thread->board->slug}:index");
            $this->redis->del("catalog:{$thread->board->slug}");
        }

        return true;
    }

    public function toggleSpoiler(int $postId): bool
    {
        /** @var Post|null $post */
        $post = Post::find($postId);
        if (!$post) return false;

        $post->spoiler_image = !$post->spoiler_image;
        $post->save();
        
        $this->redis->del("thread:{$post->thread_id}");
        // Invalidate catalog (spoiler toggling an OP image affects catalog display)
        $thread = Thread::with('board')->find($post->thread_id);
        if ($thread && $thread->board) {
            $this->redis->del("catalog:{$thread->board->slug}");
        }

        return true;
    }

    /**
     * Get IP hashes for posts in a thread (Staff only).
     *
     * Decrypts IPs in memory to generate consistent poster IDs,
     * then immediately wipes the plaintext from memory.
     *
     * @return array<int, string> Map of post_id => ip_hash
     */
    public function getThreadIps(int $threadId): array
    {
        /** @var \Hyperf\Database\Model\Collection<int, Post> $posts */
        $posts = Post::query()
            ->where('thread_id', $threadId)
            ->get();

        $result = [];
        $salt = $this->getIpHashSalt();

        foreach ($posts as $post) {
            $encryptedIp = $post->getAttribute('ip_address');
            $plainIp = is_string($encryptedIp) && $encryptedIp !== ''
                ? $this->piiEncryption->decrypt($encryptedIp)
                : '';

            // Generate consistent poster ID hash from decrypted IP
            $hash = substr(base64_encode(pack('H*', sha1($plainIp . $salt))), 0, 8);
            $result[$post->id] = $hash;

            // Wipe plaintext IP from memory
            $this->piiEncryption->wipe($plainIp);
        }

        return $result;
    }

    /**
     * Get a decrypted IP address for a specific post (Staff/Admin only).
     *
     * This should be called sparingly and only for moderation purposes.
     * The caller is responsible for wiping the returned value from memory.
     */
    public function getDecryptedIp(int $postId): ?string
    {
        /** @var Post|null $post */
        $post = Post::find($postId);
        if (!$post) {
            return null;
        }

        $encryptedIp = $post->getAttribute('ip_address');
        if (!is_string($encryptedIp) || $encryptedIp === '') {
            return null;
        }

        return $this->piiEncryption->decrypt($encryptedIp);
    }

    /**
     * Generate a deterministic, global IP hash for a post.
     *
     * Unlike generatePosterId() which rotates per-thread & per-day,
     * this hash is stable across all threads and time so staff can
     * track a poster across the site by the same hash.
     *
     * Uses HMAC-SHA256 with a dedicated salt prefix to prevent
     * rainbow-table attacks and ensure domain separation from poster IDs.
     *
     * @return string 16-char hex hash (64 bits of entropy)
     */
    private function generateGlobalIpHash(Post $post): string
    {
        $encryptedIp = $post->getAttribute('ip_address');
        $plainIp = is_string($encryptedIp) && $encryptedIp !== ''
            ? $this->piiEncryption->decrypt($encryptedIp)
            : '';

        $salt = $this->getIpHashSalt();
        $hash = substr(hash_hmac('sha256', $plainIp, 'global_ip_hash:' . $salt), 0, 16);

        // Wipe plaintext IP from memory
        $this->piiEncryption->wipe($plainIp);

        return $hash;
    }

    /**
     * Find all posts across the site matching a global IP hash (Staff only).
     *
     * Uses batch processing to limit memory usage. Pre-loads thread/board
     * data to eliminate N+1 queries.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPostsByIpHash(string $ipHash, int $limit = 100): array
    {
        $salt = $this->getIpHashSalt();

        $results = [];
        $batchSize = 500;
        $offset = 0;
        $maxScan = $this->ipPostScanLimit;

        while ($offset < $maxScan && count($results) < $limit) {
            /** @var \Hyperf\Database\Model\Collection<int, Post> $posts */
            $posts = Post::query()
                ->where('deleted', false)
                ->orderByDesc('created_at')
                ->offset($offset)
                ->limit($batchSize)
                ->get();

            if ($posts->isEmpty()) {
                break;
            }

            // Collect matching posts first
            $matchingPosts = [];
            foreach ($posts as $post) {
                /** @var Post $post */
                $encryptedIp = $post->getAttribute('ip_address');
                $plainIp = is_string($encryptedIp) && $encryptedIp !== ''
                    ? $this->piiEncryption->decrypt($encryptedIp)
                    : '';

                $postHash = substr(hash_hmac('sha256', $plainIp, 'global_ip_hash:' . $salt), 0, 16);
                $this->piiEncryption->wipe($plainIp);

                if ($postHash === $ipHash) {
                    $matchingPosts[] = $post;
                    if (count($results) + count($matchingPosts) >= $limit) {
                        break;
                    }
                }
            }

            if (!empty($matchingPosts)) {
                // Batch-load thread and board data for all matching posts (avoids N+1)
                $threadIds = array_unique(array_map(fn(Post $p) => $p->thread_id, $matchingPosts));
                $threads = Thread::query()->whereIn('id', $threadIds)->get()->keyBy('id');
                $boardIds = $threads->pluck('board_id')->unique()->toArray();
                $boards = Board::query()->whereIn('id', $boardIds)->get()->keyBy('id');

                foreach ($matchingPosts as $post) {
                    $thread = $threads->get($post->thread_id);
                    $boardSlug = '';
                    if ($thread) {
                        $board = $boards->get($thread->board_id);
                        $boardSlug = $board ? $board->slug : '';
                    }

                    $results[] = [
                        'id'              => $post->id,
                        'board_post_no'   => $post->board_post_no,
                        'board_slug'      => $boardSlug,
                        'thread_id'       => $post->thread_id,
                        'author_name'     => $post->author_name,
                        'tripcode'        => $post->tripcode,
                        'subject'         => $post->subject,
                        'content_preview' => $post->content_preview,
                        'content_html'    => $post->content_html,
                        'media_url'       => $post->media_url,
                        'thumb_url'       => $post->thumb_url,
                        'created_at'      => $this->toTimestamp($post->created_at),
                        'formatted_time'  => $this->formatTime($post->created_at),
                        'ip_hash'         => $ipHash,
                    ];
                }
            }

            $offset += $batchSize;
        }

        return $results;
    }

    /**
     * Get the IP hash salt, failing fast if not configured.
     *
     * @throws \RuntimeException if ip_hash_salt is not set or empty
     */
    private function getIpHashSalt(): string
    {
        if ($this->ipHashSalt === '') {
            throw new \RuntimeException('ip_hash_salt must be configured in site settings (or IP_HASH_SALT env var)');
        }
        return $this->ipHashSalt;
    }

    /* ══════════════════════════════════════════════════════════════
     * LIVEPOSTING — Open Post Lifecycle
     * @see docs/LIVEPOSTING.md §5.7, §7
     * ══════════════════════════════════════════════════════════════ */

    /**
     * Allocate an open (editing) post for liveposting.
     *
     * Creates a post row with is_editing=true and an empty body,
     * plus an open_post_bodies row for the rapidly-changing text.
     * The post is visible to thread viewers immediately (as an
     * "open" post with a live cursor).
     *
     * @param Thread $thread The target thread
     * @param array{
     *     name?: string,
     *     email?: string,
     *     password?: string,
     *     ip_address?: string,
     * } $data Post creation data
     * @return array{post_id: int, thread_id: int, board_post_no: int|null}
     * @throws \RuntimeException if the thread is locked
     */
    public function createOpenPost(Thread $thread, array $data): array
    {
        if ($thread->locked) {
            throw new \RuntimeException('Thread is locked');
        }

        $board = $thread->board;

        /** @var array{post_id: int, thread_id: int, board_post_no: int|null} $result */
        $result = Db::transaction(function () use ($thread, $data, $board): array {
            $boardPostNo = $board ? $this->allocateBoardPostNo($board) : null;

            $rawName = $data['name'] ?? '';
            [$name, $trip] = $this->formatter->parseNameTrip(is_string($rawName) ? $rawName : '');

            $rawEmail = $data['email'] ?? '';
            $email = is_string($rawEmail) ? $rawEmail : '';

            // Generate poster ID and country if board features are enabled
            $posterId = ($board && $board->user_ids) ? $this->generatePosterId(
                (string) ($data['ip_address'] ?? ''),
                $thread->id
            ) : null;
            [$countryCode, $countryName] = ($board && $board->country_flags)
                ? $this->resolveCountry((string) ($data['ip_address'] ?? ''))
                : [null, null];

            // Hash the reclaim password (low-cost bcrypt for fast reclamation)
            $passwordRaw = $data['password'] ?? '';
            $editPasswordHash = (is_string($passwordRaw) && $passwordRaw !== '')
                ? password_hash($passwordRaw, PASSWORD_BCRYPT, ['cost' => 4])
                : null;

            /** @var Post $post */
            $post = Post::create([
                'thread_id'            => $thread->id,
                'is_op'                => false,
                'author_name'          => $name,
                'tripcode'             => $trip,
                'email'                => $email ?: null,
                'subject'              => null,
                'content'              => '',
                'content_html'         => '',
                'ip_address'           => $this->piiEncryption->encrypt((string) ($data['ip_address'] ?? '')),
                'country_code'         => $countryCode,
                'country_name'         => $countryName,
                'poster_id'            => $posterId,
                'board_post_no'        => $boardPostNo,
                'spoiler_image'        => false,
                'is_editing'           => true,
                'edit_password_hash'   => $editPasswordHash,
                'edit_expires_at'      => (new \DateTimeImmutable('+15 minutes'))->format(\DateTimeInterface::RFC3339),
                'delete_password_hash' => (isset($data['password']) && is_string($data['password']) && $data['password'] !== '')
                    ? password_hash($data['password'], PASSWORD_BCRYPT)
                    : null,
            ]);

            // Create the separate body row
            OpenPostBody::create([
                'post_id'    => $post->id,
                'body'       => '',
                'updated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            ]);

            // Increment thread reply count (open posts count as replies)
            $thread->newQuery()->where('id', $thread->id)->increment('reply_count');

            // Bump thread unless sage
            $isSage = strtolower(trim($email)) === 'sage';
            if (!$isSage) {
                $bumpLimit = $board ? $board->bump_limit : 300;
                if ($thread->reply_count <= $bumpLimit) {
                    $thread->update(['bumped_at' => \Carbon\Carbon::now()]);
                }
            }

            // Don't invalidate caches yet — the post is empty and being edited.
            // Cache invalidation happens on closeOpenPost().

            return [
                'post_id'       => $post->id,
                'thread_id'     => $thread->id,
                'board_post_no' => $boardPostNo,
            ];
        });

        // Publish livepost.opened event
        $boardSlug = $board ? $board->slug : '';
        $this->eventPublisher->publish(CloudEvent::create(
            'livepost.opened',
            [
                'board_id'   => $boardSlug,
                'thread_id'  => (string) $result['thread_id'],
                'post_id'    => (string) $result['post_id'],
                'ip_hash'    => hash('sha256', (string) ($data['ip_address'] ?? '')),
            ],
        ));

        return $result;
    }

    /**
     * Close (finalize) an open post.
     *
     * Copies the body from open_post_bodies to posts.content, parses
     * markup into content_html, clears the editing state, and deletes
     * the open_post_bodies row.
     *
     * @param int $postId The post to close
     * @return array{post_id: int, thread_id: int, content_html: string}|null Null if not found or not editing
     */
    public function closeOpenPost(int $postId): ?array
    {
        /** @var Post|null $post */
        $post = Post::find($postId);
        if ($post === null || !$post->is_editing) {
            return null;
        }

        /** @var OpenPostBody|null $openBody */
        $openBody = OpenPostBody::find($postId);
        $finalBody = $openBody ? $openBody->body : '';

        // Parse the final body into HTML
        $contentHtml = $this->formatter->format($finalBody);

        $result = Db::transaction(function () use ($post, $finalBody, $contentHtml, $openBody): array {
            $post->update([
                'content'            => $finalBody,
                'content_html'       => $contentHtml,
                'is_editing'         => false,
                'edit_password_hash' => null,
                'edit_expires_at'    => null,
            ]);

            // Remove the separate body row
            if ($openBody !== null) {
                $openBody->delete();
            }

            // Invalidate caches — the post now has final content
            $this->redis->del("thread:{$post->thread_id}");
            $board = $post->thread?->board;
            if ($board) {
                $this->redis->del("catalog:{$board->slug}");
            }

            return [
                'post_id'      => $post->id,
                'thread_id'    => $post->thread_id,
                'content_html' => $contentHtml,
            ];
        });

        // Publish livepost.closed event
        $thread = $post->thread;
        $boardSlug = $thread?->board?->slug ?? '';
        $this->eventPublisher->publish(CloudEvent::create(
            'livepost.closed',
            [
                'board_id'         => $boardSlug,
                'thread_id'        => (string) $post->thread_id,
                'post_id'          => (string) $post->id,
                'final_body'       => mb_substr($finalBody, 0, 10000),
                'duration_seconds' => time() - (int) strtotime($post->created_at),
            ],
        ));

        return $result;
    }

    /**
     * Update the body of an open post (debounced persistence from gateway).
     *
     * @param int    $postId The open post ID
     * @param string $body   The current body text
     * @return bool True if updated, false if not found or not editing
     */
    public function setOpenBody(int $postId, string $body): bool
    {
        /** @var Post|null $post */
        $post = Post::find($postId);
        if ($post === null || !$post->is_editing) {
            return false;
        }

        OpenPostBody::query()
            ->where('post_id', $postId)
            ->update([
                'body'       => $body,
                'updated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            ]);

        return true;
    }

    /**
     * Reclaim an open post after disconnection.
     *
     * Verifies the password hash matches the one stored at post creation.
     *
     * @param int    $postId  The post to reclaim
     * @param string $password The plaintext password to verify
     * @return array{post_id: int, thread_id: int, body: string}|null Null if verification fails or not editing
     */
    public function reclaimPost(int $postId, string $password): ?array
    {
        /** @var Post|null $post */
        $post = Post::find($postId);
        if ($post === null || !$post->is_editing) {
            return null;
        }

        // Verify the reclaim password
        if ($post->edit_password_hash === null || !password_verify($password, $post->edit_password_hash)) {
            return null;
        }

        // Extend the expiry (grant another 15 minutes)
        $post->update([
            'edit_expires_at' => (new \DateTimeImmutable('+15 minutes'))->format(\DateTimeInterface::RFC3339),
        ]);

        // Return the current body for the client to resume editing
        /** @var OpenPostBody|null $openBody */
        $openBody = OpenPostBody::find($postId);

        return [
            'post_id'   => $post->id,
            'thread_id' => $post->thread_id,
            'body'      => $openBody ? $openBody->body : '',
        ];
    }

    /**
     * Force-close expired open posts (called by a cleanup timer).
     *
     * @return int Number of posts closed
     */
    public function closeExpiredPosts(): int
    {
        $expired = Post::query()
            ->where('is_editing', true)
            ->where('edit_expires_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($expired as $post) {
            $result = $this->closeOpenPost($post->id);
            if ($result !== null) {
                // Publish livepost.expired event
                $thread = $post->thread;
                $boardSlug = $thread?->board?->slug ?? '';
                $this->eventPublisher->publish(CloudEvent::create(
                    'livepost.expired',
                    [
                        'board_id'  => $boardSlug,
                        'thread_id' => (string) $post->thread_id,
                        'post_id'   => (string) $post->id,
                        'reason'    => 'timeout',
                    ],
                ));
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get the formatted output for an open post (for sync messages).
     *
     * @param Post $post
     * @return array<string, mixed>
     */
    public function formatOpenPostOutput(Post $post): array
    {
        /** @var OpenPostBody|null $openBody */
        $openBody = OpenPostBody::find($post->id);

        return [
            'id'              => $post->id,
            'board_post_no'   => $post->board_post_no,
            'author_name'     => $post->author_name,
            'tripcode'        => $post->tripcode,
            'poster_id'       => $post->poster_id,
            'country_code'    => $post->country_code,
            'country_name'    => $post->country_name,
            'created_at'      => $this->toTimestamp($post->created_at),
            'formatted_time'  => $this->formatTime($post->created_at),
            'is_editing'      => true,
            'body'            => $openBody ? $openBody->body : '',
        ];
    }
}
