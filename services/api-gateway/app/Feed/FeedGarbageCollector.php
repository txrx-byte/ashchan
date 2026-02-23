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
 * Evicts idle ThreadFeed instances to prevent memory leaks.
 *
 * This is NOT a Swoole Process — it runs as a Swoole Timer within each
 * worker, inspecting worker-local feeds every 60 seconds. A feed is
 * considered idle when it has zero clients for more than 5 minutes.
 *
 * Phase 2 will extend this to also force-close expired open posts
 * (15-minute timeout) and log memory usage per worker.
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
     * Perform a single GC sweep: evict idle feeds.
     */
    private function sweep(): void
    {
        $now = time();
        $evicted = 0;

        foreach ($this->feedManager->getAllFeeds() as $threadId => $feed) {
            $idleSince = $feed->idleSince();
            if ($idleSince > 0 && ($now - $idleSince) >= self::IDLE_THRESHOLD_SECONDS) {
                $this->feedManager->remove($threadId);
                $evicted++;
            }
        }

        if ($evicted > 0) {
            $this->logger->info('FeedGC sweep completed', [
                'evicted'     => $evicted,
                'remaining'   => count($this->feedManager->getAllFeeds()),
                'memory_bytes' => memory_get_usage(true),
            ]);
        }
    }
}
