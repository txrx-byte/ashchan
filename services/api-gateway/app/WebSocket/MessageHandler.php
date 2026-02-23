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

use App\Feed\ThreadFeedManager;
use App\WebSocket\Handler\AppendHandler;
use App\WebSocket\Handler\BackspaceHandler;
use App\WebSocket\Handler\ClosePostHandler;
use App\WebSocket\Handler\InsertPostHandler;
use App\WebSocket\Handler\ReclaimHandler;
use App\WebSocket\Handler\SpliceHandler;
use App\WebSocket\Handler\SynchroniseHandler;
use Psr\Log\LoggerInterface;
use Swoole\WebSocket\Server as WsServer;

/**
 * Routes incoming WebSocket messages to the appropriate handler.
 *
 * Text messages: first 2 chars are the zero-padded type code.
 * Binary messages: last byte is the type byte.
 *
 * Supports:
 * - Text type 30 (Synchronise): subscribe to a thread feed
 * - Text type 34 (NOOP): keepalive, no action
 * - Text type 01 (InsertPost): allocate an open post
 * - Text type 05 (ClosePost): finalize an open post
 * - Text type 31 (Reclaim): reclaim an open post after disconnect
 * - Binary type 0x02 (Append): character streaming
 * - Binary type 0x03 (Backspace): delete last character
 * - Binary type 0x04 (Splice): arbitrary text replacement
 *
 * @see docs/LIVEPOSTING.md §4.2, §5.2
 */
final class MessageHandler
{
    /** Text message type codes. */
    public const TEXT_INSERT_POST  = 1;
    public const TEXT_CLOSE_POST   = 5;
    public const TEXT_INSERT_IMAGE = 6;
    public const TEXT_SYNCHRONISE  = 30;
    public const TEXT_RECLAIM      = 31;
    public const TEXT_NOOP         = 34;

    public function __construct(
        private readonly ThreadFeedManager $feedManager,
        private readonly SynchroniseHandler $syncHandler,
        private readonly InsertPostHandler $insertPostHandler,
        private readonly ClosePostHandler $closePostHandler,
        private readonly ReclaimHandler $reclaimHandler,
        private readonly AppendHandler $appendHandler,
        private readonly BackspaceHandler $backspaceHandler,
        private readonly SpliceHandler $spliceHandler,
        private readonly WsServer $server,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle a text WebSocket message.
     *
     * @param int    $fd   Swoole file descriptor
     * @param string $data Raw text frame data
     * @param ClientConnection $conn Client connection state
     */
    public function handleText(int $fd, string $data, ClientConnection $conn): void
    {
        if (strlen($data) < 2) {
            $this->logger->warning('Invalid text message: too short', ['fd' => $fd]);
            return;
        }

        $type = (int) substr($data, 0, 2);
        $payloadStr = substr($data, 2);

        $conn->touch();

        switch ($type) {
            case self::TEXT_SYNCHRONISE:
                $this->handleSynchronise($fd, $payloadStr, $conn);
                break;

            case self::TEXT_NOOP:
                // Keepalive — no action required
                break;

            case self::TEXT_INSERT_POST:
                $this->handleInsertPost($fd, $payloadStr, $conn);
                break;

            case self::TEXT_CLOSE_POST:
                $this->closePostHandler->handle($fd, $conn);
                break;

            case self::TEXT_RECLAIM:
                $this->handleReclaim($fd, $payloadStr, $conn);
                break;

            case self::TEXT_INSERT_IMAGE:
                // Phase 3: not yet implemented
                $this->sendError($fd, 'Image attachment is not yet available');
                break;

            default:
                $this->logger->warning('Unknown text message type', [
                    'fd'   => $fd,
                    'type' => $type,
                ]);
                break;
        }
    }

    /**
     * Handle a binary WebSocket message.
     *
     * @param int    $fd   Swoole file descriptor
     * @param string $data Raw binary frame data
     * @param ClientConnection $conn Client connection state
     */
    public function handleBinary(int $fd, string $data, ClientConnection $conn): void
    {
        if (strlen($data) < 1) {
            $this->logger->warning('Empty binary frame', ['fd' => $fd]);
            return;
        }

        $conn->touch();

        $type = ord($data[strlen($data) - 1]);

        switch ($type) {
            case BinaryProtocol::TYPE_APPEND:
                // Strip the type byte (last byte) and pass the char bytes
                $charData = substr($data, 0, -1);
                $this->appendHandler->handle($fd, $charData, $conn);
                break;

            case BinaryProtocol::TYPE_BACKSPACE:
                $this->backspaceHandler->handle($fd, $conn);
                break;

            case BinaryProtocol::TYPE_SPLICE:
                // Strip the type byte (last byte) and pass the splice payload
                $spliceData = substr($data, 0, -1);
                $this->spliceHandler->handle($fd, $spliceData, $conn);
                break;

            default:
                $this->logger->warning('Unknown binary message type', [
                    'fd'   => $fd,
                    'type' => $type,
                ]);
                break;
        }
    }

    /**
     * Handle Synchronise (type 30): subscribe the client to a thread feed.
     */
    private function handleSynchronise(int $fd, string $payloadStr, ClientConnection $conn): void
    {
        if ($payloadStr === '' || $payloadStr === false) {
            $this->sendError($fd, 'Missing synchronise payload');
            return;
        }

        try {
            /** @var array{board?: string, thread?: int}|null $payload */
            $payload = json_decode($payloadStr, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->sendError($fd, 'Invalid JSON in synchronise');
            return;
        }

        if (!is_array($payload)) {
            $this->sendError($fd, 'Invalid synchronise payload');
            return;
        }

        $board = $payload['board'] ?? null;
        $threadId = $payload['thread'] ?? null;

        if (!is_string($board) || $board === '' || !is_int($threadId) || $threadId <= 0) {
            $this->sendError($fd, 'Invalid board or thread in synchronise');
            return;
        }

        $this->syncHandler->handle($fd, $conn, $board, $threadId);
    }

    /**
     * Handle InsertPost (type 01): allocate an open post.
     */
    private function handleInsertPost(int $fd, string $payloadStr, ClientConnection $conn): void
    {
        $payload = $this->decodeJsonPayload($fd, $payloadStr, 'InsertPost');
        if ($payload === null) {
            return;
        }

        $this->insertPostHandler->handle($fd, $conn, $payload);
    }

    /**
     * Handle Reclaim (type 31): reclaim an open post after disconnect.
     */
    private function handleReclaim(int $fd, string $payloadStr, ClientConnection $conn): void
    {
        $payload = $this->decodeJsonPayload($fd, $payloadStr, 'Reclaim');
        if ($payload === null) {
            return;
        }

        $this->reclaimHandler->handle($fd, $conn, $payload);
    }

    /**
     * Decode a JSON payload string, returning null on failure.
     *
     * @return array<string, mixed>|null
     */
    private function decodeJsonPayload(int $fd, string $payloadStr, string $context): ?array
    {
        if ($payloadStr === '' || $payloadStr === false) {
            $this->sendError($fd, "Missing {$context} payload");
            return null;
        }

        try {
            $payload = json_decode($payloadStr, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->sendError($fd, "Invalid JSON in {$context}");
            return null;
        }

        if (!is_array($payload)) {
            $this->sendError($fd, "Invalid {$context} payload");
            return null;
        }

        return $payload;
    }

    /**
     * Send an error message to a client (text frame, no type prefix).
     */
    private function sendError(int $fd, string $message): void
    {
        if ($this->server->isEstablished($fd)) {
            $errorMsg = BinaryProtocol::encodeTextMessage(0, ['error' => $message]);
            $this->server->push($fd, $errorMsg, WEBSOCKET_OPCODE_TEXT);
        }
    }
}
