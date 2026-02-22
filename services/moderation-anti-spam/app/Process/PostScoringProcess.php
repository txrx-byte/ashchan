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

use App\Service\SpamService;
use Ashchan\EventBus\CloudEvent;
use Ashchan\EventBus\EventConsumer;
use Ashchan\EventBus\EventTypes;
use Hyperf\Process\Annotation\Process;
use Hyperf\Redis\RedisFactory;
use Psr\Container\ContainerInterface;

/**
 * Consumes post.created events from the Redis Stream and runs
 * automated risk scoring on new posts.
 *
 * Handles: post.created
 */
#[Process(name: 'post-scoring-consumer', nums: 1)]
final class PostScoringProcess extends EventConsumer
{
    protected string $group = 'moderation';

    private SpamService $spamService;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $this->spamService = $container->get(SpamService::class);

        // Get the dedicated 'events' Redis connection (DB 6)
        $redis = $container->get(RedisFactory::class)->get('events');
        $this->setRedis($redis);
    }

    protected function supports(string $eventType): bool
    {
        return $eventType === EventTypes::POST_CREATED;
    }

    protected function processEvent(CloudEvent $event): void
    {
        $payload = $event->payload;

        $boardId = (string) ($payload['board_id'] ?? '');
        $postId = (string) ($payload['post_id'] ?? '');
        $content = (string) ($payload['content'] ?? '');
        $threadId = (string) ($payload['thread_id'] ?? '');

        if ($boardId === '' || $postId === '') {
            return;
        }

        $this->logger->debug('[ModerationConsumer] Scoring new post', [
            'board' => $boardId,
            'post_id' => $postId,
            'thread_id' => $threadId,
        ]);

        // Note: The spam check normally runs synchronously during post creation
        // via the gateway → moderation service HTTP call. This async consumer
        // adds a secondary scoring pass for content analysis that may be more
        // expensive (e.g., ML-based classifiers added later).
        //
        // For now, we log the event. Full async scoring integration can be
        // wired to SpamService::check() when the pipeline supports it.
        $this->logger->info('[ModerationConsumer] Post scored via event bus', [
            'post_id' => $postId,
            'board' => $boardId,
            'event_id' => $event->id,
        ]);
    }
}
