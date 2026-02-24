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

namespace App\WebSocket;

/**
 * Envelope for inter-worker messages sent via Swoole's sendMessage() IPC.
 *
 * When a client sends a message on worker 0, the binary/text frame needs
 * to be broadcast to clients watching the same thread on ALL workers.
 * This value object wraps the data needed for that forwarding.
 *
 * Swoole's $server->sendMessage() serializes the data automatically
 * (it uses PHP's serialize/unserialize). The onPipeMessage callback
 * receives the deserialized object.
 *
 * @see docs/LIVEPOSTING.md §5.4
 */
final class PipeMessage
{
    /** Binary broadcast: immediate push to all clients in the thread. */
    public const TYPE_BINARY_BROADCAST = 'binary_broadcast';

    /** Text broadcast: immediate push to all clients in the thread. */
    public const TYPE_TEXT_BROADCAST = 'text_broadcast';

    /** Text queued: enqueue into the 100ms message buffer for batched delivery. */
    public const TYPE_TEXT_QUEUE = 'text_queue';

    /** NekotV binary broadcast: immediate push to all NekotV subscribers. */
    public const TYPE_NEKOTV_BROADCAST = 'nekotv_broadcast';

    public function __construct(
        /** IPC message type. */
        public readonly string $type,

        /** Target thread ID. */
        public readonly int $threadId,

        /** Raw frame data (binary or text string). */
        public readonly string $data,

        /** Source worker ID (for debugging, not routing). */
        public readonly int $sourceWorker,
    ) {
    }
}
