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

use Hyperf\Redis\Redis;

/**
 * In-memory search using Redis sorted sets.
 * For production, replace with Meilisearch/Elasticsearch.
 *
 * Stores a search index of post/thread content keyed by board.
 * Supports prefix and full-text substring matching via Redis SCAN.
 */
final class SearchService
{
    private const INDEX_PREFIX = 'search:';
    private const TTL          = 86400 * 7; // 7 days

    public function __construct(
        private Redis $redis,
    ) {}

    /**
     * Index a post for search.
     */
    public function indexPost(string $boardSlug, int $threadId, int $postId, string $content, ?string $subject = null): void
    {
        $text = mb_strtolower(trim(($subject ? $subject . ' ' : '') . strip_tags($content)));
        if (mb_strlen($text) < 3) return;

        $key = self::INDEX_PREFIX . $boardSlug;
        $doc = json_encode([
            'thread_id' => $threadId,
            'post_id'   => $postId,
            'text'      => mb_substr($text, 0, 500),
        ]);

        $this->redis->hSet($key, (string) $postId, $doc);
        $this->redis->expire($key, self::TTL);
    }

    /**
     * Remove a post from the index.
     */
    public function removePost(string $boardSlug, int $postId): void
    {
        $key = self::INDEX_PREFIX . $boardSlug;
        $this->redis->hDel($key, (string) $postId);
    }

    /**
     * Search posts on a board.
     *
     * @return array<string, mixed>
     */
    public function search(string $boardSlug, string $query, int $page = 1, int $perPage = 25): array
    {
        $key   = self::INDEX_PREFIX . $boardSlug;
        $query = mb_strtolower(trim($query));

        if (mb_strlen($query) < 2) {
            return ['results' => [], 'total' => 0];
        }

        $all = (array) $this->redis->hGetAll($key);
        $results = [];

        foreach ($all as $postId => $docJson) {
            if (!is_string($docJson)) continue;
            $doc = json_decode($docJson, true);
            if (!is_array($doc) || !isset($doc['text'], $doc['thread_id'])) continue;
            $textVal = $doc['text'];
            $text = is_string($textVal) ? $textVal : '';
            if (str_contains($text, $query)) {
                $results[] = [
                    'post_id'   => (int) $postId,
                    'thread_id' => $doc['thread_id'],
                    'excerpt'   => $this->highlight($text, $query),
                ];
            }
        }

        $total = count($results);
        $paged = array_slice($results, ($page - 1) * $perPage, $perPage);

        return [
            'results'     => $paged,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Search across all boards.
     * @return array<string, mixed>
     */
    public function searchAll(string $query, int $page = 1, int $perPage = 25): array
    {
        $cursor  = '0';
        $allResults = [];

        $keys = [];
        do {
            $iter = $this->redis->scan($cursor, self::INDEX_PREFIX . '*', 100);
            if ($iter !== false) {
                $keys = array_merge($keys, (array) $iter);
            }
        } while ($cursor !== null && is_numeric($cursor) && (int)$cursor > 0);

        foreach ($keys as $key) {
            if (!is_string($key)) continue;
            $boardSlug = str_replace(self::INDEX_PREFIX, '', $key);
            $boardResults = $this->search($boardSlug, $query, 1, 1000);
            if (isset($boardResults['results']) && is_array($boardResults['results'])) {
                foreach ($boardResults['results'] as $r) {
                    if (is_array($r)) {
                        $r['board'] = $boardSlug;
                        $allResults[] = $r;
                    }
                }
            }
        }

        $total = count($allResults);
        $paged = array_slice($allResults, ($page - 1) * $perPage, $perPage);

        return [
            'results'     => $paged,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    private function highlight(string $text, string $query): string
    {
        $excerpt = mb_substr($text, 0, 200);
        $pos = mb_strpos($excerpt, $query);
        if ($pos !== false) {
            $start = max(0, $pos - 40);
            $excerpt = ($start > 0 ? '…' : '') . mb_substr($text, $start, 150) . '…';
        }
        return str_replace(
            $query,
            "<mark>{$query}</mark>",
            htmlspecialchars($excerpt, ENT_QUOTES)
        );
    }
}
