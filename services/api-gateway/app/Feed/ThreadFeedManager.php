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

use App\Service\ProxyClient;
use App\WebSocket\ClientConnection;
use Psr\Log\LoggerInterface;
use Swoole\WebSocket\Server as WsServer;

/**
 * Registry of active per-thread feeds.
 *
 * Each Swoole worker maintains its own ThreadFeed objects (since WebSocket
 * fd's are worker-local). Phase 1 uses worker-local arrays for connection
 * tracking and IP counting. Phase 2 will add Swoole Tables (created before
 * worker fork) for cross-worker visibility and broadcasting.
 *
 * Analogous to meguca's websockets/feeds/feeds.go feed registry.
 *
 * @see docs/LIVEPOSTING.md §5.4
 */
final class ThreadFeedManager
{
    /**
     * Worker-local feeds: threadId → ThreadFeed.
     *
     * @var array<int, ThreadFeed>
     */
    private array $feeds = [];

    /**
     * Worker-local IP connection counts: ipHash → count.
     *
     * Phase 1: per-worker counting (effective limit = maxPerIp × workerNum).
     * Phase 2: Swoole Table for global cross-worker counting.
     *
     * @var array<string, int>
     */
    private array $ipCounts = [];

    /**
     * Worker-local connection metadata: fd → {ip, thread_id, connected_at}.
     *
     * @var array<int, array{ip: string, thread_id: int, board: string, connected_at: int}>
     */
    private array $connections = [];

    /**
     * Maximum concurrent WebSocket connections per IP address (per worker).
     */
    private readonly int $maxConnectionsPerIp;

    public function __construct(
        private readonly WsServer $server,
        private readonly LoggerInterface $logger,
        private readonly int $workerId,
        private readonly ?ProxyClient $proxyClient = null,
    ) {
        $this->maxConnectionsPerIp = (int) (getenv('WS_MAX_CONNECTIONS_PER_IP') ?: 16);
    }

    /**
     * Get or create a ThreadFeed for a given thread.
     */
    public function getOrCreate(int $threadId): ThreadFeed
    {
        if (!isset($this->feeds[$threadId])) {
            $feed = new ThreadFeed($threadId, $this->server, $this->logger, $this->proxyClient);
            $this->feeds[$threadId] = $feed;

            $this->logger->debug('ThreadFeed created', [
                'thread_id' => $threadId,
                'worker_id' => $this->workerId,
            ]);
        }

        return $this->feeds[$threadId];
    }

    /**
     * Get a feed if it exists, null otherwise.
     */
    public function getFeed(int $threadId): ?ThreadFeed
    {
        return $this->feeds[$threadId] ?? null;
    }

    /**
     * Remove a thread feed (when it has no more clients).
     */
    public function remove(int $threadId): void
    {
        if (isset($this->feeds[$threadId])) {
            $this->feeds[$threadId]->destroy();
            unset($this->feeds[$threadId]);

            $this->logger->debug('ThreadFeed removed', [
                'thread_id' => $threadId,
                'worker_id' => $this->workerId,
            ]);
        }
    }

    /**
     * Register a new WebSocket connection.
     *
     * @return bool False if the IP has exceeded its connection limit
     */
    public function registerConnection(int $fd, ClientConnection $conn): bool
    {
        // Check IP connection limit (per-worker in Phase 1)
        $ipHash = $this->hashIp($conn->ip);
        $currentCount = $this->ipCounts[$ipHash] ?? 0;

        if ($currentCount >= $this->maxConnectionsPerIp) {
            $this->logger->warning('IP connection limit exceeded', [
                'ip'    => $conn->ip,
                'count' => $currentCount,
                'limit' => $this->maxConnectionsPerIp,
            ]);
            return false;
        }

        // Increment IP counter
        $this->ipCounts[$ipHash] = $currentCount + 1;

        // Store connection metadata
        $this->connections[$fd] = [
            'ip'           => $conn->ip,
            'thread_id'    => 0,
            'board'        => '',
            'connected_at' => $conn->connectedAt,
        ];

        return true;
    }

    /**
     * Unregister a WebSocket connection.
     */
    public function unregisterConnection(int $fd, string $ip): void
    {
        unset($this->connections[$fd]);

        // Decrement IP counter
        $ipHash = $this->hashIp($ip);
        $currentCount = $this->ipCounts[$ipHash] ?? 0;
        if ($currentCount <= 1) {
            unset($this->ipCounts[$ipHash]);
        } else {
            $this->ipCounts[$ipHash] = $currentCount - 1;
        }
    }

    /**
     * Subscribe a client to a thread feed.
     */
    public function subscribe(int $fd, ClientConnection $conn, int $threadId): ThreadFeed
    {
        $feed = $this->getOrCreate($threadId);
        $feed->addClient($fd, $conn);

        // Update connection metadata
        if (isset($this->connections[$fd])) {
            $this->connections[$fd]['thread_id'] = $threadId;
            $this->connections[$fd]['board'] = $conn->board ?? '';
        }

        return $feed;
    }

    /**
     * Unsubscribe a client from its current thread feed.
     */
    public function unsubscribe(int $fd, ?int $threadId): void
    {
        if ($threadId === null) {
            return;
        }

        $feed = $this->getFeed($threadId);
        if ($feed === null) {
            return;
        }

        $isEmpty = $feed->removeClient($fd);

        // Don't remove the feed immediately — let the GC process handle it
        // This prevents thrashing when clients briefly disconnect and reconnect
        if ($isEmpty) {
            $this->logger->debug('ThreadFeed now empty, eligible for GC', [
                'thread_id' => $threadId,
                'worker_id' => $this->workerId,
            ]);
        }
    }

    /**
     * Get all worker-local feeds (for GC inspection).
     *
     * @return array<int, ThreadFeed>
     */
    public function getAllFeeds(): array
    {
        return $this->feeds;
    }

    /**
     * Get the total number of active connections on this worker.
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Get the number of unique IPs tracked on this worker.
     */
    public function getIpCount(): int
    {
        return count($this->ipCounts);
    }

    /**
     * Get metrics for the health endpoint.
     *
     * @return array<string, int>
     */
    public function getMetrics(): array
    {
        return [
            'worker_id'         => $this->workerId,
            'feeds'             => count($this->feeds),
            'connections'       => count($this->connections),
            'unique_ips'        => count($this->ipCounts),
        ];
    }

    /**
     * Hash an IP address for use as an array key.
     */
    private function hashIp(string $ip): string
    {
        return hash('xxh3', $ip);
    }
}
