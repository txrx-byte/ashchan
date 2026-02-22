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

use Hyperf\Process\AbstractProcess;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Abstract base class for Redis Streams event consumers.
 *
 * Runs as a Hyperf Process (separate Swoole worker). Subclasses set
 * $group and implement process(CloudEvent). The consumer loop uses
 * XREADGROUP for competing-consumer semantics and XACK on success.
 *
 * Failed messages are retried up to $maxRetries times, then moved
 * to a dead-letter stream (DLQ) for manual inspection.
 */
abstract class EventConsumer extends AbstractProcess
{
    /** Redis Stream name. Override or set via env EVENTS_STREAM_NAME. */
    protected string $stream = 'ashchan:events';

    /** Consumer group name. MUST be set by subclass. */
    protected string $group = '';

    /** Consumer name within the group (defaults to hostname). */
    protected string $consumer = '';

    /** Max messages per XREADGROUP call. */
    protected int $batchSize = 100;

    /** Block timeout in milliseconds for XREADGROUP. */
    protected int $pollIntervalMs = 1000;

    /** Max retry attempts before dead-lettering. */
    protected int $maxRetries = 3;

    /** Idle time in ms before reclaiming pending messages. */
    protected int $reclaimIdleMs = 60_000;

    /** Dead-letter queue stream name. */
    protected string $dlqStream = 'ashchan:events:dlq';

    /** @var \Redis|\Hyperf\Redis\RedisProxy */
    protected object $redis;

    protected LoggerInterface $logger;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $this->logger = $container->get(LoggerInterface::class);

        // Allow env-based configuration
        $envStream = getenv('EVENTS_STREAM_NAME');
        if ($envStream !== false && $envStream !== '') {
            $this->stream = $envStream;
        }

        $envBatch = getenv('EVENTS_BATCH_SIZE');
        if ($envBatch !== false && is_numeric($envBatch)) {
            $this->batchSize = (int) $envBatch;
        }

        $envPoll = getenv('EVENTS_POLL_INTERVAL');
        if ($envPoll !== false && is_numeric($envPoll)) {
            $this->pollIntervalMs = (int) $envPoll;
        }

        $envMaxRetries = getenv('EVENTS_MAX_RETRIES');
        if ($envMaxRetries !== false && is_numeric($envMaxRetries)) {
            $this->maxRetries = (int) $envMaxRetries;
        }

        $envDlq = getenv('EVENTS_DLQ_STREAM');
        if ($envDlq !== false && $envDlq !== '') {
            $this->dlqStream = $envDlq;
        }

        // Default consumer name to hostname for uniqueness
        if ($this->consumer === '') {
            $this->consumer = gethostname() ?: ('consumer-' . getmypid());
        }
    }

    /**
     * Initialize the Redis connection. Called by subclass or DI.
     *
     * @param \Redis|\Hyperf\Redis\RedisProxy $redis
     */
    protected function setRedis(object $redis): void
    {
        $this->redis = $redis;
    }

    /**
     * Main consumer loop. Runs until the Hyperf process manager signals shutdown.
     */
    public function handle(): void
    {
        if ($this->group === '') {
            throw new \InvalidArgumentException(
                sprintf('Consumer group name must be set in %s. Override the $group property.', static::class),
            );
        }

        $this->createGroupIfNotExists();

        $this->logger->info("[EventBus] Consumer started", [
            'group' => $this->group,
            'consumer' => $this->consumer,
            'stream' => $this->stream,
        ]);

        while (\Hyperf\Process\ProcessManager::isRunning()) {
            try {
                $this->consumeNewMessages();
                $this->reclaimStaleMessages();
            } catch (\Throwable $e) {
                $this->logger->error("[EventBus] Consumer loop error", [
                    'group' => $this->group,
                    'error' => $e->getMessage(),
                ]);
                // Brief sleep to avoid tight error loops
                usleep(1_000_000);
            }
        }

        $this->logger->info("[EventBus] Consumer shutting down", [
            'group' => $this->group,
            'consumer' => $this->consumer,
        ]);
    }

    /**
     * Process a single CloudEvent. Implemented by each consumer service.
     */
    abstract protected function processEvent(CloudEvent $event): void;

    /**
     * Filter which event types this consumer handles.
     * Override to restrict processing to specific types.
     */
    protected function supports(string $eventType): bool
    {
        return true;
    }

    /**
     * Read and process new messages from the stream.
     */
    private function consumeNewMessages(): void
    {
        /** @var array<string, array<string, array<string, string>>>|false $results */
        $results = $this->redis->xReadGroup(
            $this->group,
            $this->consumer,
            [$this->stream => '>'],
            $this->batchSize,
            $this->pollIntervalMs,
        );

        if ($results === false || $results === []) {
            return;
        }

        // XREADGROUP returns [stream => [id => [field => value], ...]]
        $messages = $results[$this->stream] ?? [];
        foreach ($messages as $id => $data) {
            $this->handleMessage((string) $id, $data);
        }
    }

    /**
     * Reclaim messages that have been pending (unacknowledged) for too long.
     * This handles consumer crashes — another consumer in the group picks up
     * the orphaned messages.
     */
    private function reclaimStaleMessages(): void
    {
        try {
            /** @var array<string, array<string, string>>|false $claimed */
            $claimed = $this->redis->xClaim(
                $this->stream,
                $this->group,
                $this->consumer,
                $this->reclaimIdleMs,
                ['0-0'], // Claim from the beginning of pending entries
                ['COUNT' => $this->batchSize],
            );

            if (!is_array($claimed) || $claimed === []) {
                return;
            }

            foreach ($claimed as $id => $data) {
                if (is_array($data) && $data !== []) {
                    $this->handleMessage((string) $id, $data);
                }
            }
        } catch (\Throwable $e) {
            // XCLAIM may fail if the group doesn't exist yet — safe to ignore
            $this->logger->debug("[EventBus] XCLAIM error (may be benign)", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a single stream message: deserialize, filter, process, ACK.
     *
     * @param string               $id   Stream entry ID
     * @param array<string, string> $data Stream entry fields
     */
    private function handleMessage(string $id, array $data): void
    {
        if (!isset($data['event'])) {
            // Malformed message — ACK it to avoid infinite redelivery
            $this->redis->xAck($this->stream, $this->group, [$id]);
            $this->logger->warning("[EventBus] Malformed message (no 'event' field), ACKed", [
                'stream_id' => $id,
            ]);
            return;
        }

        try {
            $event = CloudEvent::fromJson($data['event']);

            if (!$this->supports($event->type)) {
                // Not interested in this event type — ACK and skip
                $this->redis->xAck($this->stream, $this->group, [$id]);
                return;
            }

            $this->processEvent($event);
            $this->redis->xAck($this->stream, $this->group, [$id]);

            $this->logger->debug("[EventBus] Processed event", [
                'stream_id' => $id,
                'event_type' => $event->type,
                'event_id' => $event->id,
                'group' => $this->group,
            ]);
        } catch (\Throwable $e) {
            $this->handleFailure($id, $data, $e);
        }
    }

    /**
     * Handle a processing failure: check retry count and dead-letter if exhausted.
     *
     * @param string               $id   Stream entry ID
     * @param array<string, string> $data Stream entry fields
     * @param \Throwable           $e    The exception that occurred
     */
    private function handleFailure(string $id, array $data, \Throwable $e): void
    {
        $retryCount = $this->getDeliveryCount($id);

        if ($retryCount >= $this->maxRetries) {
            // Move to dead-letter queue
            $this->deadLetter($id, $data, $e);
            $this->redis->xAck($this->stream, $this->group, [$id]);

            $this->logger->error("[EventBus] Dead-lettered after {$this->maxRetries} retries", [
                'stream_id' => $id,
                'group' => $this->group,
                'error' => $e->getMessage(),
            ]);
        } else {
            // Leave unACKed for redelivery on next reclaim cycle
            $this->logger->warning("[EventBus] Processing failed, will retry", [
                'stream_id' => $id,
                'group' => $this->group,
                'retry' => $retryCount,
                'max_retries' => $this->maxRetries,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Move a message to the dead-letter queue stream.
     *
     * @param string               $id   Stream entry ID
     * @param array<string, string> $data Original stream entry fields
     * @param \Throwable           $e    The exception
     */
    private function deadLetter(string $id, array $data, \Throwable $e): void
    {
        try {
            $this->redis->xAdd($this->dlqStream, '*', [
                'original_id' => $id,
                'event' => $data['event'] ?? '',
                'group' => $this->group,
                'consumer' => $this->consumer,
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'failed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            ]);
        } catch (\Throwable $dlqError) {
            $this->logger->critical("[EventBus] Failed to write to DLQ", [
                'stream_id' => $id,
                'error' => $dlqError->getMessage(),
            ]);
        }
    }

    /**
     * Get the number of times a message has been delivered (from XPENDING).
     */
    private function getDeliveryCount(string $id): int
    {
        try {
            /** @var array<int, array<int, mixed>>|false $pending */
            $pending = $this->redis->xPending(
                $this->stream,
                $this->group,
                $id,
                $id,
                1,
                $this->consumer,
            );

            if (is_array($pending) && isset($pending[0][3])) {
                return (int) $pending[0][3]; // delivery count is 4th element
            }
        } catch (\Throwable) {
            // If we can't check, assume first delivery
        }

        return 1;
    }

    /**
     * Create the consumer group if it doesn't already exist.
     * Uses MKSTREAM to create the stream if it doesn't exist either.
     */
    private function createGroupIfNotExists(): void
    {
        try {
            $this->redis->xGroup(
                'CREATE',
                $this->stream,
                $this->group,
                '0',    // Read from the beginning
                true,   // MKSTREAM — create stream if not exists
            );

            $this->logger->info("[EventBus] Created consumer group", [
                'group' => $this->group,
                'stream' => $this->stream,
            ]);
        } catch (\Throwable $e) {
            // "BUSYGROUP Consumer Group name already exists" is expected
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                $this->logger->error("[EventBus] Failed to create consumer group", [
                    'group' => $this->group,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
