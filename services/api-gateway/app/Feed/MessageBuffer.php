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

use SplQueue;

/**
 * Batched text message buffer for a ThreadFeed.
 *
 * Accumulates text WebSocket messages and provides a flush mechanism
 * that concatenates them into a single MessageConcat frame (type 33).
 * This reduces transport overhead by batching rapid updates into fewer
 * WebSocket frames.
 *
 * Binary messages (append/backspace/splice) bypass this buffer and are
 * sent immediately for lowest latency.
 *
 * @see docs/LIVEPOSTING.md §5.5
 */
final class MessageBuffer
{
    /** @var SplQueue<string> */
    private SplQueue $queue;

    public function __construct()
    {
        $this->queue = new SplQueue();
    }

    /**
     * Enqueue a text message for batched sending.
     */
    public function push(string $message): void
    {
        $this->queue->enqueue($message);
    }

    /**
     * Whether the buffer has any pending messages.
     */
    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }

    /**
     * Number of pending messages.
     */
    public function count(): int
    {
        return $this->queue->count();
    }

    /**
     * Drain the buffer and return all pending messages.
     *
     * @return array<string>
     */
    public function drain(): array
    {
        $messages = [];
        while (!$this->queue->isEmpty()) {
            $messages[] = $this->queue->dequeue();
        }
        return $messages;
    }

    /**
     * Explicitly clear all pending messages without returning them.
     * Used during feed destruction to prevent holding references.
     */
    public function clear(): void
    {
        while (!$this->queue->isEmpty()) {
            $this->queue->dequeue();
        }
    }
}
