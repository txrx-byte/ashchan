<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Board;
use App\Model\Post;
use App\Model\Thread;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use function Hyperf\Support\env;

final class BoardService
{
    public function __construct(
        private ContentFormatter $formatter,
        private Redis $redis,
    ) {}

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
        // Always query DB to ensure proper model hydration
        // Cache is used only to check if board exists (fast path for 404s)
        try {
            $key = "board:{$slug}";
            // Disable cache in development mode
            if (env('APP_ENV', 'production') !== 'local') {
                $cached = $this->redis->get($key);
                if ($cached === 'NOT_FOUND') {
                    return null;
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
        return \App\Model\Blotter::query()
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn($b) => [
                'id' => $b->id,
                'content' => $b->content,
                'is_important' => $b->is_important,
                'created_at' => $this->toTimestamp($b->created_at),
            ])
            ->toArray();
    }

    /* ──────────────────────────────────────────────
     * Threads – Index
     * ────────────────────────────────────────────── */

    /**
     * Board index: threads sorted by bump order, paginated.
     * Returns threads with OP + latest N replies.
     * @return array{threads: array<int, array<string, mixed>>, page: int, total_pages: int, total: int}
     */
    public function getThreadIndex(Board $board, int $page = 1, int $perPage = 15): array
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

        $result = [];
        foreach ($threads as $thread) {
            /** @var Thread $thread */
            /** @var Post|null $op */
            $op = Post::query()
                ->where('thread_id', $thread->id)
                ->where('is_op', true)
                ->where('deleted', false)
                ->first();

            /** @var \Hyperf\Database\Model\Collection<int, Post> $latestReplies */
            $latestReplies = Post::query()
                ->where('thread_id', $thread->id)
                ->where('is_op', false)
                ->where('deleted', false)
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->reverse()
                ->values();

            $totalReplies = $thread->reply_count;
            $shownReplies = $latestReplies->count();
            $omittedPosts = max(0, $totalReplies - $shownReplies);
            $omittedImages = 0;
            if ($omittedPosts > 0) {
                $totalImages = Post::query()
                    ->where('thread_id', $thread->id)
                    ->where('is_op', false)
                    ->where('deleted', false)
                    ->whereNotNull('media_url')
                    ->count();
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
                'op'              => $this->formatPostOutput($op),
                'latest_replies'  => $latestReplies->map(function (Post $r) {
                    return $this->formatPostOutput($r);
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
    public function getThread(int $threadId): ?array
    {
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

        return [
            'thread_id'     => $thread->id,
            'board_id'      => $thread->board_id,
            'sticky'        => $thread->sticky,
            'locked'        => $thread->locked,
            'archived'      => $thread->archived,
            'reply_count'   => $thread->reply_count,
            'image_count'   => $thread->image_count,
            'op'            => $this->formatPostOutput($op, ($op ? ($backlinks[(string)$op->id] ?? []) : [])),
            'replies'       => $replies->map(function (Post $r) use ($backlinks) {
                return $this->formatPostOutput($r, $backlinks[(string)$r->id] ?? []);
            })->toArray(),
        ];
    }

    /* ──────────────────────────────────────────────
     * Threads – Catalog
     * ────────────────────────────────────────────── */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCatalog(Board $board): array
    {
        $threads = Thread::query()
            ->where('board_id', $board->id)
            ->where('archived', false)
            ->orderByDesc('sticky')
            ->orderByDesc('bumped_at')
            ->limit($board->max_threads ?: 200)
            ->get();

        $result = [];
        foreach ($threads as $thread) {
            /** @var Thread $thread */
            /** @var Post|null $op */
            $op = Post::query()
                ->where('thread_id', $thread->id)
                ->where('is_op', true)
                ->where('deleted', false)
                ->first();

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
            ->limit(3000)
            ->get();

        $result = [];
        foreach ($threads as $thread) {
            /** @var Thread $thread */
            /** @var Post|null $op */
            $op = Post::query()
                ->where('thread_id', $thread->id)
                ->where('is_op', true)
                ->first();

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
                'ip_hash'              => $data['ip_hash'] ?? '',
                'media_url'            => $data['media_url'] ?? null,
                'thumb_url'            => $data['thumb_url'] ?? null,
                'media_filename'       => $data['media_filename'] ?? null,
                'media_size'           => $data['media_size'] ?? null,
                'media_dimensions'     => $data['media_dimensions'] ?? null,
                'media_hash'           => $data['media_hash'] ?? null,
                'spoiler_image'        => $data['spoiler'] ?? false,
                'delete_password_hash' => (isset($data['password']) && is_string($data['password'])) ? password_hash($data['password'], PASSWORD_BCRYPT) : null,
            ]);

            // Prune old threads if over limit
            $this->pruneThreads($board);

            // Invalidate caches
            $this->redis->del("board:{$board->slug}:index");

            return [
                'thread_id' => $thread->id,
                'post_id'   => $post->id,
            ];
        });
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

        /** @var array<string, int> $result */
        $result = Db::transaction(function () use ($thread, $data) {
            $rawName = $data['name'] ?? '';
            [$name, $trip] = $this->formatter->parseNameTrip(is_string($rawName) ? $rawName : '');
            $rawContent = $data['content'] ?? '';
            $contentHtml = $this->formatter->format(is_string($rawContent) ? $rawContent : '');
            $rawEmail = $data['email'] ?? '';
            $email = is_string($rawEmail) ? $rawEmail : '';
            $isSage = strtolower(trim($email)) === 'sage';

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
                'ip_hash'              => $data['ip_hash'] ?? '',
                'media_url'            => $data['media_url'] ?? null,
                'thumb_url'            => $data['thumb_url'] ?? null,
                'media_filename'       => $data['media_filename'] ?? null,
                'media_size'           => $data['media_size'] ?? null,
                'media_dimensions'     => $data['media_dimensions'] ?? null,
                'media_hash'           => $data['media_hash'] ?? null,
                'spoiler_image'        => $data['spoiler'] ?? false,
                'delete_password_hash' => (isset($data['password']) && is_string($data['password'])) ? password_hash($data['password'], PASSWORD_BCRYPT) : null,
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

            return [
                'post_id'   => $post->id,
                'thread_id' => $thread->id,
            ];
        });
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
    public function getPostsAfter(int $threadId, int $afterId): array
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
        $result = $posts->map(fn(Post $p) => $this->formatPostOutput($p))
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
    private function formatPostOutput(?Post $post, array $backlinks = []): ?array
    {
        if (!$post) return null;
        return [
            'id'               => $post->id,
            'author_name'      => $post->author_name,
            'tripcode'         => $post->tripcode,
            'capcode'          => $post->capcode,
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

        $toPrune = Thread::query()
            ->where('board_id', $board->id)
            ->where('archived', false)
            ->where('sticky', false)
            ->orderBy('bumped_at')
            ->limit($current - $max)
            ->get();

        foreach ($toPrune as $thread) {
            /** @var Thread $thread */
            $thread->update(['archived' => true]);
        }
    }
}
