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
use App\Service\ProxyClient;
use App\WebSocket\BinaryProtocol;
use App\WebSocket\ClientConnection;
use App\WebSocket\OpenPost;
use Psr\Log\LoggerInterface;
use Swoole\WebSocket\Server as WsServer;

/**
 * Handles InsertPost (type 01 C→S): allocate an open post on the server.
 *
 * When the client sends InsertPost with {board, thread, name, password},
 * this handler:
 * 1. Validates the client is synced to a thread feed
 * 2. Checks the client doesn't already have an open post
 * 3. Calls boards-threads-posts service via mTLS to create an open post
 * 4. Stores the OpenPost state on the ClientConnection
 * 5. Sends PostID (type 32) back to the client
 * 6. Broadcasts InsertPost (type 01) to all feed subscribers
 *
 * @see docs/LIVEPOSTING.md §5.7, Phase 2
 */
final class InsertPostHandler
{
    public function __construct(
        private readonly ThreadFeedManager $feedManager,
        private readonly ProxyClient $proxyClient,
        private readonly WsServer $server,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle an InsertPost request from a client.
     *
     * @param int              $fd      Swoole file descriptor
     * @param ClientConnection $conn    Client connection state
     * @param array{
     *     name?: string,
     *     password?: string,
     * } $payload Parsed JSON payload
     */
    public function handle(int $fd, ClientConnection $conn, array $payload): void
    {
        // Client must be synced to a thread
        if (!$conn->isSynced() || $conn->threadId <= 0 || $conn->board === null || $conn->board === '') {
            $this->sendError($fd, 'Must synchronise to a thread before creating a post');
            return;
        }

        // No double opens
        if ($conn->hasOpenPost()) {
            $this->sendError($fd, 'Already have an open post — close it first');
            return;
        }

        $name = (string) ($payload['name'] ?? '');
        $password = (string) ($payload['password'] ?? '');

        // Generate a random reclaim password if none provided
        if ($password === '') {
            $password = bin2hex(random_bytes(16));
        }

        // Call boards-threads-posts service to allocate the post
        $response = $this->proxyClient->forward(
            'boards',
            'POST',
            "/api/v1/boards/{$conn->board}/threads/{$conn->threadId}/open-post",
            [
                'Content-Type'    => 'application/json',
                'X-Forwarded-For' => $conn->ip,
                'X-Real-IP'       => $conn->ip,
            ],
            json_encode([
                'name'     => $name,
                'password' => $password,
            ], JSON_THROW_ON_ERROR),
        );

        if ($response['status'] !== 201) {
            $body = json_decode((string) $response['body'], true);
            $error = $body['error'] ?? 'Failed to create post';
            $this->sendError($fd, $error);
            $this->logger->warning('InsertPost failed', [
                'fd'     => $fd,
                'status' => $response['status'],
                'error'  => $error,
            ]);
            return;
        }

        /** @var array{post_id: int, thread_id: int, board_post_no: int|null} $result */
        $result = json_decode((string) $response['body'], true);
        $postId = (int) $result['post_id'];

        // Store open post state on the connection
        $conn->openPost = new OpenPost(
            postId: $postId,
            threadId: $conn->threadId,
            board: $conn->board,
            createdAt: time(),
            passwordHash: $password, // Store plaintext for reclamation context
        );

        // Send PostID (type 32) back to the client
        $postIdMsg = BinaryProtocol::encodeTextMessage(32, [
            'id'            => $postId,
            'board_post_no' => $result['board_post_no'],
        ]);
        if ($this->server->isEstablished($fd)) {
            $this->server->push($fd, $postIdMsg, WEBSOCKET_OPCODE_TEXT);
        }

        // Broadcast InsertPost (type 01) to all feed subscribers
        $feed = $this->feedManager->getFeed($conn->threadId);
        if ($feed !== null) {
            $insertMsg = BinaryProtocol::encodeTextMessage(1, [
                'id'            => $postId,
                'board_post_no' => $result['board_post_no'],
                'name'          => $name,
                'is_editing'    => true,
                'body'          => '',
                'created_at'    => time(),
            ]);
            $feed->queueTextMessage($insertMsg);

            // Track the open post body in the feed cache
            $feed->updateOpenBody($postId, '');
        }

        $this->logger->info('Open post created', [
            'fd'      => $fd,
            'post_id' => $postId,
            'thread'  => $conn->threadId,
            'board'   => $conn->board,
        ]);
    }

    /**
     * Send an error message to the client.
     */
    private function sendError(int $fd, string $message): void
    {
        if ($this->server->isEstablished($fd)) {
            $msg = BinaryProtocol::encodeTextMessage(0, ['error' => $message]);
            $this->server->push($fd, $msg, WEBSOCKET_OPCODE_TEXT);
        }
    }
}
