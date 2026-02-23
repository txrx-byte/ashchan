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
 * Handles binary Backspace (0x03) messages.
 *
 * When the client sends a backspace, this handler:
 * 1. Validates the client has an open post
 * 2. Removes the last character from the OpenPost body
 * 3. Broadcasts the backspace to all feed subscribers
 * 4. Updates the feed's open body cache
 *
 * C→S frame: [0x03]
 * S→C broadcast: [postID:f64LE][0x03]
 *
 * @see docs/LIVEPOSTING.md §4.2, §5.7
 */
final class BackspaceHandler
{
    public function __construct(
        private readonly ThreadFeedManager $feedManager,
        private readonly WsServer $server,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle a binary backspace message.
     *
     * @param int              $fd   Swoole file descriptor
     * @param ClientConnection $conn Client connection state
     */
    public function handle(int $fd, ClientConnection $conn): void
    {
        $openPost = $conn->openPost;
        if ($openPost === null) {
            $this->logger->debug('Backspace from client without open post', ['fd' => $fd]);
            return;
        }

        // Attempt backspace — returns false if body is already empty
        $ok = $openPost->backspace();
        if (!$ok) {
            return; // Nothing to delete, silently ignore
        }

        // Build broadcast frame: [postID:f64LE][0x03]
        $broadcast = BinaryProtocol::encodeBackspace($openPost->postId);

        // Broadcast to thread feed (binary, immediate)
        $feed = $this->feedManager->getFeed($openPost->threadId);
        if ($feed !== null) {
            $feed->broadcastBinary($broadcast);
            $feed->updateOpenBody($openPost->postId, $openPost->getBody());
        }
    }
}
