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

namespace Ashchan\EventBus;

use Psr\Log\LoggerInterface;

/**
 * Publishes CloudEvents to a Redis Stream via XADD.
 *
 * Uses a capped stream (~MAXLEN) to prevent unbounded memory growth.
 * Failures are logged but never propagate — the database write is the
 * source of truth and missed events can be replayed via backfill.
 */
final class EventPublisher
{
    private string $stream;
    private int $maxlen;

    /**
     * @param \Redis|\Hyperf\Redis\RedisProxy $redis  Redis connection for the events DB
     * @param LoggerInterface                  $logger PSR-3 logger
     * @param string                           $stream Stream name (default: ashchan:events)
     * @param int                              $maxlen Approximate max stream length
     */
    public function __construct(
        private readonly object $redis,
        private readonly LoggerInterface $logger,
        string $stream = 'ashchan:events',
        int $maxlen = 100_000,
    ) {
        $this->stream = $stream;
        $this->maxlen = $maxlen;
    }

    /**
     * Publish a CloudEvent to the stream.
     *
     * @return string|false The stream entry ID on success, false on failure
     */
    public function publish(CloudEvent $event): string|false
    {
        try {
            /** @var string|false $id */
            $id = $this->redis->xAdd(
                $this->stream,
                '*',
                ['event' => $event->toJson()],
                $this->maxlen,
                true, // approximate trimming (~MAXLEN)
            );

            if ($id === false) {
                $this->logger->error('[EventBus] XADD returned false', [
                    'event_type' => $event->type,
                    'event_id' => $event->id,
                ]);
                return false;
            }

            $this->logger->debug('[EventBus] Published event', [
                'stream_id' => $id,
                'event_type' => $event->type,
                'event_id' => $event->id,
            ]);

            return $id;
        } catch (\Throwable $e) {
            // Never propagate — the DB write is the source of truth
            $this->logger->error('[EventBus] Failed to publish event', [
                'event_type' => $event->type,
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get current stream length.
     */
    public function streamLength(): int
    {
        try {
            /** @var int|false $len */
            $len = $this->redis->xLen($this->stream);
            return $len !== false ? $len : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Get stream info for health checks.
     *
     * @return array<string, mixed>
     */
    public function streamInfo(): array
    {
        try {
            /** @var array<string, mixed>|false $info */
            $info = $this->redis->xInfo('STREAM', $this->stream);
            return is_array($info) ? $info : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
