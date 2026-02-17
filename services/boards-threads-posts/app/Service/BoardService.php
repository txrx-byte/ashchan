<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Board;
use App\Model\Post;
use App\Model\Thread;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;

final class BoardService
{
    public function __construct(
        private ContentFormatter $formatter,
        private Redis $redis,
    ) {}

    /* ──────────────────────────────────────────────
     * Boards
     * ────────────────────────────────────────────── */

    /** List all boards, cached. */
    public function listBoards(): array
    {
        try {
            $key = 'boards:all';
            $cached = $this->redis->get($key);
            if ($cached) {
                return json_decode($cached, true);
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
            $this->redis->setex($key ?? 'boards:all', 300, json_encode($boards));
        } catch (\Throwable $e) {
            // Redis unavailable, skip caching
        }
        return $boards;
    }

    public function getBoard(string $slug): ?Board
    {
        try {
            $key = "board:{$slug}";
            $cached = $this->redis->get($key);
            if ($cached) {
                return new Board(json_decode($cached, true));
            }
        } catch (\Throwable $e) {
            // Redis unavailable, fall through to DB
        }
        $board = Board::query()->where('slug', $slug)->first();
        if ($board) {
            try {
                $this->redis->setex("board:{$slug}", 300, json_encode($board->toArray()));
            } catch (\Throwable $e) {
                // Redis unavailable
            }
        }
        return $board;
    }

    /* ──────────────────────────────────────────────
     * Threads – Index
     * ────────────────────────────────────────────── */

    /**
     * Board index: threads sorted by bump order, paginated.
     * Returns threads with OP + latest N replies.
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
            $op = Post::query()
                ->where('thread_id', $thread->id)
                ->where('is_op', true)
                ->where('deleted', false)
                ->first();

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
                $shownImages = $latestReplies->filter(fn($r) => $r->media_url)->count();
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
                'latest_replies'  => $latestReplies->map(fn($r) => $this->formatPostOutput($r))->toArray(),
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

    public function getThread(int $threadId): ?array
    {
        $thread = Thread::find($threadId);
        if (!$thread) return null;

        $posts = Post::query()
            ->where('thread_id', $threadId)
            ->where('deleted', false)
            ->orderBy('id')
            ->get();

        $op = $posts->first(fn($p) => $p->is_op);
        $replies = $posts->filter(fn($p) => !$p->is_op)->values();

        // Build backlinks map
        $backlinks = [];
        foreach ($posts as $post) {
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
            'op'            => $this->formatPostOutput($op, $backlinks[$op->id] ?? []),
            'replies'       => $replies->map(fn($r) => $this->formatPostOutput($r, $backlinks[$r->id] ?? []))->toArray(),
        ];
    }

    /* ──────────────────────────────────────────────
     * Threads – Catalog
     * ────────────────────────────────────────────── */

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
                'bumped_at'   => strtotime($thread->bumped_at),
                'created_at'  => strtotime($thread->created_at),
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

    public function createThread(Board $board, array $data): array
    {
        return Db::transaction(function () use ($board, $data) {
            $thread = Thread::create([
                'board_id' => $board->id,
                'sticky'   => false,
                'locked'   => false,
                'archived' => false,
            ]);
            $thread->update([
                'bumped_at'   => now(),
                'reply_count' => 0,
                'image_count' => isset($data['media_url']) ? 1 : 0,
            ]);

            [$name, $trip] = $this->formatter->parseNameTrip($data['name'] ?? '');
            $contentHtml = $this->formatter->format($data['content'] ?? '');

            $post = Post::create([
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
                'delete_password_hash' => isset($data['password']) ? password_hash($data['password'], PASSWORD_BCRYPT) : null,
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
    }

    /* ──────────────────────────────────────────────
     * Create Post (Reply)
     * ────────────────────────────────────────────── */

    public function createPost(Thread $thread, array $data): array
    {
        if ($thread->locked) {
            throw new \RuntimeException('Thread is locked');
        }

        return Db::transaction(function () use ($thread, $data) {
            [$name, $trip] = $this->formatter->parseNameTrip($data['name'] ?? '');
            $contentHtml = $this->formatter->format($data['content'] ?? '');
            $isSage = strtolower(trim($data['email'] ?? '')) === 'sage';

            $post = Post::create([
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
                'delete_password_hash' => isset($data['password']) ? password_hash($data['password'], PASSWORD_BCRYPT) : null,
            ]);

            // Update thread counters
            $thread->increment('reply_count');
            if ($post->media_url) {
                $thread->increment('image_count');
            }

            // Bump thread unless sage
            if (!$isSage) {
                $board = $thread->board;
                $bumpLimit = $board ? $board->bump_limit : 300;
                if ($thread->reply_count <= $bumpLimit) {
                    $thread->update(['bumped_at' => now()]);
                }
            }

            // Invalidate caches
            $this->redis->del("thread:{$thread->id}");

            return [
                'post_id'   => $post->id,
                'thread_id' => $thread->id,
            ];
        });
    }

    /* ──────────────────────────────────────────────
     * Delete Post
     * ────────────────────────────────────────────── */

    public function deletePost(int $postId, string $password, bool $imageOnly = false): bool
    {
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
                'deleted_at' => now(),
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

    public function getPostsAfter(int $threadId, int $afterId): array
    {
        return Post::query()
            ->where('thread_id', $threadId)
            ->where('id', '>', $afterId)
            ->where('deleted', false)
            ->orderBy('id')
            ->get()
            ->map(fn($p) => $this->formatPostOutput($p))
            ->toArray();
    }

    /* ──────────────────────────────────────────────
     * Private helpers
     * ────────────────────────────────────────────── */

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
            'created_at'       => strtotime($post->created_at ?? 'now'),
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

    private function formatTime(?string $datetime): string
    {
        if (!$datetime) return '';
        $dt = new \DateTimeImmutable($datetime);
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
            $thread->update(['archived' => true]);
        }
    }
}
