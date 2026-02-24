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
use Psr\Log\LoggerInterface;
use Swoole\WebSocket\Server as WsServer;

/**
 * Registry of active NekotV feeds, one per thread.
 *
 * Worker-local (each Swoole worker has its own instance). Creates feeds
 * lazily on first subscriber and destroys them when the last client leaves.
 * Handles state persistence/restoration via Redis.
 *
 * Port of meguca's websockets/feeds/feeds.go feedMap.nekotvFeeds.
 *
 * @see meguca/websockets/feeds/feeds.go
 */
final class NekotVFeedManager
{
    /**
     * Worker-local feeds: threadId → NekotVFeed.
     *
     * @var array<int, NekotVFeed>
     */
    private array $feeds = [];

    /** Feature flag: is NekotV enabled? */
    private readonly bool $enabled;

    public function __construct(
        private readonly WsServer $server,
        private readonly LoggerInterface $logger,
        private readonly int $workerId,
    ) {
        $this->enabled = filter_var(
            getenv('NEKOTV_ENABLED') ?: 'false',
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * Whether NekotV is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Subscribe a client to a thread's NekotV feed.
     *
     * Creates the feed lazily if it doesn't exist, restoring state from Redis.
     */
    public function subscribe(int $fd, ClientConnection $conn, int $threadId): void
    {
        if (!$this->enabled) {
            return;
        }

        $feed = $this->getOrCreate($threadId);
        $feed->addClient($fd, $conn);

        $this->logger->debug('NekotV client subscribed', [
            'fd'        => $fd,
            'thread_id' => $threadId,
            'worker_id' => $this->workerId,
        ]);
    }

    /**
     * Unsubscribe a client from a thread's NekotV feed.
     *
     * If the feed becomes empty, it is destroyed and state is cleaned up.
     */
    public function unsubscribe(int $fd, int $threadId): void
    {
        $feed = $this->feeds[$threadId] ?? null;
        if ($feed === null) {
            return;
        }

        $isEmpty = $feed->removeClient($fd);

        if ($isEmpty) {
            $feed->deleteState();
            $feed->destroy();
            unset($this->feeds[$threadId]);

            $this->logger->info('NekotV feed removed (no clients)', [
                'thread_id' => $threadId,
                'worker_id' => $this->workerId,
            ]);
        }
    }

    /**
     * Get an existing feed for a thread (returns null if none exists).
     */
    public function getFeed(int $threadId): ?NekotVFeed
    {
        return $this->feeds[$threadId] ?? null;
    }

    /**
     * Get or create a feed for a thread.
     */
    public function getOrCreate(int $threadId): NekotVFeed
    {
        if (isset($this->feeds[$threadId])) {
            return $this->feeds[$threadId];
        }

        $feed = new NekotVFeed($threadId, $this->server, $this->logger);

        // Try to restore state from Redis
        $savedState = $this->loadStateFromRedis($threadId);
        $feed->start($savedState);

        $this->feeds[$threadId] = $feed;

        $this->logger->info('NekotV feed created', [
            'thread_id'      => $threadId,
            'worker_id'      => $this->workerId,
            'restored_state' => $savedState !== null,
        ]);

        return $feed;
    }

    /**
     * Handle a media command dispatched from post body parsing.
     */
    public function handleMediaCommand(int $threadId, MediaCommand $command): void
    {
        if (!$this->enabled) {
            return;
        }

        $feed = $this->feeds[$threadId] ?? null;
        if ($feed === null) {
            return; // No active feed for this thread
        }

        switch ($command->type) {
            case MediaCommandType::ADD_VIDEO:
                // Video data fetching runs in a coroutine to avoid blocking
                \Swoole\Coroutine::create(function () use ($feed, $command): void {
                    try {
                        $fetcher = new MetadataFetcher($this->logger);
                        $videoItem = $fetcher->fetch($command->args);
                        if ($videoItem !== null) {
                            $feed->addVideo($videoItem, true);
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error('NekotV video fetch failed', [
                            'url'   => $command->args,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
                break;

            case MediaCommandType::REMOVE_VIDEO:
                $feed->removeVideo($command->args);
                break;

            case MediaCommandType::SKIP_VIDEO:
                $feed->skipVideo();
                break;

            case MediaCommandType::PAUSE:
                $feed->pause();
                break;

            case MediaCommandType::PLAY:
                $feed->play();
                break;

            case MediaCommandType::SET_TIME:
                $seconds = CommandParser::parseTimestamp($command->args);
                if ($seconds !== null) {
                    $feed->setTime($seconds);
                }
                break;

            case MediaCommandType::CLEAR_PLAYLIST:
                $feed->clearPlaylist();
                break;

            case MediaCommandType::SET_RATE:
                $rate = filter_var($command->args, FILTER_VALIDATE_FLOAT);
                if ($rate !== false) {
                    try {
                        $feed->setRate((float) $rate);
                    } catch (\InvalidArgumentException $e) {
                        $this->logger->debug('NekotV invalid rate', [
                            'rate'  => $command->args,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                break;
        }
    }

    /**
     * Toggle playlist lock for a thread.
     */
    public function toggleLock(int $threadId, bool $isOpen): void
    {
        $feed = $this->feeds[$threadId] ?? null;
        if ($feed !== null) {
            $feed->toggleLock($isOpen);
        }
    }

    /**
     * Get all worker-local feeds (for garbage collection / metrics).
     *
     * @return array<int, NekotVFeed>
     */
    public function getAllFeeds(): array
    {
        return $this->feeds;
    }

    /**
     * Remove a feed by thread ID (used by GC).
     */
    public function remove(int $threadId): void
    {
        $feed = $this->feeds[$threadId] ?? null;
        if ($feed !== null) {
            $feed->destroy();
            unset($this->feeds[$threadId]);
        }
    }

    /**
     * Get metrics.
     *
     * @return array<string, int>
     */
    public function getMetrics(): array
    {
        $totalClients = 0;
        foreach ($this->feeds as $feed) {
            $totalClients += $feed->clientCount();
        }

        return [
            'nekotv_feeds'   => count($this->feeds),
            'nekotv_clients' => $totalClients,
        ];
    }

    /**
     * Load persisted state from Redis for a thread.
     *
     * @return array<string, mixed>|null
     */
    private function loadStateFromRedis(int $threadId): ?array
    {
        try {
            $redis = $this->getRedis();
            if ($redis === null) {
                return null;
            }

            $key = 'nekotv:state:' . $threadId;
            $data = $redis->get($key);
            if ($data === false || $data === null) {
                return null;
            }

            $state = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
            return is_array($state) ? $state : null;
        } catch (\Throwable $e) {
            $this->logger->warning('NekotV state load failed', [
                'thread_id' => $threadId,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get a Redis connection.
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
}
