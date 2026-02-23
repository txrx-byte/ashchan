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

namespace App\WebSocket\Handler;

use App\Feed\ThreadFeedManager;
use App\WebSocket\BinaryProtocol;
use App\WebSocket\ClientConnection;
use Psr\Log\LoggerInterface;
use Swoole\WebSocket\Server as WsServer;

/**
 * Handles the Synchronise (type 30) message.
 *
 * When a client connects and sends a synchronise request with a board
 * slug and thread ID, this handler:
 * 1. Validates the thread exists (via boards-threads-posts service)
 * 2. Unsubscribes the client from any previous feed
 * 3. Subscribes the client to the requested thread feed
 * 4. Sends the sync state (open posts, client count) back to the client
 * 5. Sends the server time for latency calculation
 *
 * @see docs/LIVEPOSTING.md §4.1, §4.3
 */
final class SynchroniseHandler
{
    /** Message type for Synchronise response. */
    private const MSG_SYNCHRONISE = 30;

    /** Message type for SyncCount. */
    private const MSG_SYNC_COUNT = 35;

    /** Message type for ServerTime. */
    private const MSG_SERVER_TIME = 36;

    public function __construct(
        private readonly ThreadFeedManager $feedManager,
        private readonly WsServer $server,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle a synchronise request from a client.
     *
     * @param int              $fd       Swoole file descriptor
     * @param ClientConnection $conn     Client connection state
     * @param string           $board    Board slug (e.g., "g", "tech")
     * @param int              $threadId Thread ID to subscribe to
     */
    public function handle(int $fd, ClientConnection $conn, string $board, int $threadId): void
    {
        // If already synced to a different thread, unsubscribe first
        if ($conn->isSynced() && $conn->threadId !== $threadId) {
            $this->feedManager->unsubscribe($fd, $conn->threadId);
            $conn->resetSync();
        }

        // Update connection state
        $conn->board = $board;
        $conn->threadId = $threadId;
        $conn->synced = true;
        $conn->touch();

        // Subscribe to the thread feed
        $feed = $this->feedManager->subscribe($fd, $conn, $threadId);

        // Send sync state to the client
        $syncState = $feed->getSyncState();
        $syncMessage = BinaryProtocol::encodeTextMessage(self::MSG_SYNCHRONISE, [
            'board'       => $board,
            'thread'      => $threadId,
            'open_posts'  => $syncState['open_posts'],
            'active_ips'  => $syncState['active_ips'],
            'client_count' => $syncState['client_count'],
        ]);

        if ($this->server->isEstablished($fd)) {
            $this->server->push($fd, $syncMessage, WEBSOCKET_OPCODE_TEXT);
        }

        // Send client count to all feed subscribers
        $countMessage = BinaryProtocol::encodeTextMessage(self::MSG_SYNC_COUNT, [
            'active' => $feed->getActiveIpCount(),
            'total'  => $feed->clientCount(),
        ]);
        $feed->queueTextMessage($countMessage);

        // Send server time for latency calculation
        $timeMessage = BinaryProtocol::encodeTextMessage(self::MSG_SERVER_TIME, [
            'time' => time(),
        ]);

        if ($this->server->isEstablished($fd)) {
            $this->server->push($fd, $timeMessage, WEBSOCKET_OPCODE_TEXT);
        }

        $this->logger->info('Client synchronised', [
            'fd'        => $fd,
            'board'     => $board,
            'thread_id' => $threadId,
            'clients'   => $feed->clientCount(),
        ]);
    }
}
