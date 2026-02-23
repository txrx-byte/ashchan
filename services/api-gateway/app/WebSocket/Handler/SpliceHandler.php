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
 * Handles binary Splice (0x04) messages: arbitrary text replacement.
 *
 * Splice replaces a range of characters in the open post body:
 * - start: UTF-8 character offset from beginning
 * - len:   number of characters to delete at that offset
 * - text:  replacement text to insert at that offset
 *
 * This handles paste, cut, select-and-type, and other non-trivial edits.
 *
 * C→S frame: [start:u16LE][len:u16LE][text:utf8][0x04]
 * S→C broadcast: [postID:f64LE][start:u16LE][len:u16LE][text:utf8][0x04]
 *
 * @see docs/LIVEPOSTING.md §4.2, §5.7
 */
final class SpliceHandler
{
    public function __construct(
        private readonly ThreadFeedManager $feedManager,
        private readonly WsServer $server,
        private readonly LoggerInterface $logger,
        private readonly ?SpamScorer $spamScorer = null,
    ) {
    }

    /**
     * Handle a binary splice message.
     *
     * @param int              $fd   Swoole file descriptor
     * @param string           $data Raw binary data (type byte already removed by caller)
     * @param ClientConnection $conn Client connection state
     */
    public function handle(int $fd, string $data, ClientConnection $conn): void
    {
        $openPost = $conn->openPost;
        if ($openPost === null) {
            $this->logger->debug('Splice from client without open post', ['fd' => $fd]);
            return;
        }

        // Spam scoring: splice cost scales with replacement text length
        if ($this->spamScorer !== null) {
            $ipHash = hash('xxh3', $conn->ip);
            $textLen = max(1, mb_strlen($data)); // Approximate; full decode below
            $this->spamScorer->record($ipHash, SpamScorer::COST_SPLICE * $textLen);
        }

        // Decode splice payload: [start:u16LE][len:u16LE][text:utf8]
        $decoded = BinaryProtocol::decodeSplicePayload($data);
        if ($decoded === null) {
            $this->logger->warning('Invalid splice payload', ['fd' => $fd, 'data_len' => strlen($data)]);
            return;
        }

        $start = $decoded['start'];
        $len = $decoded['len'];
        $text = $decoded['text'];

        // Apply splice to the open post body
        $ok = $openPost->splice($start, $len, $text);
        if (!$ok) {
            // Body limit would be exceeded — send error
            if ($this->server->isEstablished($fd)) {
                $msg = BinaryProtocol::encodeTextMessage(0, ['error' => 'Post body limit reached']);
                $this->server->push($fd, $msg, WEBSOCKET_OPCODE_TEXT);
            }
            return;
        }

        // Build broadcast frame: [postID:f64LE][start:u16LE][len:u16LE][text:utf8][0x04]
        $broadcast = BinaryProtocol::encodeSplice($openPost->postId, $start, $len, $text);

        // Broadcast to thread feed (binary, immediate)
        $feed = $this->feedManager->getFeed($openPost->threadId);
        if ($feed !== null) {
            $feed->broadcastBinary($broadcast);
            $feed->updateOpenBody($openPost->postId, $openPost->getBody());
        }
    }
}
