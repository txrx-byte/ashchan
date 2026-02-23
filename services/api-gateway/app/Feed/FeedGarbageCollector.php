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

use App\WebSocket\BinaryProtocol;
use App\WebSocket\ClientConnection;
use App\Service\ProxyClient;

/**
 * Evicts idle ThreadFeed instances and force-closes expired open posts.
 *
 * This is NOT a Swoole Process — it runs as a Swoole Timer within each
 * worker, inspecting worker-local feeds every 60 seconds.
 *
 * Two sweep duties:
 * 1. Evict feeds with zero clients for more than 5 minutes.
 * 2. Force-close open posts that exceed the 15-minute lifetime limit.
 *
 * @see docs/LIVEPOSTING.md §14.2
 */
final class FeedGarbageCollector
{
    /** How often to run the GC sweep (in milliseconds). */
    private const SWEEP_INTERVAL_MS = 60_000;

    /** How long a feed can be idle (no clients) before eviction (in seconds). */
    private const IDLE_THRESHOLD_SECONDS = 300;

    /** Swoole Timer ID. */
    private int $timerId = 0;

    public function __construct(
        private readonly ThreadFeedManager $feedManager,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly ?ProxyClient $proxyClient = null,
    ) {
    }

    /**
     * Start the periodic GC sweep.
     */
    public function start(): void
    {
        $this->timerId = \Swoole\Timer::tick(self::SWEEP_INTERVAL_MS, function (): void {
            $this->sweep();
        });

        $this->logger->debug('FeedGarbageCollector started', [
            'interval_ms' => self::SWEEP_INTERVAL_MS,
            'idle_threshold_s' => self::IDLE_THRESHOLD_SECONDS,
        ]);
    }

    /**
     * Stop the GC sweep.
     */
    public function stop(): void
    {
        if ($this->timerId > 0) {
            \Swoole\Timer::clear($this->timerId);
            $this->timerId = 0;
        }
    }

    /**
     * Perform a single GC sweep: evict idle feeds and force-close expired open posts.
     */
    private function sweep(): void
    {
        $now = time();
        $evicted = 0;
        $closedPosts = 0;

        foreach ($this->feedManager->getAllFeeds() as $threadId => $feed) {
            // 1. Force-close expired open posts (15-minute timeout)
            $closedPosts += $this->closeExpiredPosts($feed);

            // 2. Evict fully idle feeds (no clients for > 5 minutes)
            $idleSince = $feed->idleSince();
            if ($idleSince > 0 && ($now - $idleSince) >= self::IDLE_THRESHOLD_SECONDS) {
                $this->feedManager->remove($threadId);
                $evicted++;
            }
        }

        if ($evicted > 0 || $closedPosts > 0) {
            $this->logger->info('FeedGC sweep completed', [
                'evicted'      => $evicted,
                'closed_posts' => $closedPosts,
                'remaining'    => count($this->feedManager->getAllFeeds()),
                'memory_bytes' => memory_get_usage(true),
            ]);
        }
    }

    /**
     * Force-close all expired open posts in a feed.
     *
     * Calls the boards-threads-posts service to finalize each expired post,
     * clears the OpenPost state on the client connection, and broadcasts
     * ClosePost (type 05) to the feed.
     *
     * @return int Number of posts force-closed
     */
    private function closeExpiredPosts(ThreadFeed $feed): int
    {
        $expired = $feed->getExpiredOpenPosts();
        if (count($expired) === 0) {
            return 0;
        }

        $closed = 0;
        foreach ($expired as $fd => $conn) {
            $openPost = $conn->openPost;
            if ($openPost === null) {
                continue;
            }

            $postId = $openPost->postId;
            $threadId = $openPost->threadId;

            // Call boards-threads-posts to finalize the post
            $contentHtml = '';
            if ($this->proxyClient !== null) {
                $response = $this->proxyClient->forward(
                    'boards',
                    'POST',
                    "/api/v1/posts/{$postId}/close",
                    ['Content-Type' => 'application/json'],
                    '',
                );

                if ($response['status'] === 200) {
                    $result = json_decode((string) $response['body'], true);
                    $contentHtml = $result['content_html'] ?? '';
                } else {
                    $this->logger->warning('Expired post close failed', [
                        'post_id' => $postId,
                        'status'  => $response['status'],
                    ]);
                }
            }

            // Clear open post state
            $conn->openPost = null;

            // Broadcast ClosePost (type 05)
            $closeMsg = BinaryProtocol::encodeTextMessage(5, [
                'id'           => $postId,
                'content_html' => $contentHtml,
            ]);
            $feed->queueTextMessage($closeMsg);
            $feed->removeOpenBody($postId);

            $this->logger->info('Expired post force-closed by GC', [
                'post_id'   => $postId,
                'thread_id' => $threadId,
                'fd'        => $fd,
            ]);

            $closed++;
        }

        return $closed;
    }
}
