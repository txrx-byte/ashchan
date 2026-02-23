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
use Psr\Log\LoggerInterface;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Server as WsServer;
use Swoole\Timer;

/**
 * Per-thread state manager and message broadcaster.
 *
 * Each active thread with at least one WebSocket client gets a ThreadFeed.
 * The feed manages:
 * - A set of subscribed client fds
 * - An in-memory cache of open post bodies (for instant sync)
 * - A message buffer that flushes every 100ms (batched text messages)
 * - Direct binary broadcasting (no buffering, for keystroke streaming)
 *
 * Analogous to meguca's websockets/feeds/feed.go goroutine.
 *
 * @see docs/LIVEPOSTING.md §5.5
 */
final class ThreadFeed
{
    /** Flush interval in milliseconds. */
    private const TICK_INTERVAL_MS = 100;

    /** @var array<int, ClientConnection> fd → connection */
    private array $clients = [];

    /** @var array<int, string> postId → current body text */
    private array $openPostBodies = [];

    /** @var array<int, array<string, mixed>> postId → recent post data for sync */
    private array $recentPosts = [];

    /** Text message buffer for batched flushing. */
    private MessageBuffer $messageBuffer;

    /** Swoole Timer ID for the 100ms flush ticker (0 = not running). */
    private int $timerId = 0;

    /** Guard flag to prevent timer callback stacking. */
    private bool $flushing = false;

    /** Unix timestamp when the last client was removed (for GC). */
    private int $idleSince = 0;

    /** Unix timestamp when this feed was created. */
    private readonly int $createdAt;

    public function __construct(
        private readonly int $threadId,
        private readonly WsServer $server,
        private readonly LoggerInterface $logger,
    ) {
        $this->messageBuffer = new MessageBuffer();
        $this->createdAt = time();
    }

    /**
     * Get the thread ID this feed serves.
     */
    public function getThreadId(): int
    {
        return $this->threadId;
    }

    /**
     * Add a client to this feed.
     */
    public function addClient(int $fd, ClientConnection $conn): void
    {
        $this->clients[$fd] = $conn;
        $this->idleSince = 0;
    }

    /**
     * Remove a client from this feed.
     *
     * @return bool True if the feed is now empty (candidate for GC)
     */
    public function removeClient(int $fd): bool
    {
        unset($this->clients[$fd]);

        if ($this->clients === []) {
            $this->idleSince = time();
            return true;
        }

        return false;
    }

    /**
     * Number of connected clients.
     */
    public function clientCount(): int
    {
        return count($this->clients);
    }

    /**
     * Unix timestamp when the feed became idle (no clients), 0 if active.
     */
    public function idleSince(): int
    {
        return $this->idleSince;
    }

    /**
     * Broadcast a binary frame to all clients in this feed (immediate, no buffering).
     *
     * Used for hot-path messages: append, backspace, splice.
     */
    public function broadcastBinary(string $data): void
    {
        foreach ($this->clients as $fd => $conn) {
            if ($this->server->isEstablished($fd)) {
                $this->server->push($fd, $data, WEBSOCKET_OPCODE_BINARY);
            } else {
                // Connection closed between check and push — clean up
                unset($this->clients[$fd]);
            }
        }
    }

    /**
     * Broadcast a text frame to all clients (immediate).
     *
     * Used for sync responses and concat frames.
     */
    public function broadcastText(string $data): void
    {
        foreach ($this->clients as $fd => $conn) {
            if ($this->server->isEstablished($fd)) {
                $this->server->push($fd, $data, WEBSOCKET_OPCODE_TEXT);
            } else {
                unset($this->clients[$fd]);
            }
        }
    }

    /**
     * Send a text message to a specific client.
     */
    public function sendToClient(int $fd, string $data, int $opcode = WEBSOCKET_OPCODE_TEXT): void
    {
        if ($this->server->isEstablished($fd)) {
            $this->server->push($fd, $data, $opcode);
        }
    }

    /**
     * Queue a text message for batched flushing (via 100ms ticker).
     *
     * If the ticker is not running, starts it. Text messages that are not
     * time-critical (e.g., InsertPost, ClosePost) are batched to reduce
     * the number of WebSocket frames sent.
     */
    public function queueTextMessage(string $message): void
    {
        $this->messageBuffer->push($message);
        $this->ensureTickerRunning();
    }

    /**
     * Update the cached body of an open post.
     *
     * This in-memory cache is used to serve instant sync to newly-connecting
     * clients without hitting the database.
     */
    public function updateOpenBody(int $postId, string $body): void
    {
        $this->openPostBodies[$postId] = $body;
    }

    /**
     * Remove an open post body from the cache (when post is closed or expired).
     */
    public function removeOpenBody(int $postId): void
    {
        unset($this->openPostBodies[$postId]);
    }

    /**
     * Get all open post bodies for sync purposes.
     *
     * @return array<int, string> postId → body
     */
    public function getOpenPostBodies(): array
    {
        return $this->openPostBodies;
    }

    /**
     * Add a recent post to the sync cache.
     *
     * @param int $postId
     * @param array<string, mixed> $postData
     */
    public function addRecentPost(int $postId, array $postData): void
    {
        $this->recentPosts[$postId] = $postData;
    }

    /**
     * Remove a recent post from the sync cache.
     */
    public function removeRecentPost(int $postId): void
    {
        unset($this->recentPosts[$postId]);
    }

    /**
     * Build the sync message for a newly-connecting client.
     *
     * Returns the state of all open posts and recent posts so the client
     * can reconcile its local DOM state.
     *
     * @return array{open_posts: array<int, array{id: int, body: string}>, active_ips: int, client_count: int}
     */
    public function getSyncState(): array
    {
        $openPosts = [];
        foreach ($this->openPostBodies as $postId => $body) {
            $openPosts[$postId] = [
                'id'   => $postId,
                'body' => $body,
            ];
        }

        return [
            'open_posts'   => array_values($openPosts),
            'active_ips'   => $this->getActiveIpCount(),
            'client_count' => $this->clientCount(),
        ];
    }

    /**
     * Count unique IP addresses connected to this feed.
     */
    public function getActiveIpCount(): int
    {
        $ips = [];
        foreach ($this->clients as $conn) {
            $ips[$conn->ip] = true;
        }
        return count($ips);
    }

    /**
     * Destroy this feed: clear all timers, buffers, and caches.
     *
     * Must be called before discarding the feed to prevent memory leaks
     * from lingering timer callbacks and SplQueue references.
     */
    public function destroy(): void
    {
        if ($this->timerId > 0) {
            Timer::clear($this->timerId);
            $this->timerId = 0;
        }

        // Explicit drain to release SplQueue references
        $this->messageBuffer->clear();

        $this->clients = [];
        $this->openPostBodies = [];
        $this->recentPosts = [];

        $this->logger->debug('ThreadFeed destroyed', ['thread_id' => $this->threadId]);
    }

    /**
     * Ensure the 100ms flush ticker is running.
     */
    private function ensureTickerRunning(): void
    {
        if ($this->timerId > 0) {
            return;
        }

        $this->timerId = Timer::tick(self::TICK_INTERVAL_MS, function (): void {
            $this->flush();
        });
    }

    /**
     * Flush the message buffer to all clients.
     *
     * Uses a $flushing guard flag to prevent timer callback stacking
     * if flush() takes longer than 100ms (see §14.3 risk mitigation).
     */
    private function flush(): void
    {
        if ($this->flushing) {
            return; // Previous flush still in progress — skip this tick
        }

        if ($this->messageBuffer->isEmpty()) {
            // No messages to flush — stop the ticker to save CPU
            if ($this->timerId > 0) {
                Timer::clear($this->timerId);
                $this->timerId = 0;
            }
            return;
        }

        $this->flushing = true;
        try {
            $messages = $this->messageBuffer->drain();

            if (count($messages) === 1) {
                // Single message — send directly without concat wrapper
                $this->broadcastText($messages[0]);
            } else {
                // Multiple messages — encode as MessageConcat (type 33)
                $this->broadcastText(BinaryProtocol::encodeConcat($messages));
            }
        } catch (\Throwable $e) {
            $this->logger->error('ThreadFeed flush error', [
                'thread_id' => $this->threadId,
                'error'     => $e->getMessage(),
            ]);
        } finally {
            $this->flushing = false;
        }
    }
}
