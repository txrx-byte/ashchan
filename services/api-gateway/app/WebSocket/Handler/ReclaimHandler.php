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
 * Handles Reclaim (type 31): reclaim an open post after disconnection.
 *
 * When a client disconnects and reconnects, they can reclaim their open
 * post by sending the post ID and the same password used at creation.
 * The server verifies the password, extends the post's expiry, and sends
 * back the current body so the client can resume editing.
 *
 * C→S: type 31 + {id, password}
 * S→C: type 31 + {id, body} on success, or error
 *
 * @see docs/LIVEPOSTING.md §4.2, Phase 2
 */
final class ReclaimHandler
{
    public function __construct(
        private readonly ThreadFeedManager $feedManager,
        private readonly ProxyClient $proxyClient,
        private readonly WsServer $server,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle a Reclaim request from a client.
     *
     * @param int              $fd      Swoole file descriptor
     * @param ClientConnection $conn    Client connection state
     * @param array{
     *     id?: int,
     *     password?: string,
     * } $payload Parsed JSON payload
     */
    public function handle(int $fd, ClientConnection $conn, array $payload): void
    {
        // Client must be synced
        if (!$conn->isSynced()) {
            $this->sendError($fd, 'Must synchronise before reclaiming');
            return;
        }

        // No double opens
        if ($conn->hasOpenPost()) {
            $this->sendError($fd, 'Already have an open post — close it first');
            return;
        }

        $postId = (int) ($payload['id'] ?? 0);
        $password = (string) ($payload['password'] ?? '');

        if ($postId <= 0 || $password === '') {
            $this->sendError($fd, 'Post ID and password required');
            return;
        }

        // Call boards-threads-posts service to verify and reclaim
        $response = $this->proxyClient->forward(
            'boards',
            'POST',
            "/api/v1/posts/{$postId}/reclaim",
            [
                'Content-Type' => 'application/json',
            ],
            json_encode(['password' => $password], JSON_THROW_ON_ERROR),
        );

        if ($response['status'] !== 200) {
            $body = json_decode((string) $response['body'], true);
            $error = $body['error'] ?? 'Reclaim failed';
            $this->sendError($fd, $error);
            $this->logger->info('Reclaim failed', [
                'fd'      => $fd,
                'post_id' => $postId,
                'status'  => $response['status'],
            ]);
            return;
        }

        /** @var array{post_id: int, thread_id: int, body: string} $result */
        $result = json_decode((string) $response['body'], true);

        // Restore open post state on the connection
        $openPost = new OpenPost(
            postId: (int) $result['post_id'],
            threadId: (int) $result['thread_id'],
            board: $conn->board ?? '',
            createdAt: time(),
            passwordHash: $password,
        );
        // Restore the body from the server state
        $openPost->restoreBody((string) $result['body']);
        $conn->openPost = $openPost;

        // Send Reclaim success (type 31) back to the client
        $reclaimMsg = BinaryProtocol::encodeTextMessage(31, [
            'id'   => $result['post_id'],
            'body' => $result['body'],
        ]);
        if ($this->server->isEstablished($fd)) {
            $this->server->push($fd, $reclaimMsg, WEBSOCKET_OPCODE_TEXT);
        }

        // Update the feed's open body cache
        $feed = $this->feedManager->getFeed($conn->threadId);
        if ($feed !== null) {
            $feed->updateOpenBody($openPost->postId, $openPost->getBody());
        }

        $this->logger->info('Post reclaimed', [
            'fd'      => $fd,
            'post_id' => $postId,
            'thread'  => $result['thread_id'],
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
