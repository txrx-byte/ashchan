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

namespace App\Feed;

/**
 * In-memory cache of recent post states for a thread.
 *
 * Holds the last 16 minutes of post data for instant sync —
 * newly-connecting clients receive this snapshot instead of hitting
 * the database. Analogous to meguca's threadCache.
 *
 * Posts older than the cache window are evicted. This cache is
 * worker-local and does not persist across restarts.
 *
 * @see docs/LIVEPOSTING.md §9.1
 */
final class FeedCache
{
    /** Cache window in seconds (16 minutes, matching meguca). */
    private const CACHE_WINDOW_SECONDS = 960;

    /**
     * Cached post data: postId → {post data + cached_at timestamp}.
     *
     * @var array<int, array{post: array<string, mixed>, cached_at: int}>
     */
    private array $posts = [];

    /**
     * Add or update a post in the cache.
     *
     * @param int $postId
     * @param array<string, mixed> $postData
     */
    public function set(int $postId, array $postData): void
    {
        $this->posts[$postId] = [
            'post'      => $postData,
            'cached_at' => time(),
        ];
    }

    /**
     * Get a cached post by ID.
     *
     * @return array<string, mixed>|null
     */
    public function get(int $postId): ?array
    {
        $entry = $this->posts[$postId] ?? null;
        if ($entry === null) {
            return null;
        }
        return $entry['post'];
    }

    /**
     * Remove a post from the cache.
     */
    public function remove(int $postId): void
    {
        unset($this->posts[$postId]);
    }

    /**
     * Get all cached posts (within the cache window).
     *
     * Automatically evicts stale entries.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        $this->evictStale();

        $result = [];
        foreach ($this->posts as $postId => $entry) {
            $result[$postId] = $entry['post'];
        }
        return $result;
    }

    /**
     * Number of cached posts.
     */
    public function count(): int
    {
        return count($this->posts);
    }

    /**
     * Clear all cached posts.
     */
    public function clear(): void
    {
        $this->posts = [];
    }

    /**
     * Evict posts older than the cache window.
     */
    private function evictStale(): void
    {
        $cutoff = time() - self::CACHE_WINDOW_SECONDS;
        foreach ($this->posts as $postId => $entry) {
            if ($entry['cached_at'] < $cutoff) {
                unset($this->posts[$postId]);
            }
        }
    }
}
