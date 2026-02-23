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
use Psr\Log\LoggerInterface;
use Swoole\WebSocket\Server as WsServer;

/**
 * Handles ClosePost (type 05): finalize an open post.
 *
 * When the client sends ClosePost, this handler:
 * 1. Validates the client has an open post
 * 2. Calls boards-threads-posts service to close the post (copy body,
 *    parse HTML, invalidate caches)
 * 3. Clears the OpenPost state on the ClientConnection
 * 4. Broadcasts ClosePost (type 05) to all feed subscribers
 * 5. Removes the open body from the feed cache
 *
 * @see docs/LIVEPOSTING.md §5.7, Phase 2
 */
final class ClosePostHandler
{
    public function __construct(
        private readonly ThreadFeedManager $feedManager,
        private readonly ProxyClient $proxyClient,
        private readonly WsServer $server,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle a ClosePost request from a client.
     *
     * @param int              $fd   Swoole file descriptor
     * @param ClientConnection $conn Client connection state
     */
    public function handle(int $fd, ClientConnection $conn): void
    {
        $openPost = $conn->openPost;
        if ($openPost === null) {
            $this->sendError($fd, 'No open post to close');
            return;
        }

        $postId = $openPost->postId;
        $threadId = $openPost->threadId;

        // Call boards-threads-posts service to finalize the post
        $response = $this->proxyClient->forward(
            'boards',
            'POST',
            "/api/v1/posts/{$postId}/close",
            [
                'Content-Type' => 'application/json',
            ],
            '',
        );

        if ($response['status'] !== 200) {
            $body = json_decode((string) $response['body'], true);
            $error = $body['error'] ?? 'Failed to close post';
            $this->logger->warning('ClosePost service call failed', [
                'fd'      => $fd,
                'post_id' => $postId,
                'status'  => $response['status'],
                'error'   => $error,
            ]);
            // Even if the service call fails, clean up local state
        }

        /** @var array{content_html?: string}|null $result */
        $result = json_decode((string) $response['body'], true);
        $contentHtml = $result['content_html'] ?? '';

        // Clear the open post state
        $conn->openPost = null;

        // Broadcast ClosePost (type 05) to all feed subscribers
        $feed = $this->feedManager->getFeed($threadId);
        if ($feed !== null) {
            $closeMsg = BinaryProtocol::encodeTextMessage(5, [
                'id'           => $postId,
                'content_html' => $contentHtml,
            ]);
            $feed->queueTextMessage($closeMsg);

            // Remove the open body from the feed cache
            $feed->removeOpenBody($postId);

            // Update sync count
            $countMsg = BinaryProtocol::encodeTextMessage(35, [
                'active' => $feed->getActiveIpCount(),
                'total'  => $feed->clientCount(),
            ]);
            $feed->queueTextMessage($countMsg);
        }

        $this->logger->info('Post closed', [
            'fd'      => $fd,
            'post_id' => $postId,
            'thread'  => $threadId,
        ]);
    }

    /**
     * Force-close an open post (called on disconnect or timeout).
     *
     * Same as handle() but doesn't require the client to be connected.
     *
     * @param ClientConnection $conn The disconnecting client
     */
    public function forceClose(ClientConnection $conn): void
    {
        $openPost = $conn->openPost;
        if ($openPost === null) {
            return;
        }

        $postId = $openPost->postId;
        $threadId = $openPost->threadId;

        // Call boards-threads-posts service to finalize the post
        $response = $this->proxyClient->forward(
            'boards',
            'POST',
            "/api/v1/posts/{$postId}/close",
            ['Content-Type' => 'application/json'],
            '',
        );

        if ($response['status'] !== 200) {
            $this->logger->warning('Force-close service call failed', [
                'post_id' => $postId,
                'status'  => $response['status'],
            ]);
        }

        $result = json_decode((string) $response['body'], true);
        $contentHtml = $result['content_html'] ?? '';

        // Clear the open post state
        $conn->openPost = null;

        // Broadcast ClosePost to feed
        $feed = $this->feedManager->getFeed($threadId);
        if ($feed !== null) {
            $closeMsg = BinaryProtocol::encodeTextMessage(5, [
                'id'           => $postId,
                'content_html' => $contentHtml,
            ]);
            $feed->queueTextMessage($closeMsg);
            $feed->removeOpenBody($postId);
        }

        $this->logger->info('Post force-closed on disconnect', [
            'post_id' => $postId,
            'thread'  => $threadId,
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
