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

namespace App\Process;

use Ashchan\EventBus\CloudEvent;
use Ashchan\EventBus\EventConsumer;
use Ashchan\EventBus\EventTypes;
use Hyperf\Process\Annotation\Process;
use Hyperf\Redis\Redis;
use Hyperf\Redis\RedisFactory;
use Psr\Container\ContainerInterface;

/**
 * Consumes domain events and invalidates cached data in the gateway's
 * Redis cache (DB 0) when threads/posts are created or moderated.
 *
 * Handles: post.created, thread.created, moderation.decision
 */
#[Process(name: 'cache-invalidator', nums: 1)]
final class CacheInvalidatorProcess extends EventConsumer
{
    protected string $group = 'cache-invalidation';

    /** Redis connection for cache operations (gateway's DB 0). */
    private object $cacheRedis;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        // Event bus Redis (DB 6) for stream operations
        $eventsRedis = $container->get(RedisFactory::class)->get('events');
        $this->setRedis($eventsRedis);

        // Default Redis (DB 0) for cache invalidation
        $this->cacheRedis = $container->get(Redis::class);
    }

    protected function supports(string $eventType): bool
    {
        return in_array($eventType, [
            EventTypes::POST_CREATED,
            EventTypes::THREAD_CREATED,
            EventTypes::MODERATION_DECISION,
        ], true);
    }

    protected function processEvent(CloudEvent $event): void
    {
        match ($event->type) {
            EventTypes::POST_CREATED,
            EventTypes::THREAD_CREATED => $this->invalidateThreadCache($event),
            EventTypes::MODERATION_DECISION => $this->invalidateOnModeration($event),
            default => null,
        };
    }

    private function invalidateThreadCache(CloudEvent $event): void
    {
        $boardSlug = (string) ($event->payload['board_id'] ?? '');
        $threadId = (string) ($event->payload['thread_id'] ?? '');

        $keysDeleted = 0;

        if ($boardSlug !== '') {
            // Invalidate board index cache
            try {
                /** @var int|false $deleted */
                $deleted = $this->cacheRedis->del("board:{$boardSlug}:index");
                $keysDeleted += is_int($deleted) ? $deleted : 0;
            } catch (\RedisException $e) {
                $this->logger->warning('[CacheInvalidator] Redis error deleting board cache', [
                    'key' => "board:{$boardSlug}:index",
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($threadId !== '') {
            // Invalidate thread cache
            try {
                /** @var int|false $deleted */
                $deleted = $this->cacheRedis->del("thread:{$threadId}");
                $keysDeleted += is_int($deleted) ? $deleted : 0;
            } catch (\RedisException $e) {
                $this->logger->warning('[CacheInvalidator] Redis error deleting thread cache', [
                    'key' => "thread:{$threadId}",
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->debug('[CacheInvalidator] Invalidated caches', [
            'board' => $boardSlug,
            'thread_id' => $threadId,
            'keys_deleted' => $keysDeleted,
            'event_type' => $event->type,
        ]);
    }

    private function invalidateOnModeration(CloudEvent $event): void
    {
        $targetType = (string) ($event->payload['target_type'] ?? '');
        $targetId = (string) ($event->payload['target_id'] ?? '');
        $action = (string) ($event->payload['action'] ?? '');

        // For ban/delete actions, invalidate related caches
        if (in_array($action, ['ban', 'delete', 'clear'], true) && $targetId !== '') {
            $boardSlug = (string) ($event->payload['board_id'] ?? '');
            $threadId = (string) ($event->payload['thread_id'] ?? '');
            $keysDeleted = 0;

            if (in_array($targetType, ['post', 'thread'], true)) {
                // Invalidate thread cache if thread context is available
                if ($threadId !== '') {
                    try {
                        /** @var int|false $deleted */
                        $deleted = $this->cacheRedis->del("thread:{$threadId}");
                        $keysDeleted += is_int($deleted) ? $deleted : 0;
                    } catch (\RedisException $e) {
                        $this->logger->warning('[CacheInvalidator] Redis error during moderation cache clear', [
                            'key' => "thread:{$threadId}",
                            'error' => $e->getMessage(),
                        ]);
                    }
                } elseif ($targetType === 'thread') {
                    // target_id IS the thread when target_type is 'thread'
                    try {
                        /** @var int|false $deleted */
                        $deleted = $this->cacheRedis->del("thread:{$targetId}");
                        $keysDeleted += is_int($deleted) ? $deleted : 0;
                    } catch (\RedisException $e) {
                        $this->logger->warning('[CacheInvalidator] Redis error during moderation cache clear', [
                            'key' => "thread:{$targetId}",
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Invalidate board index cache if board context is available
                if ($boardSlug !== '') {
                    try {
                        /** @var int|false $deleted */
                        $deleted = $this->cacheRedis->del("board:{$boardSlug}:index");
                        $keysDeleted += is_int($deleted) ? $deleted : 0;
                    } catch (\RedisException $e) {
                        $this->logger->warning('[CacheInvalidator] Redis error during moderation cache clear', [
                            'key' => "board:{$boardSlug}:index",
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            if ($keysDeleted === 0 && $boardSlug === '' && $threadId === '') {
                $this->logger->debug('[CacheInvalidator] Moderation event lacks board/thread context; cache invalidation skipped (eventual consistency via next request)', [
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'action' => $action,
                ]);
            } else {
                $this->logger->debug('[CacheInvalidator] Moderation cache invalidation', [
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'action' => $action,
                    'keys_deleted' => $keysDeleted,
                ]);
            }
        }
    }
}
