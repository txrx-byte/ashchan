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
use App\WebSocket\Handler\SynchroniseHandler;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WsServer;

/**
 * Swoole WebSocket server controller.
 *
 * Handles the raw Swoole callbacks: onHandShake, onMessage, onClose.
 * The primary server listener is changed from SERVER_HTTP to SERVER_WEBSOCKET,
 * which automatically delegates non-WebSocket HTTP requests to the existing
 * Hyperf HTTP handler.
 *
 * Lifecycle:
 * 1. onHandShake: Validate path (/api/socket), extract IP, rate-limit, perform WS handshake
 * 2. onMessage:   Route text/binary frames to MessageHandler
 * 3. onClose:     Cleanup: close open post, unsubscribe from feed, decrement IP count
 *
 * @see docs/LIVEPOSTING.md §5.2
 */
final class WebSocketController
{
    /** Path for WebSocket upgrades. */
    private const WS_PATH = '/api/socket';

    /**
     * Worker-local client connection registry: fd → ClientConnection.
     *
     * @var array<int, ClientConnection>
     */
    private array $clients = [];

    /** Worker-local ThreadFeedManager (created per-worker in onWorkerStart). */
    private ?ThreadFeedManager $feedManager = null;

    /** Worker-local MessageHandler (created lazily). */
    private ?MessageHandler $messageHandler = null;

    /** Worker-local SynchroniseHandler. */
    private ?SynchroniseHandler $syncHandler = null;

    /** Cached WsServer reference (set on first onMessage/onClose call). */
    private ?WsServer $serverRef = null;

    /** Current Swoole worker ID. */
    private int $workerId = 0;

    /**
     * Maximum connections per IP (per worker, Phase 1).
     */
    private readonly int $maxConnectionsPerIp;

    /**
     * Worker-local IP connection counts for handshake-time rate limiting.
     * ipHash → count
     *
     * @var array<string, int>
     */
    private array $ipCounts = [];

    /** Feature flag: is liveposting enabled? */
    private bool $enabled;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->enabled = filter_var(
            getenv('LIVEPOSTING_ENABLED') ?: 'false',
            FILTER_VALIDATE_BOOLEAN,
        );
        $this->maxConnectionsPerIp = (int) (getenv('WS_MAX_CONNECTIONS_PER_IP') ?: 16);
    }

    /**
     * Called by Swoole when a new WebSocket handshake request arrives.
     *
     * Validates the upgrade path, extracts the client IP, checks connection
     * limits, and completes the WebSocket handshake manually.
     */
    public function onHandShake(Request $request, Response $response): bool
    {
        // Feature flag check
        if (!$this->enabled) {
            $response->status(503);
            $response->end('Liveposting is not enabled');
            return false;
        }

        // Validate path
        $path = $request->server['request_uri'] ?? '';
        if ($path !== self::WS_PATH) {
            $response->status(404);
            $response->end('Not found');
            return false;
        }

        // Validate WebSocket upgrade headers
        $secKey = $request->header['sec-websocket-key'] ?? '';
        if ($secKey === '') {
            $response->status(400);
            $response->end('Missing Sec-WebSocket-Key');
            return false;
        }

        // Extract client IP (Cloudflare → nginx → direct)
        $ip = $this->extractClientIp($request);
        $fd = $request->fd;

        // Check IP connection limit (worker-local in Phase 1)
        $ipHash = hash('xxh3', $ip);
        $currentCount = $this->ipCounts[$ipHash] ?? 0;
        if ($currentCount >= $this->maxConnectionsPerIp) {
            $this->logger->warning('IP connection limit exceeded at handshake', [
                'ip'    => $ip,
                'count' => $currentCount,
            ]);
            $response->status(429);
            $response->end('Too many connections from this IP');
            return false;
        }

        // Increment IP count
        $this->ipCounts[$ipHash] = $currentCount + 1;

        // Create connection state
        $conn = new ClientConnection(
            fd: $fd,
            ip: $ip,
            connectedAt: time(),
            workerId: $this->workerId,
        );

        // Store in worker-local registry
        $this->clients[$fd] = $conn;

        // Complete the WebSocket handshake (RFC 6455)
        $acceptKey = base64_encode(
            sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)
        );

        $response->status(101);
        $response->header('Upgrade', 'websocket');
        $response->header('Connection', 'Upgrade');
        $response->header('Sec-WebSocket-Accept', $acceptKey);

        // Echo back the requested subprotocol if it matches ours
        $protocol = $request->header['sec-websocket-protocol'] ?? '';
        if (str_contains($protocol, 'ashchan-v1')) {
            $response->header('Sec-WebSocket-Protocol', 'ashchan-v1');
        }

        $response->end();

        $this->logger->info('WebSocket connected', [
            'fd' => $fd,
            'ip' => $ip,
        ]);

        return true;
    }

    /**
     * Called by Swoole when a WebSocket message is received.
     */
    public function onMessage(WsServer $server, Frame $frame): void
    {
        // Cache server reference for lazy initialization
        $this->serverRef = $server;

        $fd = $frame->fd;
        $conn = $this->clients[$fd] ?? null;

        if ($conn === null) {
            $this->logger->warning('Message from unknown fd', ['fd' => $fd]);
            return;
        }

        // Ensure message handler is initialized
        $this->ensureMessageHandler($server);

        /** @var MessageHandler $handler */
        $handler = $this->messageHandler;

        if ($frame->opcode === WEBSOCKET_OPCODE_BINARY) {
            $handler->handleBinary($fd, $frame->data, $conn);
        } elseif ($frame->opcode === WEBSOCKET_OPCODE_TEXT) {
            $handler->handleText($fd, $frame->data, $conn);
        } elseif ($frame->opcode === WEBSOCKET_OPCODE_PING) {
            // Respond with pong
            if ($server->isEstablished($fd)) {
                $server->push($fd, '', WEBSOCKET_OPCODE_PONG);
            }
        }
        // PONG frames are silently consumed (handled automatically by Swoole)
    }

    /**
     * Called by Swoole when a WebSocket connection is closed.
     */
    public function onClose(WsServer $server, int $fd, int $reactorId): void
    {
        $this->serverRef = $server;

        $conn = $this->clients[$fd] ?? null;
        if ($conn === null) {
            // Not a WebSocket connection (may be a regular HTTP request closing)
            return;
        }

        // Unsubscribe from thread feed
        if ($conn->isSynced() && $this->feedManager !== null) {
            // Phase 2: close any open post here (persist body, broadcast close)
            $this->feedManager->unsubscribe($fd, $conn->threadId);
        }

        // Decrement IP connection count (worker-local)
        $ipHash = hash('xxh3', $conn->ip);
        $currentCount = $this->ipCounts[$ipHash] ?? 0;
        if ($currentCount <= 1) {
            unset($this->ipCounts[$ipHash]);
        } else {
            $this->ipCounts[$ipHash] = $currentCount - 1;
        }

        // Remove from worker-local registry
        unset($this->clients[$fd]);

        $this->logger->info('WebSocket disconnected', [
            'fd'        => $fd,
            'ip'        => $conn->ip,
            'thread_id' => $conn->threadId,
        ]);
    }

    /**
     * Called by Swoole on worker start. Used to initialize per-worker state.
     *
     * NOTE: Not automatically registered in server.php because Hyperf owns
     * the ON_WORKER_START callback. In Phase 1, feedManager is created lazily
     * via ensureInitialized() using $server->worker_id. In Phase 2, register
     * this via a Hyperf BeforeWorkerStart listener to create Swoole Tables.
     */
    public function onWorkerStart(WsServer $server, int $workerId): void
    {
        $this->workerId = $workerId;

        if ($this->enabled) {
            $this->feedManager = new ThreadFeedManager($server, $this->logger, $workerId);

            $this->logger->info('WebSocket worker started', [
                'worker_id' => $workerId,
                'enabled'   => true,
            ]);
        }
    }

    /**
     * Called by Swoole when a message is received from another worker via IPC.
     *
     * Used for cross-worker broadcasting: when a client types on worker 0,
     * the binary frame is forwarded to worker 1 via sendMessage(), and this
     * callback pushes it to worker 1's local clients watching the same thread.
     *
     * Phase 2: Implement cross-worker message forwarding.
     */
    public function onPipeMessage(WsServer $server, int $srcWorkerId, mixed $data): void
    {
        // Phase 2: decode the forwarded message, find local clients for the
        // target thread, and push to them.
        $this->logger->debug('PipeMessage received', [
            'src_worker' => $srcWorkerId,
            'worker_id'  => $this->workerId,
        ]);
    }

    /**
     * Get metrics for the health endpoint.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        if ($this->feedManager === null) {
            return [
                'liveposting_enabled' => $this->enabled,
                'connections'         => 0,
                'feeds'               => 0,
            ];
        }

        $feedMetrics = $this->feedManager->getMetrics();

        return [
            'liveposting_enabled'       => $this->enabled,
            'connections'               => count($this->clients),
            'feeds'                     => $feedMetrics['feeds'],
            'unique_ips'                => $feedMetrics['unique_ips'],
            'worker_id'                 => $this->workerId,
            'worker_memory_bytes'       => memory_get_usage(true),
            'worker_memory_peak_bytes'  => memory_get_peak_usage(true),
        ];
    }

    /**
     * Extract client IP from request headers (Cloudflare → nginx → direct).
     */
    private function extractClientIp(Request $request): string
    {
        // Cloudflare sets CF-Connecting-IP for the true client IP
        $cfIp = $request->header['cf-connecting-ip'] ?? '';
        if ($cfIp !== '') {
            return $cfIp;
        }

        // nginx sets X-Real-IP or X-Forwarded-For
        $realIp = $request->header['x-real-ip'] ?? '';
        if ($realIp !== '') {
            return $realIp;
        }

        $forwardedFor = $request->header['x-forwarded-for'] ?? '';
        if ($forwardedFor !== '') {
            // Take the first IP (leftmost = original client)
            $parts = explode(',', $forwardedFor, 2);
            return trim($parts[0]);
        }

        // Fall back to direct connection IP
        return $request->server['remote_addr'] ?? '127.0.0.1';
    }

    /**
     * Ensure the feed manager is initialized for this worker.
     *
     * Called lazily since the WsServer instance is not available during
     * construction. The server reference is obtained from the onMessage/
     * onClose callback parameters.
     */
    private function ensureInitialized(WsServer $server): void
    {
        if ($this->feedManager !== null) {
            return;
        }

        $this->serverRef = $server;
        $this->workerId = $server->worker_id;
        $this->feedManager = new ThreadFeedManager($server, $this->logger, $this->workerId);
    }

    /**
     * Lazily initialize the message handler.
     */
    private function ensureMessageHandler(WsServer $server): void
    {
        if ($this->messageHandler !== null) {
            return;
        }

        $this->ensureInitialized($server);

        $this->syncHandler = new SynchroniseHandler(
            $this->feedManager,
            $server,
            $this->logger,
        );

        $this->messageHandler = new MessageHandler(
            $this->feedManager,
            $this->syncHandler,
            $server,
            $this->logger,
        );
    }
}
