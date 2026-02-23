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
use App\WebSocket\SpamScorer;
use Psr\Log\LoggerInterface;
use Swoole\WebSocket\Server as WsServer;

/**
 * Handles binary Append (0x02) messages: character streaming.
 *
 * When the client sends a single character append, this handler:
 * 1. Validates the client has an open post
 * 2. Appends the character to the OpenPost body (with limit checks)
 * 3. Broadcasts the append to all feed subscribers (immediate, binary)
 * 4. Updates the feed's open body cache
 *
 * C→S frame: [char:utf8][0x02]
 * S→C broadcast: [postID:f64LE][char:utf8][0x02]
 *
 * @see docs/LIVEPOSTING.md §4.2, §5.7
 */
final class AppendHandler
{
    public function __construct(
        private readonly ThreadFeedManager $feedManager,
        private readonly WsServer $server,
        private readonly LoggerInterface $logger,
        private readonly ?SpamScorer $spamScorer = null,
    ) {
    }

    /**
     * Handle a binary append message.
     *
     * @param int              $fd   Swoole file descriptor
     * @param string           $data Raw binary data (without type byte, which was already stripped)
     * @param ClientConnection $conn Client connection state
     */
    public function handle(int $fd, string $data, ClientConnection $conn): void
    {
        $openPost = $conn->openPost;
        if ($openPost === null) {
            $this->logger->debug('Append from client without open post', ['fd' => $fd]);
            return;
        }

        // Spam scoring: each character append adds a small cost
        if ($this->spamScorer !== null) {
            $ipHash = hash('xxh3', $conn->ip);
            $this->spamScorer->record($ipHash, SpamScorer::COST_CHAR_APPEND);
        }

        // The data is the character bytes (type byte was the last byte, already identified)
        // C→S frame format: [char:utf8][0x02]
        // After MessageHandler strips the type byte, $data = [char:utf8]
        $charBytes = $data;

        if ($charBytes === '') {
            return;
        }

        // Attempt to append — returns false if body limit reached
        $ok = $openPost->appendChar($charBytes);
        if (!$ok) {
            // Body is full — send error but don't disconnect
            if ($this->server->isEstablished($fd)) {
                $msg = BinaryProtocol::encodeTextMessage(0, ['error' => 'Post body limit reached']);
                $this->server->push($fd, $msg, WEBSOCKET_OPCODE_TEXT);
            }
            return;
        }

        // Build broadcast frame: [postID:f64LE][char:utf8][0x02]
        $broadcast = BinaryProtocol::encodeAppend($openPost->postId, $charBytes);

        // Broadcast to thread feed (binary, immediate, no buffering)
        $feed = $this->feedManager->getFeed($openPost->threadId);
        if ($feed !== null) {
            $feed->broadcastBinary($broadcast);
            $feed->updateOpenBody($openPost->postId, $openPost->getBody());
        }
    }
}
