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
 * Per-connection state value object.
 *
 * Stored in the worker-local connection registry, keyed by Swoole fd.
 * Thread/board assignment is set during the Synchronise handshake and
 * remains until the client disconnects or re-syncs to a different thread.
 *
 * @see docs/LIVEPOSTING.md §5.3
 */
final class ClientConnection
{
    /**
     * @param int         $fd          Swoole file descriptor
     * @param string      $ip          Client IP (from CF-Connecting-IP / X-Forwarded-For / remote)
     * @param int         $connectedAt Unix timestamp of connection establishment
     * @param int|null    $threadId    Currently synced thread (null = not yet synced)
     * @param string|null $board       Currently synced board slug
     * @param OpenPost|null $openPost  Currently editing post (null = not posting)
     * @param int         $lastActivity Last message timestamp (for idle detection)
     * @param bool        $synced      Has completed synchronisation handshake
     * @param int         $workerId    Swoole worker ID that owns this connection
     */
    public function __construct(
        public readonly int $fd,
        public readonly string $ip,
        public readonly int $connectedAt,
        public ?int $threadId = null,
        public ?string $board = null,
        public ?OpenPost $openPost = null,
        public int $lastActivity = 0,
        public bool $synced = false,
        public readonly int $workerId = 0,
    ) {
        if ($this->lastActivity === 0) {
            $this->lastActivity = $connectedAt;
        }
    }

    /**
     * Whether this client has an open (editing) post.
     */
    public function hasOpenPost(): bool
    {
        return $this->openPost !== null;
    }

    /**
     * Whether this client is synced to a specific thread.
     */
    public function isSynced(): bool
    {
        return $this->synced && $this->threadId !== null;
    }

    /**
     * Reset sync state when switching threads or disconnecting.
     */
    public function resetSync(): void
    {
        $this->threadId = null;
        $this->board = null;
        $this->synced = false;
    }

    /**
     * Update the last activity timestamp (for idle timeout detection).
     */
    public function touch(): void
    {
        $this->lastActivity = time();
    }
}
