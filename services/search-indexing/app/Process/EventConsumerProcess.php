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

use App\Service\SearchService;
use Ashchan\EventBus\CloudEvent;
use Ashchan\EventBus\EventConsumer;
use Ashchan\EventBus\EventTypes;
use Hyperf\Process\Annotation\Process;
use Hyperf\Redis\RedisFactory;
use Psr\Container\ContainerInterface;

/**
 * Consumes domain events from the Redis Stream and indexes
 * post content for search.
 *
 * Handles: post.created, thread.created, moderation.decision
 */
#[Process(name: 'event-consumer', nums: 1)]
final class EventConsumerProcess extends EventConsumer
{
    protected string $group = 'search-indexing';

    private SearchService $searchService;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $this->searchService = $container->get(SearchService::class);

        // Get the dedicated 'events' Redis connection (DB 6)
        $redis = $container->get(RedisFactory::class)->get('events');
        $this->setRedis($redis);
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
            EventTypes::POST_CREATED => $this->indexPost($event),
            EventTypes::THREAD_CREATED => $this->indexThread($event),
            EventTypes::MODERATION_DECISION => $this->handleModeration($event),
            default => null,
        };
    }

    private function indexPost(CloudEvent $event): void
    {
        $payload = $event->payload;

        $boardSlug = (string) ($payload['board_id'] ?? '');
        $threadId = (int) ($payload['thread_id'] ?? 0);
        $postId = (int) ($payload['post_id'] ?? 0);
        $content = (string) ($payload['content'] ?? '');

        if ($boardSlug === '' || $postId === 0) {
            return;
        }

        $this->searchService->indexPost($boardSlug, $threadId, $postId, $content);

        $this->logger->debug('[SearchConsumer] Indexed post', [
            'board' => $boardSlug,
            'thread_id' => $threadId,
            'post_id' => $postId,
        ]);
    }

    private function indexThread(CloudEvent $event): void
    {
        // Thread creation is also reflected via the OP post.created event,
        // but this allows future thread-level indexing if needed
        $this->logger->debug('[SearchConsumer] Thread created event received', [
            'thread_id' => $event->payload['thread_id'] ?? 'unknown',
        ]);
    }

    private function handleModeration(CloudEvent $event): void
    {
        $payload = $event->payload;
        $action = (string) ($payload['action'] ?? '');

        // If a post was banned/deleted, remove it from the search index
        if (in_array($action, ['ban', 'delete'], true)) {
            $targetType = (string) ($payload['target_type'] ?? '');
            $targetId = (int) ($payload['target_id'] ?? 0);

            if ($targetType === 'post' && $targetId > 0) {
                $boardSlug = (string) ($payload['board_id'] ?? '');

                if ($boardSlug !== '') {
                    $this->searchService->removePost($boardSlug, $targetId);
                    $this->logger->info('[SearchConsumer] Post removed from search index', [
                        'board' => $boardSlug,
                        'post_id' => $targetId,
                        'action' => $action,
                    ]);
                } else {
                    $this->logger->warning('[SearchConsumer] Cannot remove post from index: missing board_id in moderation event payload', [
                        'post_id' => $targetId,
                        'action' => $action,
                    ]);
                }
            }
        }
    }
}
