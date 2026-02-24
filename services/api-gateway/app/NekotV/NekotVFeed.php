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

namespace App\NekotV;

use App\WebSocket\ClientConnection;
use App\WebSocket\PipeMessage;
use Psr\Log\LoggerInterface;
use Swoole\Timer;
use Swoole\WebSocket\Server as WsServer;

/**
 * Per-thread NekotV synchronized video feed.
 *
 * Each thread can have one NekotVFeed that manages a shared playlist,
 * server-authoritative playback timer, and set of subscribed clients.
 * A 1-second Swoole Timer broadcasts time sync events and handles
 * auto-skip when a video finishes.
 *
 * Port of meguca's websockets/feeds/neko_tv.go NekoTVFeed.
 * Uses Swoole Timers instead of Go channels + goroutines.
 *
 * Lifecycle:
 * 1. Created lazily when the first client subscribes to a thread's NekotV
 * 2. Tick every 1s: broadcast time sync, detect video end → auto-skip
 * 3. Destroyed when the last client unsubscribes
 *
 * @see meguca/websockets/feeds/neko_tv.go
 * @see docs/LIVEPOSTING.md (analogous architecture)
 */
final class NekotVFeed
{
    /** Binary message type byte for NekotV frames. */
    public const MESSAGE_TYPE = 0x10;

    /** Sync timer interval in milliseconds. */
    private const SYNC_INTERVAL_MS = 1000;

    /** Seconds before video end to trigger auto-skip detection. */
    private const END_THRESHOLD = 0.01;

    /** Delay before auto-skip executes (milliseconds). */
    private const AUTO_SKIP_DELAY_MS = 1000;

    /** Redis key prefix for NekotV state persistence. */
    private const REDIS_KEY_PREFIX = 'nekotv:state:';

    /** Redis TTL for persisted state (24 hours). */
    private const REDIS_TTL = 86400;

    /** Debounce interval for Redis writes (milliseconds). */
    private const STATE_WRITE_DEBOUNCE_MS = 1000;

    /** @var array<int, ClientConnection> fd → connection */
    private array $clients = [];

    /** Server-authoritative playback timer. */
    private VideoTimer $videoTimer;

    /** Ordered playlist with position tracking. */
    private VideoList $videoList;

    /** Swoole Timer ID for 1-second sync ticker (0 = not running). */
    private int $syncTimerId = 0;

    /** Swoole Timer ID for debounced state writes (0 = not pending). */
    private int $stateWriteTimerId = 0;

    /** Swoole Timer ID for pending auto-skip (0 = not pending). */
    private int $autoSkipTimerId = 0;

    /** Unix timestamp when the last client was removed (for GC). */
    private int $idleSince = 0;

    /** Number of Swoole workers (for cross-worker IPC). */
    private readonly int $workerCount;

    /** Swoole Timer ID for hourly drift re-bake (0 = not running). */
    private int $rebakeTimerId = 0;

    /** Drift re-bake check interval in milliseconds (5 minutes). */
    private const REBAKE_CHECK_INTERVAL_MS = 300_000;

    public function __construct(
        private readonly int $threadId,
        private readonly WsServer $server,
        private readonly LoggerInterface $logger,
    ) {
        $this->videoTimer = new VideoTimer($threadId);
        $this->videoList = new VideoList($threadId);
        $this->workerCount = (int) ($server->setting['worker_num'] ?? 1);
    }

    /**
     * Start the feed, optionally restoring state from Redis.
     *
     * @param array<string, mixed>|null $savedState Persisted state to restore
     */
    public function start(?array $savedState = null): void
    {
        if ($savedState !== null) {
            $this->restoreState($savedState);
        }

        $this->startSyncTimer();
        $this->startReBakeTimer();

        $this->logger->info('NekotV feed started', ['thread_id' => $this->threadId]);
    }

    /**
     * Get the thread ID this feed serves.
     */
    public function getThreadId(): int
    {
        return $this->threadId;
    }

    /**
     * Get the VideoList instance (for lock toggling).
     */
    public function getVideoList(): VideoList
    {
        return $this->videoList;
    }

    // ─────────────────────────────────────────────────────────────
    //  Client management
    // ─────────────────────────────────────────────────────────────

    /**
     * Add a client and send the connected state snapshot.
     */
    public function addClient(int $fd, ClientConnection $conn): void
    {
        $this->clients[$fd] = $conn;
        $this->idleSince = 0;
        $this->sendConnectedMessage($fd);
    }

    /**
     * Remove a client from this feed.
     *
     * @return bool True if the feed is now empty (candidate for destruction)
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

    // ─────────────────────────────────────────────────────────────
    //  Video operations (called from command parser or media commands)
    // ─────────────────────────────────────────────────────────────

    /**
     * Add a video to the playlist and broadcast.
     */
    public function addVideo(VideoItem $item, bool $atEnd = true): void
    {
        // Duplicate check
        if ($this->videoList->exists(fn(VideoItem $v) => $v->url === $item->url)) {
            return;
        }

        if (!$this->videoList->addItem($item, $atEnd)) {
            return; // Playlist full
        }

        $this->broadcastEvent(NekotVEvent::addVideo($item, $atEnd));

        // Start playback if this is the first video
        if ($this->videoList->length() === 1) {
            $this->videoTimer->start();
        }

        $this->scheduleStateWrite();
    }

    /**
     * Remove a video from the playlist by URL.
     */
    public function removeVideo(string $url): void
    {
        if (!$this->videoList->isOpen()) {
            return;
        }

        $index = $this->videoList->findIndex(fn(VideoItem $v) => $v->url === $url);
        if ($index === -1) {
            return;
        }

        $this->videoList->removeItem($index);
        $this->broadcastEvent(NekotVEvent::removeVideo($url));
        $this->scheduleStateWrite();
    }

    /**
     * Skip the current video and advance to the next.
     */
    public function skipVideo(): void
    {
        if (!$this->videoList->isOpen()) {
            return;
        }
        if ($this->videoList->length() === 0) {
            return;
        }

        try {
            $currentItem = $this->videoList->currentItem();
        } catch (\RuntimeException) {
            return;
        }

        $isEmpty = $this->videoList->skipItem();

        if ($isEmpty || $this->videoList->length() === 0) {
            $this->videoTimer->stop();
        } else {
            $this->videoTimer->setTime(0);
        }

        $this->broadcastEvent(NekotVEvent::skipVideo($currentItem->url));
        $this->scheduleStateWrite();
    }

    /**
     * Pause playback.
     */
    public function pause(): void
    {
        if (!$this->videoList->isOpen()) {
            return;
        }
        if ($this->videoList->length() === 0) {
            return;
        }

        $this->videoTimer->pause();
        $this->broadcastEvent(NekotVEvent::pause($this->videoTimer->getTime()));
        $this->scheduleStateWrite();
    }

    /**
     * Resume playback.
     */
    public function play(): void
    {
        if ($this->videoList->length() === 0) {
            return;
        }

        $time = $this->videoTimer->getTime();
        $this->videoTimer->play();
        $this->broadcastEvent(NekotVEvent::play($time));
        $this->scheduleStateWrite();
    }

    /**
     * Seek to a specific time.
     */
    public function setTime(float $seconds): void
    {
        if (!$this->videoList->isOpen()) {
            return;
        }
        if ($this->videoList->length() === 0) {
            return;
        }

        try {
            $this->videoTimer->setTime($seconds);
        } catch (\InvalidArgumentException $e) {
            $this->logger->debug('NekotV invalid setTime', ['error' => $e->getMessage()]);
            return;
        }
        $this->broadcastEvent(NekotVEvent::setTime($seconds));
        $this->scheduleStateWrite();
    }

    /**
     * Set the playback rate and broadcast immediately.
     */
    public function setRate(float $rate): void
    {
        if (!$this->videoList->isOpen()) {
            return;
        }
        if ($this->videoList->length() === 0) {
            return;
        }

        try {
            $this->videoTimer->setRate($rate);
        } catch (\InvalidArgumentException $e) {
            $this->logger->debug('NekotV invalid setRate', ['error' => $e->getMessage()]);
            return;
        }
        $this->broadcastEvent(NekotVEvent::setRate($rate));
        $this->scheduleStateWrite();
    }

    /**
     * Clear the entire playlist.
     */
    public function clearPlaylist(): void
    {
        if (!$this->videoList->isOpen()) {
            return;
        }

        $this->videoList->clear();
        $this->videoTimer->stop();
        $this->broadcastEvent(NekotVEvent::clearPlaylist());
        $this->scheduleStateWrite();
    }

    /**
     * Toggle playlist lock state and broadcast.
     */
    public function toggleLock(bool $isOpen): void
    {
        $this->videoList->setOpen($isOpen);
        $this->broadcastEvent(NekotVEvent::toggleLock($isOpen));
    }

    /**
     * Whether the playlist is open (unlocked).
     */
    public function isOpen(): bool
    {
        return $this->videoList->isOpen();
    }

    // ─────────────────────────────────────────────────────────────
    //  Sync timer & auto-skip
    // ─────────────────────────────────────────────────────────────

    /**
     * Called every 1 second by the sync timer.
     *
     * Checks for video end (auto-skip) and broadcasts time sync to all clients.
     */
    private function syncVideoState(): void
    {
        try {
            $item = $this->videoList->currentItem();
        } catch (\RuntimeException) {
            return;
        }

        // Don't auto-skip live streams
        if ($item->isLive()) {
            $this->broadcastTimeSyncMessage();
            return;
        }

        $maxTime = $item->duration - self::END_THRESHOLD;

        if ($this->videoTimer->getTime() > $maxTime) {
            // Video has ended — pause and schedule auto-skip
            $this->videoTimer->pause();
            $this->videoTimer->setTime($maxTime);

            $skipUrl = $item->url;

            // Cancel any pending auto-skip
            if ($this->autoSkipTimerId > 0) {
                Timer::clear($this->autoSkipTimerId);
            }

            $this->autoSkipTimerId = Timer::after(self::AUTO_SKIP_DELAY_MS, function () use ($skipUrl): void {
                $this->autoSkipTimerId = 0;

                if ($this->videoList->length() === 0) {
                    return;
                }

                try {
                    $current = $this->videoList->currentItem();
                } catch (\RuntimeException) {
                    return;
                }

                if ($current->url !== $skipUrl) {
                    return; // Different video is now playing
                }

                $this->skipVideoInternal();
                $this->play();
            });

            return;
        }

        if ($this->videoList->length() > 0) {
            $this->broadcastTimeSyncMessage();
        }
    }

    /**
     * Internal skip (bypasses isOpen check, used for auto-skip).
     */
    private function skipVideoInternal(): void
    {
        if ($this->videoList->length() === 0) {
            return;
        }

        try {
            $currentItem = $this->videoList->currentItem();
        } catch (\RuntimeException) {
            return;
        }

        $isEmpty = $this->videoList->skipItem();

        if ($isEmpty || $this->videoList->length() === 0) {
            $this->videoTimer->stop();
        } else {
            $this->videoTimer->setTime(0);
        }

        $this->broadcastEvent(NekotVEvent::skipVideo($currentItem->url));
        $this->scheduleStateWrite();
    }

    // ─────────────────────────────────────────────────────────────
    //  Broadcasting
    // ─────────────────────────────────────────────────────────────

    /**
     * Send the full state snapshot to a newly connected client.
     */
    private function sendConnectedMessage(int $fd): void
    {
        $event = NekotVEvent::connected(
            $this->videoList->getItems(),
            $this->videoList->getPos(),
            $this->videoList->isOpen(),
            $this->videoTimer->getTimeData(),
        );

        $data = $this->encodeEvent($event);

        if ($this->server->isEstablished($fd)) {
            $this->server->push($fd, $data, WEBSOCKET_OPCODE_BINARY);
        }
    }

    /**
     * Broadcast a time sync message to all clients.
     */
    private function broadcastTimeSyncMessage(): void
    {
        $timeData = $this->videoTimer->getTimeData();
        $event = NekotVEvent::timeSync($timeData['time'], $timeData['paused'], $timeData['rate']);
        $this->broadcastEvent($event);
    }

    /**
     * Broadcast a NekotV event to all subscribed clients on all workers.
     *
     * @param array<string, mixed> $event
     */
    private function broadcastEvent(array $event): void
    {
        $data = $this->encodeEvent($event);

        // Send to local clients
        foreach ($this->clients as $fd => $conn) {
            if ($this->server->isEstablished($fd)) {
                $this->server->push($fd, $data, WEBSOCKET_OPCODE_BINARY);
            } else {
                unset($this->clients[$fd]);
            }
        }

        // Forward to other workers
        $this->sendToOtherWorkers($data);
    }

    /**
     * Broadcast to local clients only (called from pipe message handler).
     */
    public function broadcastLocal(string $data): void
    {
        foreach ($this->clients as $fd => $conn) {
            if ($this->server->isEstablished($fd)) {
                $this->server->push($fd, $data, WEBSOCKET_OPCODE_BINARY);
            } else {
                unset($this->clients[$fd]);
            }
        }
    }

    /**
     * Encode an event as a binary NekotV frame.
     *
     * Format: [JSON payload bytes][0x10]
     *
     * @param array<string, mixed> $event
     */
    private function encodeEvent(array $event): string
    {
        return json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            . chr(self::MESSAGE_TYPE);
    }

    /**
     * Forward a binary frame to all other Swoole workers via IPC.
     */
    private function sendToOtherWorkers(string $data): void
    {
        if ($this->workerCount <= 1) {
            return;
        }

        $currentWorkerId = $this->server->worker_id;
        $msg = new PipeMessage(
            PipeMessage::TYPE_NEKOTV_BROADCAST,
            $this->threadId,
            $data,
            $currentWorkerId,
        );
        $serialized = serialize($msg);

        for ($i = 0; $i < $this->workerCount; $i++) {
            if ($i === $currentWorkerId) {
                continue;
            }
            $this->server->sendMessage($serialized, $i);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  Persistence (Redis)
    // ─────────────────────────────────────────────────────────────

    /**
     * Get the full serializable state for persistence.
     *
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        return [
            'video_list' => $this->videoList->toArray(),
            'timer'      => $this->videoTimer->toArray(),
        ];
    }

    /**
     * Restore state from persisted data.
     *
     * @param array<string, mixed> $state
     */
    private function restoreState(array $state): void
    {
        if (isset($state['video_list']) && is_array($state['video_list'])) {
            $this->videoList->fromArray($state['video_list']);
        }
        if (isset($state['timer']) && is_array($state['timer'])) {
            $this->videoTimer->fromArray($state['timer']);
        }
    }

    /**
     * Get the Redis key for this feed's state.
     */
    public function getRedisKey(): string
    {
        return self::REDIS_KEY_PREFIX . $this->threadId;
    }

    /**
     * Schedule a debounced state write to Redis.
     */
    private function scheduleStateWrite(): void
    {
        if ($this->stateWriteTimerId > 0) {
            return; // Already scheduled
        }

        $this->stateWriteTimerId = Timer::after(self::STATE_WRITE_DEBOUNCE_MS, function (): void {
            $this->stateWriteTimerId = 0;
            $this->writeStateToRedis();
        });
    }

    /**
     * Persist current state to Redis.
     */
    private function writeStateToRedis(): void
    {
        try {
            $redis = $this->getRedis();
            if ($redis === null) {
                return;
            }

            if ($this->videoList->length() === 0) {
                $redis->del($this->getRedisKey());
            } else {
                $redis->setex(
                    $this->getRedisKey(),
                    self::REDIS_TTL,
                    json_encode($this->getState(), JSON_THROW_ON_ERROR),
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('NekotV state write failed', [
                'thread_id' => $this->threadId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete persisted state from Redis.
     */
    public function deleteState(): void
    {
        try {
            $redis = $this->getRedis();
            $redis?->del($this->getRedisKey());
        } catch (\Throwable $e) {
            $this->logger->error('NekotV state delete failed', [
                'thread_id' => $this->threadId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get a Redis connection from the Hyperf container.
     *
     * Uses the gateway's Redis DB 0 (same as application cache).
     */
    private function getRedis(): ?\Redis
    {
        try {
            $container = \Hyperf\Context\ApplicationContext::getContainer();
            /** @var \Hyperf\Redis\RedisFactory $factory */
            $factory = $container->get(\Hyperf\Redis\RedisFactory::class);
            return $factory->get('default');
        } catch (\Throwable) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  Timer management
    // ─────────────────────────────────────────────────────────────

    /**
     * Start the 1-second sync timer.
     */
    private function startSyncTimer(): void
    {
        if ($this->syncTimerId > 0) {
            return;
        }

        $interval = (int) (getenv('NEKOTV_SYNC_INTERVAL') ?: self::SYNC_INTERVAL_MS);
        $this->syncTimerId = Timer::tick($interval, function (): void {
            $this->syncVideoState();
        });
    }

    /**
     * Start the periodic drift re-bake timer.
     *
     * Checks every 5 minutes whether the VideoTimer's accumulated float
     * arithmetic needs collapsing. The actual re-bake only fires when
     * the timer's rebake_at timestamp is reached (~1 hour intervals).
     */
    private function startReBakeTimer(): void
    {
        if ($this->rebakeTimerId > 0) {
            return;
        }

        $this->rebakeTimerId = Timer::tick(self::REBAKE_CHECK_INTERVAL_MS, function (): void {
            if ($this->videoTimer->needsReBake()) {
                $this->videoTimer->reBake();
                $this->logger->debug('NekotV timer re-baked', ['thread_id' => $this->threadId]);
            }
        });
    }

    /**
     * Stop the sync timer and clean up all resources.
     */
    public function destroy(): void
    {
        if ($this->syncTimerId > 0) {
            Timer::clear($this->syncTimerId);
            $this->syncTimerId = 0;
        }

        if ($this->stateWriteTimerId > 0) {
            Timer::clear($this->stateWriteTimerId);
            $this->stateWriteTimerId = 0;
            // Flush any pending state write
            $this->writeStateToRedis();
        }

        if ($this->autoSkipTimerId > 0) {
            Timer::clear($this->autoSkipTimerId);
            $this->autoSkipTimerId = 0;
        }

        if ($this->rebakeTimerId > 0) {
            Timer::clear($this->rebakeTimerId);
            $this->rebakeTimerId = 0;
        }

        $this->clients = [];

        // Clean up Swoole Table rows for this thread
        $this->videoTimer->delete();
        $this->videoList->delete();

        $this->logger->debug('NekotV feed destroyed', ['thread_id' => $this->threadId]);
    }
}
