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
use App\Model\Post;
use App\Model\Thread;

use function Hyperf\Collection\collect;

/**
 * Service that transforms ashchan data into the exact 4chan API JSON format.
 *
 * Reference: https://github.com/4chan/4chan-API
 *
 * Endpoints mapped:
 *   - boards.json           → GET /api/4chan/boards.json
 *   - /{board}/threads.json → GET /api/4chan/{board}/threads.json
 *   - /{board}/catalog.json → GET /api/4chan/{board}/catalog.json
 *   - /{board}/{page}.json  → GET /api/4chan/{board}/{page}.json
 *   - /{board}/thread/{no}  → GET /api/4chan/{board}/thread/{no}.json
 *   - /{board}/archive.json → GET /api/4chan/{board}/archive.json
 */
final class FourChanApiService
{
    private const DEFAULT_PER_PAGE = 15;
    private const DEFAULT_MAX_PAGES = 10;
    private const PREVIEW_REPLIES = 5;
    private const CATALOG_LAST_REPLIES = 5;

    /* ──────────────────────────────────────────────
     * boards.json
     * ────────────────────────────────────────────── */

    /**
     * Build boards.json response.
     *
     * @return array{boards: array<int, array<string, mixed>>}
     */
    public function getBoards(): array
    {
        $boards = Board::query()
            ->where('archived', false)
            ->orderBy('category')
            ->orderBy('slug')
            ->get();

        $result = [];
        foreach ($boards as $board) {
            /** @var Board $board */
            $result[] = $this->formatBoard($board);
        }

        return ['boards' => $result];
    }

    /* ──────────────────────────────────────────────
     * /{board}/threads.json (Threadlist)
     * ────────────────────────────────────────────── */

    /**
     * Build threads.json (threadlist) — array of pages, each with thread stubs.
     *
     * @return array<int, array{page: int, threads: array<int, array{no: int, last_modified: int, replies: int}>}>
     */
    public function getThreadList(Board $board): array
    {
        $perPage = self::DEFAULT_PER_PAGE;
        $maxPages = self::DEFAULT_MAX_PAGES;

        $threads = Thread::query()
            ->where('board_id', $board->id)
            ->where('archived', false)
            ->orderByDesc('sticky')
            ->orderByDesc('bumped_at')
            ->limit($perPage * $maxPages)
            ->get();

        $pages = [];
        $chunks = $threads->chunk($perPage);
        $pageNum = 1;

        foreach ($chunks as $chunk) {
            $threadStubs = [];
            foreach ($chunk as $thread) {
                /** @var Thread $thread */
                $threadStubs[] = [
                    'no'            => $thread->id,
                    'last_modified' => $this->toTimestamp($thread->updated_at),
                    'replies'       => $thread->reply_count,
                ];
            }
            $pages[] = [
                'page'    => $pageNum,
                'threads' => $threadStubs,
            ];
            $pageNum++;
        }

        return $pages;
    }

    /* ──────────────────────────────────────────────
     * /{board}/catalog.json
     * ────────────────────────────────────────────── */

    /**
     * Build catalog.json — array of pages, each page has threads (OP attrs + last_replies).
     *
     * @return array<int, array{page: int, threads: array<int, array<string, mixed>>}>
     */
    public function getCatalog(Board $board): array
    {
        $perPage = self::DEFAULT_PER_PAGE;
        $maxPages = self::DEFAULT_MAX_PAGES;

        $threads = Thread::query()
            ->where('board_id', $board->id)
            ->where('archived', false)
            ->orderByDesc('sticky')
            ->orderByDesc('bumped_at')
            ->limit($perPage * $maxPages)
            ->get();

        if ($threads->isEmpty()) {
            return [];
        }

        // Batch-load all OPs and replies (avoids N+1)
        $threadIds = $threads->pluck('id')->toArray();
        /** @var \Hyperf\Database\Model\Collection<int, Post> $allOps */
        $allOps = Post::query()
            ->whereIn('thread_id', $threadIds)
            ->where('is_op', true)
            ->where('deleted', false)
            ->get()
            ->keyBy('thread_id');

        $allReplies = Post::query()
            ->whereIn('thread_id', $threadIds)
            ->where('is_op', false)
            ->where('deleted', false)
            ->orderByDesc('id')
            ->get()
            ->groupBy('thread_id');

        $pages = [];
        $chunks = $threads->chunk($perPage);
        $pageNum = 1;

        foreach ($chunks as $chunk) {
            $threadEntries = [];
            foreach ($chunk as $thread) {
                /** @var Thread $thread */
                /** @var Post|null $op */
                $op = $allOps->get($thread->id);

                if (!$op) {
                    continue;
                }

                $entry = $this->formatPost4chan($op, $thread, true);

                // Add catalog-specific fields
                $entry['replies'] = $thread->reply_count;
                $entry['images'] = $thread->image_count;
                $entry['omitted_posts'] = max(0, $thread->reply_count - self::CATALOG_LAST_REPLIES);
                $entry['omitted_images'] = 0;

                /** @var \Hyperf\Database\Model\Collection<int, Post> $threadReplies */
                $threadReplies = $allReplies->get($thread->id, collect());

                if ($thread->reply_count > 0 && $entry['omitted_posts'] > 0) {
                    $totalImages = $threadReplies->filter(fn(Post $r) => (bool) $r->media_url)->count();
                    $entry['omitted_images'] = $totalImages;
                }

                $entry['last_modified'] = $this->toTimestamp($thread->updated_at);

                // last_replies: most recent N replies
                $lastReplies = $threadReplies->take(self::CATALOG_LAST_REPLIES)->reverse()->values();

                $lastRepliesFormatted = [];
                $shownImageCount = 0;
                foreach ($lastReplies as $reply) {
                    /** @var Post $reply */
                    $lastRepliesFormatted[] = $this->formatPost4chan($reply, $thread, false);
                    if ($reply->media_url) {
                        $shownImageCount++;
                    }
                }

                if ($entry['omitted_posts'] > 0) {
                    $entry['omitted_images'] = max(0, (int) $entry['omitted_images'] - $shownImageCount);
                }

                if (!empty($lastRepliesFormatted)) {
                    $entry['last_replies'] = $lastRepliesFormatted;
                }

                $entry['bumplimit'] = ($thread->reply_count >= ($board->bump_limit ?: 300)) ? 1 : null;
                $entry['imagelimit'] = ($thread->image_count >= ($board->image_limit ?: 150)) ? 1 : null;

                // Remove null values (4chan omits fields that are not set)
                $entry = $this->removeNulls($entry);

                $threadEntries[] = $entry;
            }

            $pages[] = [
                'page'    => $pageNum,
                'threads' => $threadEntries,
            ];
            $pageNum++;
        }

        return $pages;
    }

    /* ──────────────────────────────────────────────
     * /{board}/{page}.json (Index page)
     * ────────────────────────────────────────────── */

    /**
     * Build index page JSON — array of threads, each with OP + preview replies.
     *
     * @return array{threads: array<int, array{posts: array<int, array<string, mixed>>}>}|null
     */
    public function getIndexPage(Board $board, int $page): ?array
    {
        $perPage = self::DEFAULT_PER_PAGE;
        $maxPages = self::DEFAULT_MAX_PAGES;

        if ($page < 1 || $page > $maxPages) {
            return null;
        }

        $threads = Thread::query()
            ->where('board_id', $board->id)
            ->where('archived', false)
            ->orderByDesc('sticky')
            ->orderByDesc('bumped_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        if ($threads->isEmpty() && $page > 1) {
            return null;
        }

        // Batch-load all OPs and replies (avoids N+1)
        $threadIds = $threads->pluck('id')->toArray();
        /** @var \Hyperf\Database\Model\Collection<int, Post> $allOps */
        $allOps = Post::query()
            ->whereIn('thread_id', $threadIds)
            ->where('is_op', true)
            ->where('deleted', false)
            ->get()
            ->keyBy('thread_id');

        $allReplies = Post::query()
            ->whereIn('thread_id', $threadIds)
            ->where('is_op', false)
            ->where('deleted', false)
            ->orderByDesc('id')
            ->get()
            ->groupBy('thread_id');

        $threadEntries = [];
        foreach ($threads as $thread) {
            /** @var Thread $thread */
            /** @var Post|null $op */
            $op = $allOps->get($thread->id);

            if (!$op) {
                continue;
            }

            $opFormatted = $this->formatPost4chan($op, $thread, true);
            $opFormatted['replies'] = $thread->reply_count;
            $opFormatted['images'] = $thread->image_count;
            $opFormatted['last_modified'] = $this->toTimestamp($thread->updated_at);
            $opFormatted['unique_ips'] = $this->getUniqueIpCount($thread->id);

            // Bump/image limit flags
            if ($thread->reply_count >= ($board->bump_limit ?: 300)) {
                $opFormatted['bumplimit'] = 1;
            }
            if ($thread->image_count >= ($board->image_limit ?: 150)) {
                $opFormatted['imagelimit'] = 1;
            }

            // Preview replies from batch-loaded data
            /** @var \Hyperf\Database\Model\Collection<int, Post> $threadReplies */
            $threadReplies = $allReplies->get($thread->id, collect());
            $latestReplies = $threadReplies->take(self::PREVIEW_REPLIES)->reverse()->values();

            $totalReplies = $thread->reply_count;
            $shownReplies = $latestReplies->count();
            $omittedPosts = max(0, $totalReplies - $shownReplies);

            if ($omittedPosts > 0) {
                $opFormatted['omitted_posts'] = $omittedPosts;

                $totalImages = $threadReplies->filter(fn(Post $r) => (bool) $r->media_url)->count();
                $shownImages = $latestReplies->filter(fn(Post $r) => (bool) $r->media_url)->count();
                $opFormatted['omitted_images'] = max(0, $totalImages - $shownImages);
            }

            // Remove null values
            $opFormatted = $this->removeNulls($opFormatted);

            $posts = [$opFormatted];
            foreach ($latestReplies as $reply) {
                /** @var Post $reply */
                $formatted = $this->formatPost4chan($reply, $thread, false);
                $posts[] = $this->removeNulls($formatted);
            }

            $threadEntries[] = ['posts' => $posts];
        }

        return ['threads' => $threadEntries];
    }

    /* ──────────────────────────────────────────────
     * /{board}/thread/{no}.json (Full thread)
     * ────────────────────────────────────────────── */

    /**
     * Build full thread JSON.
     *
     * @return array{posts: array<int, array<string, mixed>>}|null
     */
    public function getThread(Board $board, int $threadNo): ?array
    {
        /** @var Thread|null $thread */
        $thread = Thread::query()
            ->where('id', $threadNo)
            ->where('board_id', $board->id)
            ->first();

        if (!$thread) {
            return null;
        }

        $allPosts = Post::query()
            ->where('thread_id', $thread->id)
            ->where('deleted', false)
            ->orderBy('id')
            ->get();

        $posts = [];

        foreach ($allPosts as $post) {
            /** @var Post $post */
            $isOp = $post->is_op;
            $formatted = $this->formatPost4chan($post, $thread, $isOp);

            if ($isOp) {
                $formatted['replies'] = $thread->reply_count;
                $formatted['images'] = $thread->image_count;

                if (!$thread->archived) {
                    $formatted['unique_ips'] = $this->getUniqueIpCount($thread->id);
                }

                if ($thread->reply_count >= ($board->bump_limit ?: 300)) {
                    $formatted['bumplimit'] = 1;
                }
                if ($thread->image_count >= ($board->image_limit ?: 150)) {
                    $formatted['imagelimit'] = 1;
                }
                if ($thread->archived) {
                    $formatted['archived'] = 1;
                    $formatted['archived_on'] = $this->toTimestamp($thread->updated_at);
                }
            }

            $posts[] = $this->removeNulls($formatted);
        }

        return ['posts' => $posts];
    }

    /* ──────────────────────────────────────────────
     * /{board}/archive.json
     * ────────────────────────────────────────────── */

    /**
     * Build archive.json — simple array of archived thread OP numbers.
     *
     * @return array<int>
     */
    public function getArchive(Board $board): array
    {
        /** @var array<int> $ids */
        $ids = Thread::query()
            ->where('board_id', $board->id)
            ->where('archived', true)
            ->orderByDesc('updated_at')
            ->pluck('id')
            ->toArray();
        return $ids;
    }

    /* ──────────────────────────────────────────────
     * Post → 4chan format transformer
     * ────────────────────────────────────────────── */

    /**
     * Transform an ashchan Post into the exact 4chan post JSON object.
     *
     * @return array<string, mixed>
     */
    private function formatPost4chan(Post $post, Thread $thread, bool $isOp): array
    {
        $time = $this->toTimestamp($post->created_at);
        $result = [
            'no'   => $post->id,
            'resto' => $isOp ? 0 : $thread->id,
            'now'  => $this->format4chanTime($post->created_at),
            'time' => $time,
            'name' => $post->author_name ?: 'Anonymous',
        ];

        // Sticky (OP only)
        if ($isOp && $thread->sticky) {
            $result['sticky'] = 1;
        }

        // Closed (OP only)
        if ($isOp && $thread->locked) {
            $result['closed'] = 1;
        }

        // Tripcode
        if ($post->tripcode) {
            $result['trip'] = $post->tripcode;
        }

        // Capcode
        if ($post->capcode) {
            $result['capcode'] = $post->capcode;
        }

        // Country code
        if ($post->country_code) {
            $result['country'] = $post->country_code;
            $result['country_name'] = $post->country_name ?: $this->countryName($post->country_code);
        }

        // Poster ID (per-thread unique identifier)
        if ($post->poster_id) {
            $result['id'] = $post->poster_id;
        }

        // Subject (OP only in 4chan, but we include if present)
        if ($post->subject) {
            $result['sub'] = $post->subject;
        }

        // Comment (HTML)
        if ($post->content_html) {
            $result['com'] = $post->content_html;
        }

        // Attachment fields
        if ($post->media_url) {
            // tim: Unix timestamp + microtime (as integer)
            $result['tim'] = $this->generateTim($post);

            // Filename (without extension)
            $filename = $post->media_filename ?: 'file';
            $ext = $this->extractExtension($filename, $post->media_url);
            $result['filename'] = pathinfo($filename, PATHINFO_FILENAME) ?: 'file';
            $result['ext'] = '.' . $ext;
            $result['fsize'] = $post->media_size ?? 0;

            // MD5 hash
            if ($post->media_hash) {
                $result['md5'] = $post->media_hash;
            }

            // Dimensions
            $dims = $this->parseDimensions($post->media_dimensions);
            $result['w'] = $dims['w'];
            $result['h'] = $dims['h'];
            $result['tn_w'] = $dims['tn_w'];
            $result['tn_h'] = $dims['tn_h'];

            // Spoiler
            if ($post->spoiler_image) {
                $result['spoiler'] = 1;
            }
        }

        // Semantic URL (OP only)
        if ($isOp) {
            $result['semantic_url'] = $this->generateSemanticUrl($post->subject ?: $post->content_preview ?? '');
        }

        return $result;
    }

    /* ──────────────────────────────────────────────
     * Board → 4chan format transformer
     * ────────────────────────────────────────────── */

    /**
     * @return array<string, mixed>
     */
    private function formatBoard(Board $board): array
    {
        $result = [
            'board'             => $board->slug,
            'title'             => $board->title ?: $board->slug,
            'ws_board'          => $board->nsfw ? 0 : 1,
            'per_page'          => self::DEFAULT_PER_PAGE,
            'pages'             => self::DEFAULT_MAX_PAGES,
            'max_filesize'      => 4194304,  // 4MB default
            'max_webm_filesize' => 3145728,  // 3MB default
            'max_comment_chars' => 2000,
            'max_webm_duration' => 120,
            'bump_limit'        => $board->bump_limit ?: 300,
            'image_limit'       => $board->image_limit ?: 150,
            'cooldowns'         => [
                'threads' => $board->cooldown_seconds ?: 60,
                'replies' => max(15, (int) (($board->cooldown_seconds ?: 60) / 4)),
                'images'  => max(15, (int) (($board->cooldown_seconds ?: 60) / 4)),
            ],
            'meta_description'  => "&quot;/{$board->slug}/ - " . htmlspecialchars($board->title ?: $board->slug) . "&quot; is a board on ashchan.",
        ];

        // is_archived
        if (!$board->archived) {
            // Board supports archiving (not itself archived)
            $result['is_archived'] = 1;
        }

        // text_only
        if ($board->text_only) {
            $result['text_only'] = 1;
        }

        // require_subject
        if ($board->require_subject) {
            $result['require_subject'] = 1;
        }

        // forced_anon — not currently in model, skip unless added

        // user_ids — poster IDs enabled on this board
        if ($board->user_ids) {
            $result['user_ids'] = 1;
        }

        // country_flags — country flags enabled on this board
        if ($board->country_flags) {
            $result['country_flags'] = 1;
        }

        return $result;
    }

    /* ──────────────────────────────────────────────
     * Helper methods
     * ────────────────────────────────────────────── */

    /**
     * Format datetime in 4chan's MM/DD/YY(Day)HH:MM:SS format.
     */
    private function format4chanTime(mixed $datetime): string
    {
        if (!$datetime) {
            return '';
        }
        try {
            if ($datetime instanceof \DateTimeInterface) {
                $dt = $datetime;
            } else {
                $str = is_scalar($datetime) ? (string) $datetime : '';
                $dt = new \DateTimeImmutable($str);
            }
            $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            return $dt->format('m/d/y')
                . '(' . $days[(int) $dt->format('w')] . ')'
                . $dt->format('H:i:s');
        } catch (\Exception $e) {
            return '';
        }
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

    /**
     * Generate tim value: Unix timestamp + microtime as integer (like 1546293948883).
     */
    private function generateTim(Post $post): int
    {
        $timestamp = $this->toTimestamp($post->created_at);
        // Append milliseconds from post ID to create unique tim
        $micro = $post->id % 1000;
        return (int) ($timestamp * 1000 + $micro);
    }

    /**
     * Extract file extension from filename or URL.
     */
    private function extractExtension(string $filename, ?string $url): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if ($ext) {
            return strtolower($ext);
        }
        if ($url) {
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path)) {
                $ext = pathinfo($path, PATHINFO_EXTENSION);
                if ($ext) {
                    return strtolower($ext);
                }
            }
        }
        return 'jpg'; // Default
    }

    /**
     * Parse media_dimensions string (e.g. "800x600") into w, h, tn_w, tn_h.
     *
     * @return array{w: int, h: int, tn_w: int, tn_h: int}
     */
    private function parseDimensions(?string $dimensions): array
    {
        $w = 0;
        $h = 0;
        if ($dimensions && preg_match('/^(\d+)x(\d+)$/', $dimensions, $m)) {
            $w = (int) $m[1];
            $h = (int) $m[2];
        }

        // Calculate thumbnail dimensions (max 250x250 maintaining aspect ratio)
        $tnW = $w;
        $tnH = $h;
        if ($w > 0 && $h > 0) {
            $maxTn = 250;
            if ($w > $maxTn || $h > $maxTn) {
                $ratio = min($maxTn / $w, $maxTn / $h);
                $tnW = (int) round($w * $ratio);
                $tnH = (int) round($h * $ratio);
            }
        }

        return ['w' => $w, 'h' => $h, 'tn_w' => $tnW, 'tn_h' => $tnH];
    }

    /**
     * Generate SEO-friendly semantic URL from subject or content.
     */
    private function generateSemanticUrl(string $text): string
    {
        $text = strip_tags($text);
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = (string) preg_replace('/[\s-]+/', '-', $text);
        $text = trim($text, '-');
        return mb_substr($text, 0, 80) ?: 'untitled';
    }

    /**
     * Get unique IP count for a thread.
     *
     * Since IP addresses are encrypted with unique nonces, counting DISTINCT
     * ip_address would always equal the total post count. Instead, use
     * poster_id (deterministic per IP+thread+day) when available.
     */
    private function getUniqueIpCount(int $threadId): int
    {
        // poster_id is a deterministic hash of IP per thread; DISTINCT gives true unique posters
        $distinctPosters = (int) Post::query()
            ->where('thread_id', $threadId)
            ->where('deleted', false)
            ->whereNotNull('poster_id')
            ->where('poster_id', '!=', '')
            ->distinct()
            ->count('poster_id');

        if ($distinctPosters > 0) {
            return $distinctPosters;
        }

        // Fallback: count all non-deleted posts (upper bound; boards without poster IDs)
        return (int) Post::query()
            ->where('thread_id', $threadId)
            ->where('deleted', false)
            ->count();
    }

    /**
     * Remove null values from array — 4chan API omits unset fields rather than sending null.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function removeNulls(array $data): array
    {
        return array_filter($data, fn($v) => $v !== null);
    }

    /**
     * Map ISO country code to country name.
     */
    private function countryName(string $code): string
    {
        $countries = [
            'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada',
            'AU' => 'Australia', 'DE' => 'Germany', 'FR' => 'France',
            'JP' => 'Japan', 'BR' => 'Brazil', 'IN' => 'India',
            'IT' => 'Italy', 'ES' => 'Spain', 'NL' => 'Netherlands',
            'SE' => 'Sweden', 'NO' => 'Norway', 'FI' => 'Finland',
            'DK' => 'Denmark', 'PL' => 'Poland', 'RU' => 'Russia',
            'KR' => 'South Korea', 'MX' => 'Mexico', 'AR' => 'Argentina',
            'CL' => 'Chile', 'CO' => 'Colombia', 'PE' => 'Peru',
            'NZ' => 'New Zealand', 'IE' => 'Ireland', 'AT' => 'Austria',
            'CH' => 'Switzerland', 'BE' => 'Belgium', 'PT' => 'Portugal',
            'CZ' => 'Czech Republic', 'HU' => 'Hungary', 'RO' => 'Romania',
            'BG' => 'Bulgaria', 'GR' => 'Greece', 'TR' => 'Turkey',
            'IL' => 'Israel', 'SA' => 'Saudi Arabia', 'AE' => 'United Arab Emirates',
            'SG' => 'Singapore', 'MY' => 'Malaysia', 'TH' => 'Thailand',
            'ID' => 'Indonesia', 'PH' => 'Philippines', 'VN' => 'Vietnam',
            'TW' => 'Taiwan', 'HK' => 'Hong Kong', 'CN' => 'China',
            'ZA' => 'South Africa', 'EG' => 'Egypt', 'NG' => 'Nigeria',
            'KE' => 'Kenya', 'UA' => 'Ukraine', 'HR' => 'Croatia',
            'RS' => 'Serbia', 'SK' => 'Slovakia', 'SI' => 'Slovenia',
            'LT' => 'Lithuania', 'LV' => 'Latvia', 'EE' => 'Estonia',
            'XX' => 'Unknown',
        ];
        return $countries[$code] ?? $code;
    }
}
