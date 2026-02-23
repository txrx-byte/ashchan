# Liveposting — Real-Time Post Streaming for Ashchan

> **Status:** Phase 3 Complete (Client)  
> **Date:** 2026-02-23  
> **Source Study:** meguca imageboard (Go/Gorilla WebSocket)  
> **Target Stack:** Hyperf 3.1 / Swoole (PHP-CLI)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Meguca Liveposting Architecture Analysis](#2-meguca-liveposting-architecture-analysis)
3. [Ashchan Liveposting Design](#3-ashchan-liveposting-design)
4. [WebSocket Protocol Specification](#4-websocket-protocol-specification)
5. [Server-Side Implementation](#5-server-side-implementation)
6. [Client-Side Implementation](#6-client-side-implementation)
7. [Database Schema Changes](#7-database-schema-changes)
8. [Event Bus Integration](#8-event-bus-integration)
9. [Caching and Performance](#9-caching-and-performance)
10. [Security and Anti-Spam](#10-security-and-anti-spam)
11. [Infrastructure Changes](#11-infrastructure-changes)
12. [Migration Strategy](#12-migration-strategy)
13. [Testing Plan](#13-testing-plan)
14. [Known Risks and Mitigations](#14-known-risks-and-mitigations)

---

## 1. Executive Summary

Liveposting enables users viewing a thread to see other users' posts materialize character-by-character in real time, directly in the thread view, as they are being typed. This transforms the traditional imageboard "compose → submit → refresh" model into a live, conversational experience that dramatically increases engagement.

### Key Capabilities

- **Open posts:** A post is allocated on the server when the user starts typing, and stays "open" (editable) until explicitly closed or timed out.
- **Character streaming:** Each keystroke (append, backspace, splice) is broadcast to all clients watching the same thread within ~100ms.
- **Post reclamation:** If a user disconnects, they can reclaim their open post upon reconnection using a password hash.
- **Graceful degradation:** Liveposting is additive; the traditional "submit a finished post" flow remains available and functional.

### Why Hyperf/Swoole Is Ideal

Swoole's coroutine-based event loop, native WebSocket server support, shared-memory data structures, and low per-connection overhead make it a natural fit for this workload. Unlike meguca's Go goroutines + channels pattern, Swoole provides:

- **Native WebSocket server** as a first-class Swoole server type (no external library needed).
- **Coroutine channels** (`Swoole\Coroutine\Channel`) for lock-free message routing — directly analogous to Go channels.
- **Swoole Tables** for zero-copy, cross-worker shared state — faster than Redis for hot path data like open post bodies and client-to-feed mappings.
- **100k+ concurrent connections** per process with ~2KB memory overhead per connection.
- **Sub-millisecond message dispatch** via in-process fan-out, avoiding network round-trips.

---

## 2. Meguca Liveposting Architecture Analysis

### 2.1 Core Concepts

Meguca's liveposting is built around four pillars:

#### Open Posts
A post is created in an "editing" state (`Editing: true`). While open, the author can modify it character by character. Other viewers see these changes in real time. The post body is stored in an embedded database (`SetOpenBody`) and cached in-memory. On close, the body is parsed for links, commands, and other markup.

**Key data structure** (`websockets/open_post.go`):
```go
type openPost struct {
    isSpoilered bool
    len, lines  int
    id, op      uint64
    time        int64
    body        []byte
    board       string
}
```

#### Feed System
Each thread has a `Feed` — a long-lived goroutine that manages:
- A set of subscribed WebSocket clients
- An in-memory `threadCache` of recent post states
- A message buffer that flushes every 100ms (via `TickerInterval`)
- Channels for each mutation type (insert, close, splice, etc.)

**Feed architecture** (`websockets/feeds/feed.go`):
```
        ┌───────────┐
        │   Feed    │  One per active thread
        │ goroutine │
        └─────┬─────┘
              │ select {} loop
   ┌──────────┼──────────────────────────────────┐
   │          │          │         │              │
add/remove  send    insertPost  closePost   setOpenBody
 clients   (text)   (creation)  (finalize)  (body cache)
   │          │          │         │              │
   └──────────┴──────────┴─────────┴──────────────┘
              │
              ▼ flush every 100ms
         sendToAll(buf) → each client.Send(msg)
```

Messages are buffered using `MessageConcat` — multiple small updates are concatenated into a single WebSocket frame to reduce transport overhead.

#### Client State Machine
The TypeScript client uses a finite state machine (`postSM`) with states: `none → ready → draft → allocating → alloc → halted/done`. Transitions correspond to connection events, user actions, and server responses.

```
none ──sync──► ready ──open──► draft ──sentAllocRequest──► allocating
                                                              │
                                                           alloc
                                                              │
                   done ◄──────────────────────────────── alloc (editing)
                                                              │
                                                         disconnect
                                                              │
                                                           halted
                                                              │
                                                           reclaim
                                                              │
                                                           alloc ◄────┘
```

#### Binary Wire Protocol
Meguca optimizes the hot path (append, backspace, splice) with a **binary WebSocket protocol** to minimize serialization overhead:

| Message | Format | Size |
|---------|--------|------|
| Append | `[postID:f64LE][char:utf8][type:u8]` | 10-13 bytes |
| Backspace | `[postID:f64LE][type:u8]` | 9 bytes |
| Splice | `[postID:f64LE][start:u16LE][len:u16LE][text:utf8][type:u8]` | 13+ bytes |

The post ID is encoded as `float64` (little-endian) for fast JavaScript `DataView.getFloat64()` decoding. The message type byte is appended as the **last byte** to allow variable-length payloads.

### 2.2 Message Flow (Keystroke → Broadcast)

```
User types 'a'
    │
    ▼
Client: FormModel.parseInput()
    │ detects single-char append
    ▼
Client: sendBinary([utf8('a'), MessageAppend])
    │
    ▼ WebSocket frame
    │
Server: Client.handleMessage() → BinaryMessage → runHandlerBinary()
    │ typ = MessageAppend
    ▼
Server: Client.appendRune(data)
    │ 1. Validate: post is open, body length < 2000
    │ 2. Build broadcast msg: [postID:f64LE][char:utf8][MessageAppend:u8]
    │ 3. Append char to c.post.body
    │ 4. Call c.updateBodyBinary(msg)
    ▼
Server: Feed.UpdateBody(id, body, msg)
    │ 1. Send binary msg to binaryMessages channel
    │ 2. Send body update to setOpenBody channel
    ▼
Server: Feed goroutine select loop
    │ case binaryMessages: sendToAllBinary(msg)
    │ case setOpenBody: updateCachedPost(id, body)
    ▼
Server: baseFeed.sendToAllBinary(msg)
    │ for each client: client.SendBinary(msg)
    ▼
Each watching Client: sendExternal channel → listenerLoop → conn.WriteMessage(BinaryMessage, msg)
    │
    ▼ WebSocket frame to each browser
    │
Client: onMessage(ArrayBuffer)
    │ msgType = last byte = MessageAppend
    │ id = DataView.getFloat64(0, true)
    │ char = TextDecoder.decode(slice(8))
    ▼
Client: Post.appendString(char)
    │ DOM update: append text node or reparse body
    ▼
User sees 'a' appear in the post
```

### 2.3 Key Design Decisions in Meguca

1. **Per-thread feed goroutines** — isolates concurrency; a stalled thread cannot affect others.
2. **100ms tick buffer** — batches rapid keystrokes into fewer WebSocket frames.
3. **Binary protocol for hot path** — text JSON only for infrequent messages (sync, close, insert).
4. **Post passwords** — allow reclaiming open posts after disconnection (bcrypt-hashed).
5. **Thread cache in memory** — recent posts (last 16 min) kept in-memory for instant sync.
6. **IP-based connection limiting** — max 16 concurrent WebSocket connections per IP.
7. **Spam scoring** — each character costs spam points; exceeding the threshold requires captcha.

---

## 3. Ashchan Liveposting Design

### 3.1 Architecture Overview

Liveposting runs inside the **API Gateway** service (port 9501), leveraging Swoole's built-in WebSocket server. The gateway already serves as the single public entry point and handles all client-facing traffic.

```
Browser
  │
  ▼ wss://ashchan.net/api/socket
  │
Cloudflare (TLS 1.3, CF-Connecting-IP)
  │
  ▼ cloudflared tunnel (outbound-only)
  │
nginx (:80)
  │ /api/socket → WebSocket upgrade
  │ (bypasses Anubis PoW and Varnish)
  ▼
Swoole WebSocket Server (api-gateway :9501)
  │
  ├── ThreadFeedManager (Swoole Table + coroutine channels)
  │     └── ThreadFeed (one per active thread)
  │           ├── Client set
  │           ├── OpenPost body cache
  │           └── 100ms flush ticker
  │
  ├── boards-threads-posts (:9503) via mTLS
  │     └── POST creation, body persistence, close
  │
  └── moderation-anti-spam (:9506) via mTLS
        └── Spam scoring, ban checks
```

### 3.2 Architecture Decision: Gateway vs Dedicated Service

An important question is whether liveposting should run inside the API Gateway or as a separate dedicated service (e.g., `liveposting:9507`).

**Decision: Run inside the API Gateway, with a migration path to extraction.**

| Factor | Gateway (chosen) | Dedicated Service |
|--------|-------------------|--------------------|
| Deployment risk | Redeploying gateway disconnects all WS clients | Independent lifecycle |
| Operational simplicity | No new service to manage, monitor, or certificate | Another mTLS endpoint, systemd unit, health check |
| Shared state | Direct access to auth middleware, rate limiter, ban cache | Must duplicate or RPC for every handshake |
| Latency | Zero-hop for HTTP→WS upgrade on same port | Extra proxy hop or separate DNS |
| nginx routing | Single upstream, path-based split | Separate upstream block |
| Cloudflare tunnel | Works as-is (same origin) | May need separate tunnel route |

**Rationale:** At our current scale, the operational cost of a 7th service outweighs the deployment-isolation benefit. The gateway already handles all client-facing traffic, and Swoole's graceful restart (`SIGUSR1`) performs a rolling worker reload that drains existing WebSocket connections before spawning new ones — meaning a gateway redeployment does **not** necessarily sever all connections simultaneously.

**Migration path:** If liveposting grows to dominate gateway resource usage or deployment cadence diverges, the WebSocket components are cleanly isolated in `app/WebSocket/` and `app/Feed/` and can be extracted into `services/liveposting/` with minimal refactoring. The client only knows the `/api/socket` path, which nginx can re-route transparently.

**Graceful restart procedure for zero-downtime deploys:**
```bash
# Rolling worker reload — existing WS connections finish on old workers
kill -USR1 $(cat runtime/hyperf.pid)
# Swoole spawns new workers, old workers drain and exit after idle timeout
```

If truly zero-disconnect deploys are required (e.g., during schema migrations that change the WS protocol), a blue/green deployment with nginx upstream switching is the recommended approach.

### 3.3 Key Differences from Meguca

| Aspect | Meguca (Go) | Ashchan (Swoole) |
|--------|-------------|------------------|
| Concurrency model | Goroutines + channels | Swoole coroutines + channels |
| Per-thread isolation | Goroutine per feed | Coroutine per feed |
| Shared state | Go maps + sync.RWMutex | Swoole Table (lock-free, shared memory) |
| WebSocket server | gorilla/websocket (HTTP upgrade) | Swoole native WebSocket server |
| Message buffering | Go ticker + string slice | Swoole Timer + SplQueue |
| Connection limit | Go map + mutex | Swoole Table atomic counter |
| Post persistence | Embedded BoltDB | PostgreSQL (async via coroutine) |
| Client ↔ Feed routing | Go channel sends | Swoole Channel + direct method calls |
| Binary protocol | Custom binary frames | Same binary frame format (compatible) |
| Multi-worker | Single process | Multiple Swoole workers (shared via Swoole Table) |

### 3.4 Component Map

```
services/api-gateway/
├── app/
│   ├── WebSocket/
│   │   ├── WebSocketController.php      # Swoole onOpen/onMessage/onClose
│   │   ├── MessageHandler.php           # Route message types to handlers
│   │   ├── ClientConnection.php         # Per-connection state (VO)
│   │   ├── BinaryProtocol.php           # Encode/decode binary frames
│   │   └── Handler/
│   │       ├── SynchroniseHandler.php   # Sync client to thread feed
│   │       ├── AppendHandler.php        # Handle character append
│   │       ├── BackspaceHandler.php     # Handle backspace
│   │       ├── SpliceHandler.php        # Handle text splice
│   │       ├── ClosePostHandler.php     # Close an open post
│   │       ├── InsertPostHandler.php    # Allocate a new post
│   │       ├── ReclaimHandler.php       # Reclaim post after disconnect
│   │       └── InsertImageHandler.php   # Attach image to open post
│   ├── Feed/
│   │   ├── ThreadFeedManager.php        # Registry of active feeds
│   │   ├── ThreadFeed.php               # Per-thread state + broadcast
│   │   ├── FeedCache.php                # In-memory post state cache
│   │   └── MessageBuffer.php           # 100ms batched flush
│   └── Process/
│       └── FeedGarbageCollector.php     # Evict idle feeds (Swoole Process)
├── config/
│   └── autoload/
│       └── server.php                   # Add WebSocket server listener
```

---

## 4. WebSocket Protocol Specification

### 4.1 Connection Lifecycle

```
Client                          Server
  │                               │
  ├── GET /api/socket ──────────► │  HTTP Upgrade
  │   Sec-WebSocket-Protocol:     │
  │   ashchan-v1                  │
  │                               │
  │ ◄── 101 Switching Protocols ──┤  onOpen: register fd, rate-limit check
  │                               │
  ├── TEXT synchronise ──────────►│  Subscribe to thread feed
  │   {"board":"g","thread":123}  │
  │                               │
  │ ◄── TEXT 30{...configs...} ───┤  Board configs
  │ ◄── TEXT 30{...recent...} ────┤  Sync message (recent post states)
  │ ◄── TEXT 36{...time...} ──────┤  Server time
  │                               │
  │     ... live session ...      │
  │                               │
  ├── CLOSE 1000 ────────────────►│  onClose: unsubscribe, close open post
  │ ◄── CLOSE 1000 ──────────────┤
```

### 4.2 Message Types

#### Text Messages (JSON)

Message type is the first two characters (zero-padded integer), followed by JSON payload.

| Code | Name | Direction | Payload |
|------|------|-----------|---------|
| `01` | InsertPost | S→C | `Post` object |
| `05` | ClosePost | S→C | `{id, links, commands}` |
| `06` | InsertImage | S→C | `{id, image}` |
| `07` | Spoiler | S→C | post ID |
| `30` | Synchronise | Both | `{board, thread}` (C→S) / `{recent, moderation}` (S→C) |
| `31` | Reclaim | Both | `{id, password}` (C→S) / result code (S→C) |
| `32` | PostID | S→C | new post ID |
| `33` | Concat | S→C | array of concatenated messages |
| `34` | NOOP | C→S | null (keepalive) |
| `35` | SyncCount | S→C | `{active, total}` |
| `36` | ServerTime | S→C | Unix timestamp |
| `37` | Redirect | S→C | board path |
| `38` | Captcha | S→C | captcha requirement |
| `39` | Configs | S→C | board configuration |

#### Binary Messages (hot path)

The message type byte is the **last byte** of the frame. Post IDs are encoded as `float64` little-endian (8 bytes) for fast JS `DataView` decoding.

| Type Byte | Name | C→S Format | S→C Broadcast Format |
|-----------|------|------------|---------------------|
| `0x02` | Append | `[char:utf8][0x02]` | `[postID:f64LE][char:utf8][0x02]` |
| `0x03` | Backspace | `[0x03]` | `[postID:f64LE][0x03]` |
| `0x04` | Splice | `[start:u16LE][len:u16LE][text:utf8][0x04]` | `[postID:f64LE][start:u16LE][len:u16LE][text:utf8][0x04]` |

### 4.3 Synchronisation Protocol

When a client connects to a thread, the server sends a sync message containing the state of all posts created within the last 16 minutes. The client compares this against its local DOM state and resolves differences:

- **Post in sync but not in DOM** → fetch and insert.
- **Post in DOM but closed on server** → close locally.
- **Post body differs** → update body.
- **Post open on server** → mark as editing (show live cursor).

---

## 5. Server-Side Implementation

### 5.1 Swoole WebSocket Server Configuration

Add a WebSocket server listener to the existing HTTP server in `config/autoload/server.php`:

```php
// In the 'servers' array, modify the primary HTTP server to support WebSocket:
[
    'name' => 'http',
    'type' => Hyperf\Server\Server::SERVER_WEBSOCKET,  // Changed from SERVER_HTTP
    'host' => '0.0.0.0',
    'port' => (int) (getenv('PORT') ?: 9501),
    'sock_type' => SWOOLE_SOCK_TCP,
    'callbacks' => [
        Event::ON_REQUEST  => [Hyperf\HttpServer\Server::class, 'onRequest'],
        Event::ON_HAND_SHAKE => [App\WebSocket\WebSocketController::class, 'onHandShake'],
        Event::ON_MESSAGE  => [App\WebSocket\WebSocketController::class, 'onMessage'],
        Event::ON_CLOSE    => [App\WebSocket\WebSocketController::class, 'onClose'],
    ],
],
```

This allows the same port to handle both HTTP requests and WebSocket upgrades — the Swoole server will route based on whether the request is an HTTP upgrade or a regular request.

### 5.2 WebSocketController

```php
final class WebSocketController
{
    // Swoole onHandShake: validate upgrade path, rate-limit, extract IP
    public function onHandShake(Request $request, Response $response): void
    {
        // 1. Verify path is /api/socket
        // 2. Extract client IP (CF-Connecting-IP → X-Forwarded-For → remote)
        // 3. Check IP connection count (Swoole Table, max 16 per IP)
        // 4. Check IP ban (mTLS call to moderation-anti-spam)
        // 5. Perform WebSocket handshake
        // 6. Register connection in ConnectionTable
    }

    // Swoole onMessage: route to MessageHandler
    public function onMessage(Server $server, Frame $frame): void
    {
        // Binary frames → BinaryProtocol::decode() → route by type byte
        // Text frames   → parse first 2 chars as type → route by type
    }

    // Swoole onClose: cleanup
    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        // 1. Close any open post (persist body, broadcast close)
        // 2. Remove from ThreadFeed
        // 3. Decrement IP connection count
        // 4. Remove from ConnectionTable
    }
}
```

### 5.3 ClientConnection (Value Object)

```php
final class ClientConnection
{
    public function __construct(
        public readonly int $fd,              // Swoole file descriptor
        public readonly string $ip,           // Client IP
        public readonly int $connectedAt,     // Unix timestamp
        public ?int $threadId = null,         // Currently synced thread (null = board page)
        public ?string $board = null,         // Currently synced board
        public ?OpenPost $openPost = null,    // Currently editing post
        public int $lastActivity = 0,        // Last message timestamp
        public bool $synced = false,          // Has completed synchronisation
    ) {}
}
```

### 5.4 ThreadFeedManager

The feed manager is a singleton that maps thread IDs to `ThreadFeed` instances. It uses a `Swoole\Table` for cross-worker visibility of which threads have active feeds, but each worker maintains its own `ThreadFeed` objects (since WebSocket fd's are worker-local in Swoole).

```php
final class ThreadFeedManager
{
    // Swoole Table: thread_id → worker_id mapping (for cross-worker routing)
    private \Swoole\Table $feedRegistry;

    // Worker-local feeds: thread_id → ThreadFeed
    private array $feeds = [];

    public function getOrCreate(int $threadId): ThreadFeed;
    public function remove(int $threadId): void;
    public function getFeed(int $threadId): ?ThreadFeed;
}
```

**Cross-worker broadcasting:** When a user types and the Feed broadcasts, it only reaches clients connected to the same Swoole worker. For full fan-out, we use the Swoole server's `$server->sendMessage()` IPC to forward binary frames to other workers, which then call `$server->push($fd, $data)` for their local clients.

```
Worker 0: Client A types 'a'
    │
    ├── Local broadcast to Worker 0 clients
    │
    └── $server->sendMessage($binaryFrame, workerId: 1)
        └── Worker 1: receives via onPipeMessage
            └── push to Worker 1 clients watching same thread
```

### 5.5 ThreadFeed

```php
final class ThreadFeed
{
    private int $threadId;
    private array $clients = [];           // fd → ClientConnection
    private array $openPostBodies = [];    // postId → string (current body)
    private array $recentPosts = [];       // postId → CachedPost
    private \SplQueue $messageBuffer;      // Pending text messages
    private int $timerId;                  // Swoole Timer ID for 100ms flush

    public function addClient(int $fd, ClientConnection $conn): void;
    public function removeClient(int $fd): bool; // returns true if feed empty
    public function broadcastBinary(string $data): void;
    public function broadcastText(string $data): void;
    public function updateOpenBody(int $postId, string $body): void;
    public function getSyncMessage(): string;
    public function getActiveIpCount(): int;

    // Start/stop the 100ms flush timer
    private function startTicker(): void
    {
        $this->timerId = \Swoole\Timer::tick(100, function () {
            $this->flush();
        });
    }

    // Guard flag prevents timer callback stacking if flush is slow
    private bool $flushing = false;

    private function flush(): void
    {
        if ($this->flushing) {
            return; // Previous flush still in progress — skip this tick
        }
        if ($this->messageBuffer->isEmpty()) {
            \Swoole\Timer::clear($this->timerId);
            $this->timerId = 0;
            return;
        }
        $this->flushing = true;
        try {
            $messages = [];
            while (!$this->messageBuffer->isEmpty()) {
                $messages[] = $this->messageBuffer->dequeue();
            }
            // Encode as MessageConcat and send to all
            $encoded = BinaryProtocol::encodeConcat($messages);
            $this->broadcastText($encoded);
        } finally {
            $this->flushing = false;
        }
    }
}
```

### 5.6 OpenPost State

```php
final class OpenPost
{
    public function __construct(
        public int $postId,
        public int $threadId,
        public string $board,
        public string $body = '',
        public int $charCount = 0,
        public int $lineCount = 0,
        public int $createdAt = 0,
        public bool $hasSpoiler = false,
        public string $passwordHash = '',  // bcrypt for reclamation
    ) {}
}
```

### 5.7 Handler Examples

#### AppendHandler

```php
final class AppendHandler
{
    public function handle(
        Server $server,
        ClientConnection $conn,
        string $charBytes,
    ): void {
        $openPost = $conn->openPost;
        if ($openPost === null) return;
        if ($openPost->charCount >= 2000) {
            throw new BodyTooLongException();
        }

        $char = mb_convert_encoding($charBytes, 'UTF-8');
        if ($char === "\n") {
            $openPost->lineCount++;
            if ($openPost->lineCount > 100) {
                throw new TooManyLinesException();
            }
        }

        // Build broadcast frame: [postID:f64LE][char:utf8][0x02]
        $broadcast = BinaryProtocol::encodeAppend($openPost->postId, $charBytes);

        $openPost->body .= $char;
        $openPost->charCount++;

        // Broadcast to thread feed (binary, immediate, no buffering)
        $feed = $this->feedManager->getFeed($openPost->threadId);
        $feed->broadcastBinary($broadcast);
        $feed->updateOpenBody($openPost->postId, $openPost->body);

        // Async persist to database (non-blocking)
        Coroutine::create(function () use ($openPost) {
            $this->boardService->setOpenBody($openPost->postId, $openPost->body);
        });

        // Increment spam score
        $this->spamScorer->incrementCharScore($conn->ip);
    }
}
```

### 5.8 BinaryProtocol

```php
final class BinaryProtocol
{
    /**
     * Encode post ID as float64 LE (matches meguca's JS DataView.getFloat64).
     *
     * CAVEAT: IEEE 754 double-precision floats have 53 bits of integer
     * mantissa. Post IDs above 2^53 (9,007,199,254,740,992) will lose
     * precision. PostgreSQL BIGINT goes up to 2^63, so this encoding is
     * safe for the first ~9 quadrillion posts. If ashchan ever approaches
     * this limit, switch to a fixed 8-byte uint64 LE encoding and update
     * the JS decoder to use BigInt. For now, the float64 approach matches
     * meguca's battle-tested wire format and gives us the fastest JS
     * decode path (DataView.getFloat64 is a single V8 intrinsic).
     *
     * This MUST be validated in the BinaryProtocol integration tests
     * with boundary values: 0, 1, 2^53-1, 2^53, 2^53+1.
     */
    public static function encodePostId(int $postId): string
    {
        assert($postId >= 0 && $postId <= (2**53), 'Post ID exceeds float64 safe integer range');
        return pack('e', (float) $postId);  // 'e' = little-endian double
    }

    public static function decodePostId(string $data): int
    {
        $float = unpack('e', substr($data, 0, 8))[1];
        $int = (int) $float;
        assert(abs($float - $int) < 0.5, 'Post ID lost precision in float64 decode');
        return $int;
    }

    public static function encodeAppend(int $postId, string $charUtf8): string
    {
        return self::encodePostId($postId) . $charUtf8 . chr(0x02);
    }

    public static function encodeBackspace(int $postId): string
    {
        return self::encodePostId($postId) . chr(0x03);
    }

    public static function encodeSplice(int $postId, int $start, int $len, string $text): string
    {
        return self::encodePostId($postId)
            . pack('v', $start)   // uint16 LE
            . pack('v', $len)     // uint16 LE
            . $text
            . chr(0x04);
    }

    public static function encodeConcat(array $messages): string
    {
        $typeStr = '33'; // MessageConcat
        return $typeStr . json_encode($messages);
    }
}
```

---

## 6. Client-Side Implementation

### 6.1 Architecture

The client-side liveposting system is implemented as a JavaScript module loaded on thread pages. It manages a WebSocket connection, a post authoring state machine, and DOM update logic.

```
frontend/static/js/
├── livepost/
│   ├── connection.js    # WebSocket lifecycle, reconnection
│   ├── protocol.js      # Binary encode/decode, message routing
│   ├── state-machine.js # Post authoring FSM
│   ├── open-post.js     # FormModel: input diffing, send mutations
│   ├── post-view.js     # DOM rendering for live post updates
│   └── sync.js          # Thread synchronisation on connect
```

### 6.2 Connection Manager

```javascript
class LiveConnection {
    constructor(board, threadId) {
        this.board = board;
        this.threadId = threadId;
        this.ws = null;
        this.reconnectAttempts = 0;
    }

    connect() {
        const proto = location.protocol === 'https:' ? 'wss' : 'ws';
        this.ws = new WebSocket(`${proto}://${location.host}/api/socket`);
        this.ws.binaryType = 'arraybuffer';
        this.ws.onopen = () => this.synchronise();
        this.ws.onmessage = (e) => this.onMessage(e.data);
        this.ws.onclose = () => this.scheduleReconnect();
    }

    synchronise() {
        this.sendText(30, { board: this.board, thread: this.threadId });
    }

    onMessage(data) {
        if (data instanceof ArrayBuffer) {
            const view = new Uint8Array(data);
            const type = view[data.byteLength - 1];
            handlers[type]?.(data.slice(0, data.byteLength - 1));
        } else {
            const type = parseInt(data.slice(0, 2));
            const payload = data.length > 2 ? JSON.parse(data.slice(2)) : null;
            if (type === 33) { // Concat
                payload.forEach(msg => this.onMessage(msg));
            } else {
                handlers[type]?.(payload);
            }
        }
    }
}
```

### 6.3 Input Diffing (FormModel)

The client tracks `inputBody` (last value sent to server) vs the textarea's current value. On each `input` event, it computes the minimal mutation:

| Diff Result | Action | Binary Frame |
|-------------|--------|-------------|
| +1 char at end | `Append` | `[char:utf8, 0x02]` |
| -1 char at end | `Backspace` | `[0x03]` |
| Anything else | `Splice` | `[start:u16, len:u16, text:utf8, 0x04]` |

This matches meguca's `FormModel.parseInput()` algorithm exactly.

### 6.4 Receiving Live Updates

When the client receives a binary append/backspace/splice message for a post that isn't the local user's open form:

1. Decode the post ID from the first 8 bytes.
2. Look up the post's DOM element by `id="p{postId}"`.
3. Update the post model's body string.
4. For simple appends: directly append a text node to the `<blockquote>` (fast path).
5. For splices/backspaces: re-render the body markup (slow path, but infrequent).

### 6.5 Post Authoring UI

The reply form is rendered inline at the bottom of the thread. When the user starts typing:

1. The FSM transitions `ready → draft`.
2. On the first keystroke, an `InsertPost` message is sent to allocate the post on the server.
3. The server responds with `PostID` → FSM transitions `allocating → alloc`.
4. Subsequent keystrokes send binary append/backspace/splice messages.
5. Clicking "Done" sends `ClosePost`.
6. The form reverts to a regular rendered post.

---

## 7. Database Schema Changes

### 7.1 Posts Table Modifications

```sql
-- Add editing state to posts table
ALTER TABLE posts
    ADD COLUMN is_editing BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN edit_password_hash TEXT,
    ADD COLUMN edit_expires_at TIMESTAMPTZ;

-- Index for finding open posts (cleanup, reclamation)
CREATE INDEX idx_posts_is_editing ON posts (is_editing) WHERE is_editing = TRUE;

-- Partial index for expiration cleanup
CREATE INDEX idx_posts_edit_expires ON posts (edit_expires_at)
    WHERE is_editing = TRUE AND edit_expires_at IS NOT NULL;
```

### 7.2 Open Post Body Table

A separate table for the rapidly-changing open post body avoids write amplification on the main posts table:

```sql
CREATE TABLE open_post_bodies (
    post_id   BIGINT PRIMARY KEY REFERENCES posts(id) ON DELETE CASCADE,
    body      TEXT NOT NULL DEFAULT '',
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- When a post is closed, the body is copied to posts.content and the row is deleted.
```

### 7.3 Spam Scoring Table

```sql
CREATE TABLE spam_scores (
    ip_hash     TEXT NOT NULL,
    session_id  TEXT NOT NULL,
    score       INTEGER NOT NULL DEFAULT 0,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (ip_hash, session_id)
);

CREATE INDEX idx_spam_scores_updated ON spam_scores (updated_at);
```

---

## 8. Event Bus Integration

### 8.1 New Events

Liveposting introduces three new domain events published to the Redis Streams event bus:

#### `livepost.opened`
```json
{
    "id": "evt_...",
    "type": "livepost.opened",
    "occurred_at": "2026-02-23T12:00:00Z",
    "payload": {
        "board_id": "g",
        "thread_id": "123",
        "post_id": "456",
        "ip_hash": "sha256:..."
    }
}
```

#### `livepost.closed`
```json
{
    "id": "evt_...",
    "type": "livepost.closed",
    "occurred_at": "2026-02-23T12:05:00Z",
    "payload": {
        "board_id": "g",
        "thread_id": "123",
        "post_id": "456",
        "final_body": "Hello world",
        "duration_seconds": 300
    }
}
```

#### `livepost.expired`
Published when an open post is force-closed by the 15-minute timeout:
```json
{
    "id": "evt_...",
    "type": "livepost.expired",
    "occurred_at": "2026-02-23T12:15:00Z",
    "payload": {
        "board_id": "g",
        "thread_id": "123",
        "post_id": "456",
        "reason": "timeout"
    }
}
```

### 8.2 Cache Invalidation

The existing `CacheInvalidatorProcess` handles `livepost.closed` events by invalidating thread and board page caches (both Redis L2 and Varnish L1), since a closed post changes the rendered HTML.

Open post body updates do **not** trigger cache invalidation — they are only visible to WebSocket-connected clients. This is a critical performance property.

---

## 9. Caching and Performance

### 9.1 Hot Path Data (Swoole Table)

These data structures live in shared memory, accessible by all Swoole workers with zero serialization:

| Table | Key | Columns | Purpose |
|-------|-----|---------|---------|
| `connections` | fd (int) | ip, thread_id, board, worker_id, connected_at | Client registry |
| `ip_counts` | ip_hash (string) | count (int) | Connection limiting |
| `open_posts` | post_id (int) | fd, thread_id, body (2KB), char_count, line_count | Open post state (see §14.1 for sizing) |
| `feed_registry` | thread_id (int) | worker_ids (bitmask), client_count | Cross-worker routing |

### 9.2 Performance Characteristics

| Metric | Target | Mechanism |
|--------|--------|-----------|
| Keystroke → broadcast latency | < 5ms (p99) | In-process binary dispatch, no serialization |
| Concurrent open posts | 10,000+ | ~2KB Swoole Table row per post |
| Connections per worker | 50,000+ | Swoole epoll, 2KB per fd |
| Message throughput | 500k msg/s | Binary protocol, zero-copy broadcast |
| Database writes | Debounced at 1s | Coroutine pool, batch body updates |
| Memory per thread feed | ~50KB | Recent post cache + message buffer |

### 9.3 Database Write Debouncing

Open post body updates are written to PostgreSQL **at most once per second** per post, using a debounce timer:

```php
// In AppendHandler / SpliceHandler:
$feed->updateOpenBody($postId, $body);

// ThreadFeed internally:
if (!isset($this->bodyWriteTimers[$postId])) {
    $this->bodyWriteTimers[$postId] = Timer::after(1000, function () use ($postId) {
        $body = $this->openPostBodies[$postId];
        Coroutine::create(fn () => $this->boardService->setOpenBody($postId, $body));
        unset($this->bodyWriteTimers[$postId]);
    });
}
```

This reduces database writes by ~95% during active typing (from ~10/s per user to ~1/s).

### 9.4 Varnish and Nginx Bypass

WebSocket connections **must** bypass both Anubis (PoW challenge) and Varnish (HTTP cache). The nginx configuration adds:

```nginx
# WebSocket upgrade for liveposting
location /api/socket {
    proxy_pass http://127.0.0.1:9501;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
}
```

---

## 10. Security and Anti-Spam

### 10.1 Connection-Level Controls

| Control | Value | Implementation |
|---------|-------|----------------|
| Max connections per IP | 16 | Swoole Table `ip_counts` + atomic increment |
| Handshake timeout | 5s | Swoole WebSocket setting |
| Idle connection timeout | 5min | Swoole Timer, no message = disconnect |
| Ping interval | 45s | Server→Client ping frame (must be < Cloudflare's 100s idle timeout) |
| Ping timeout | 30s | No pong = disconnect |
| Max frame size | 4KB | Swoole `websocket_max_frame_size` |

### 10.2 Post-Level Controls

| Control | Value | Implementation |
|---------|-------|----------------|
| Max post body length | 2000 chars | Server-side character count |
| Max lines per post | 100 | Server-side newline count |
| Max open post lifetime | 15 min | Timer → auto-close + broadcast |
| Post password (reclaim) | bcrypt(4 rounds) | Stored in `edit_password_hash` |
| Captcha trigger threshold | Configurable score | Spam scoring via moderation service |

### 10.3 Spam Scoring

Following meguca's model, each action has a cost in spam points:

| Action | Score |
|--------|-------|
| Post creation | 50 |
| Character append | 1 |
| Splice (per char) | 2 |
| Image attachment | 30 |

Scores decay over time (1 point/second). When the score exceeds the threshold (default: 500), the server sends a `MessageCaptcha` and blocks further post creation until solved.

### 10.4 Ban Integration

On WebSocket handshake, the gateway checks the client's IP against the ban list via an mTLS call to `moderation-anti-spam`. Banned users receive a close frame with reason code. Additionally, the moderation service can push ban events via Redis Streams, and the gateway's `CacheInvalidatorProcess` can force-disconnect banned IPs.

---

## 11. Infrastructure Changes

### 11.1 nginx Configuration

Add WebSocket upgrade support in `config/nginx/nginx.conf`:

```nginx
upstream gateway_ws {
    server 127.0.0.1:9501;
    keepalive 64;
}

# WebSocket — bypasses Anubis and Varnish
location /api/socket {
    proxy_pass http://gateway_ws;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header CF-Connecting-IP $http_cf_connecting_ip;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
    proxy_buffering off;
}
```

### 11.2 Cloudflare Configuration

- Enable **WebSocket** support in the Cloudflare dashboard (free plan supports WebSockets).
- Cloudflare Tunnel (`cloudflared`) natively supports WebSocket proxying.
- Set `proxy_read_timeout` > Cloudflare's 100-second idle timeout (use 45s ping frames to keep alive — see §10.1). The 45s interval ensures at least two ping/pong round-trips occur within each 100s Cloudflare window, providing a safety margin over the previous 60s interval.

### 11.3 Swoole Server Settings

```php
'settings' => [
    // ... existing settings ...
    'websocket_subprotocol' => 'ashchan-v1',
    'open_websocket_ping_frame' => true,
    'open_websocket_pong_frame' => true,
    'websocket_compression' => true,           // permessage-deflate
    'open_websocket_close_frame' => true,
    'max_connection' => 100000,
    'heartbeat_check_interval' => 45,       // Ping every 45s (< CF 100s idle timeout)
    'heartbeat_idle_time' => 300,
],
```

### 11.4 Redis Configuration

No new Redis databases required. The WebSocket system uses:
- **Redis DB 0** (gateway): spam score state, fallback sync data
- **Redis DB 6** (events): livepost events published to the existing stream

---

## 12. Migration Strategy

### Phase 1: Foundation (Week 1-2) — ✅ COMPLETE (`de21293`)
1. ✅ Add WebSocket server listener to gateway `server.php`.
2. ✅ Implement `WebSocketController` with handshake, ping/pong, basic routing.
3. ✅ Implement `BinaryProtocol` encode/decode.
4. ✅ Implement `ThreadFeedManager` and `ThreadFeed` with client tracking.
5. ✅ Implement `SynchroniseHandler` — client can connect and receive sync state.
6. ✅ Add nginx WebSocket proxy configuration.

**Implementation notes (Phase 1):**
- Swoole Tables cannot be created after worker fork — used worker-local arrays for IP rate limiting and connection tracking instead.
- `onHandShake` does not receive `WsServer` — IP rate limiting moved to controller-local `$connectionsByIp` array.
- Added `FeedGarbageCollector` custom process (normally Phase 4) since it was trivial to include.
- Added `FeedCache`, `MessageBuffer`, `ClientConnection`, `OpenPost` supporting classes.

### Phase 2: Post Lifecycle (Week 3-4) — ✅ COMPLETE
1. ✅ Apply database migrations (`is_editing`, `open_post_bodies`, `spam_scores`) — added to `db/install.sql`.
2. ✅ Implement `InsertPostHandler` — allocates open post via mTLS to BTP service, sends PostID, broadcasts InsertPost.
3. ✅ Implement `AppendHandler`, `BackspaceHandler`, `SpliceHandler` — binary character streaming with broadcast.
4. ✅ Implement `ClosePostHandler` — finalizes post via BTP, broadcasts close; includes `forceClose()` for disconnect cleanup.
5. ✅ Implement `ReclaimHandler` — reclaims post via BTP password verification, restores OpenPost state.
6. ✅ Implement database write debouncing — `Timer::after(1000ms)` per open post in `ThreadFeed`, persists via mTLS PUT.

**Implementation notes (Phase 2):**
- Created separate `BoardService::createOpenPost()` rather than modifying existing `createPost()` — open posts have `is_editing=true`, empty content, body stored in `open_post_bodies` table.
- `ProxyClient` threaded through `WebSocketController` → `ThreadFeedManager` → `ThreadFeed` to enable mTLS calls for write debouncing.
- `ClosePostHandler` stored as property on `WebSocketController` for force-close on disconnect in `onClose()`.
- BTP service exposes 5 new endpoints via `LivepostController`: open-post, close, body update, reclaim, close-expired.
- Edit password uses bcrypt cost 4 (fast, short-lived credential for reclaim within 15-minute window).
- Event bus integration included: `livepost.opened`, `livepost.closed`, `livepost.expired` events via Redis Streams.

### Phase 3: Client (Week 5-6) — ✅ COMPLETE
1. ✅ Build `livepost/` JavaScript module — 7 files in `frontend/static/js/livepost/`.
2. ✅ Implement connection manager with reconnection logic — exponential backoff, keep-alive NOOP.
3. ✅ Implement post authoring FSM and input diffing — meguca-compatible diff algorithm.
4. ✅ Implement live DOM updates for incoming mutations — append/backspace/splice with DOM fast paths.
5. ✅ Implement reply form UI with live preview — inline form, char counter, Done button, Ctrl+Enter.
6. ✅ Integrate with existing thread page templates — scripts via `extra_scripts` block, CSS via `livepost.css`.

**Implementation notes (Phase 3):**
- No build tools/bundler required — plain JS files loaded with `<script defer>` in dependency order.
- Modules communicate via a shared `window.Livepost` namespace (`LP.Connection`, `LP.Protocol`, etc.).
- Input diffing follows meguca's `FormModel.parseInput()` algorithm exactly: single-char append/backspace
  are optimized to binary frames; anything else falls back to splice with start/len/text encoding.
- DOM fast path for append: directly appends text node to `<blockquote>` (no re-render). Splice/backspace
  that affect mid-body fall back to full re-render with basic greentext highlighting.
- Open posts from other users show a pulsing `●` indicator and a blinking block cursor `█` via CSS.
- Dark theme (Tomorrow) detection via `MutationObserver` on the `#themeStylesheet` link's `href` attribute.
- Reclaim passwords stored in `sessionStorage` — survive page reloads within the same tab, clear on close.
- Ctrl+Enter / Cmd+Enter keyboard shortcut to close (submit) the live post.

### Phase 4: Anti-Spam & Polish (Week 7-8)
1. Implement spam scoring and captcha integration.
2. Implement cross-worker broadcasting via `$server->sendMessage()`.
3. ~~Implement `FeedGarbageCollector` process for idle feed eviction.~~ ✅ Done in Phase 1.
4. Implement open post timeout (15 min auto-close via scheduled task calling `closeExpiredPosts()`).
5. ~~Add event bus integration (`livepost.opened`, `livepost.closed`).~~ ✅ Done in Phase 2.
6. Load testing and performance tuning.

### Phase 5: Rollout
1. Feature flag: `LIVEPOSTING_ENABLED=true` in gateway `.env`.
2. Gradual rollout: enable per-board via board configuration.
3. Monitor WebSocket connection count, message throughput, latency.
4. Full rollout after stability confirmation.

---

## 13. Testing Plan

### 13.1 Unit Tests

| Component | Test Cases |
|-----------|-----------|
| `BinaryProtocol` | Encode/decode append, backspace, splice; edge cases (emoji, multibyte) |
| `OpenPost` | Body manipulation, char/line counting, overflow protection |
| `MessageBuffer` | Flush timing, concat encoding, empty buffer handling |
| `ThreadFeed` | Add/remove clients, broadcast routing, sync message generation |
| `SpamScorer` | Score accumulation, decay, threshold detection |

### 13.2 Integration Tests

| Scenario | Verification |
|----------|-------------|
| Client connects → sync | Receives board config + recent posts |
| Client inserts post | Post allocated, PostID returned, broadcast to others |
| Client types characters | Body updates broadcast to all thread viewers |
| Client reconnects → reclaim | Open post reclaimed with correct password |
| Post timeout | Auto-closed after 15 min, broadcast ClosePost |
| IP banned during session | Connection force-closed |
| Cross-worker broadcast | Client on worker 1 sees updates from worker 0 |

### 13.3 Load Tests

| Test | Target |
|------|--------|
| 10k concurrent connections, 1 thread | < 100MB RSS, < 50ms p99 latency |
| 1k active typists, 100 threads | < 5ms p99 broadcast latency |
| Sustained 100k msg/s | No dropped messages, < 10% CPU on 4-core |
| Reconnection storm (1k simultaneous) | All reclaims succeed, no data loss |

### 13.4 Compatibility Tests

- Chrome, Firefox, Safari, Edge (desktop)
- iOS Safari, Android Chrome (mobile)
- Behind corporate proxy (Websocket over TLS)
- With Cloudflare (connection resumption after CF timeout)

---

## 14. Known Risks and Mitigations

### 14.1 Swoole Table Fixed Sizing

**Risk:** Swoole Tables require pre-allocated row counts at server start. If the `open_posts` table fills during a traffic spike, new post allocations will fail silently (`Swoole\Table::set()` returns `false` on a full table).

**Mitigations:**

1. **Over-provision by 4×:** Size tables to 4× expected peak. For `open_posts`, if we expect a peak of 5,000 simultaneous open posts, allocate 20,000 rows (~40MB at 2KB/row — trivial).

2. **Monitoring and alerting:** Expose table utilization via the `/health` endpoint and Prometheus metrics:
    ```php
    // In HealthController or a dedicated MetricsController:
    $table = $this->openPostsTable;
    $metrics['swoole_table_open_posts_count'] = $table->count();
    $metrics['swoole_table_open_posts_capacity'] = $table->getSize();
    $metrics['swoole_table_open_posts_utilization'] = $table->count() / $table->getSize();
    ```
    Alert at 70% utilization. Critical alert at 90%.

3. **Graceful rejection:** Always check the return value of `$table->set()`. On failure, return a WebSocket error frame to the client ("server at capacity, try again") rather than silently dropping the post:
    ```php
    if (!$this->openPostsTable->set((string) $postId, $row)) {
        $this->sendError($fd, 'Server at capacity. Please try again shortly.');
        return;
    }
    ```

4. **Dynamic resizing path:** Swoole Tables cannot be resized at runtime. If utilization consistently exceeds 60%, increase the allocation in `server.php` and perform a rolling restart. The table size should be a configurable env var:
    ```php
    $tableSize = (int) (getenv('SWOOLE_TABLE_OPEN_POSTS_SIZE') ?: 20000);
    ```

### 14.2 Memory Leaks in Long-Running Workers

**Risk:** PHP was not designed for week-long process lifetimes. Subtle memory leaks in `ThreadFeed` objects, `SplQueue` message buffers, closures capturing references, and the `$recentPosts` cache can compound over hours.

**Mitigations:**

1. **Worker recycling:** Configure Swoole to restart workers after processing a set number of requests or after a time limit:
    ```php
    'settings' => [
        'max_request' => 500000,           // Restart worker after 500k requests
        'max_request_grace' => 100000,     // Grace period to finish in-flight work
    ],
    ```
    Additionally, implement a timer-based recycler that triggers worker shutdown after 8 hours regardless of request count:
    ```php
    // In WorkerStart callback:
    \Swoole\Timer::after(8 * 3600 * 1000, function () use ($server, $workerId) {
        // Graceful: stop accepting new connections, drain existing ones
        $server->stop($workerId);
    });
    ```

2. **Aggressive ThreadFeed cleanup:** When a feed has zero clients, destroy it immediately (don't keep it warm). The `FeedGarbageCollector` process runs every 60 seconds and evicts feeds idle for > 5 minutes:
    ```php
    // FeedGarbageCollector:
    foreach ($this->feeds as $threadId => $feed) {
        if ($feed->clientCount() === 0 && $feed->idleSince() > 300) {
            $feed->destroy();  // Clears timers, buffers, cache
            unset($this->feeds[$threadId]);
        }
    }
    ```

3. **Memory monitoring:** Log `memory_get_usage(true)` per worker every 60 seconds. Alert if any worker exceeds 256MB. Include in `/health` response:
    ```php
    $metrics['worker_memory_bytes'] = memory_get_usage(true);
    $metrics['worker_memory_peak_bytes'] = memory_get_peak_usage(true);
    $metrics['worker_uptime_seconds'] = time() - $this->workerStartTime;
    ```

4. **SplQueue drain:** Ensure the `MessageBuffer` is fully drained in `ThreadFeed::destroy()`. Avoid keeping references to dequeued items:
    ```php
    public function destroy(): void
    {
        if ($this->timerId) {
            \Swoole\Timer::clear($this->timerId);
        }
        // Explicit drain — prevents SplQueue from holding references
        while (!$this->messageBuffer->isEmpty()) {
            $this->messageBuffer->dequeue();
        }
        $this->clients = [];
        $this->openPostBodies = [];
        $this->recentPosts = [];
    }
    ```

### 14.3 Timer Callback Stacking

**Risk:** `\Swoole\Timer::tick(100, ...)` fires every 100ms. If `flush()` takes longer than 100ms (e.g., due to a large client set or slow `$server->push()`), the next tick fires while the previous one is still executing, causing unbounded memory growth in the message buffer.

**Mitigation:** A `$flushing` guard flag is implemented in `ThreadFeed::flush()` (see §5.5). If a flush is in progress when the next tick fires, the tick is skipped. This is safe because the in-progress flush will drain the entire buffer. Additionally, if `flush()` consistently takes >50ms (logged via a stopwatch), it indicates the client set is too large for a single feed and should be investigated.

### 14.4 Float64 Post ID Precision

**Risk:** `pack('e', (float) $postId)` encodes the post ID as an IEEE 754 double-precision float. This format has a 53-bit mantissa, meaning integer values above 2^53 (9,007,199,254,740,992) cannot be represented exactly. PHP's `(float)` cast and JavaScript's `DataView.getFloat64()` will both silently round, causing post ID collisions.

**Mitigations:**

1. **Assertion in BinaryProtocol:** `encodePostId()` and `decodePostId()` include assertions that verify the post ID is within the safe integer range (see §5.8). These assertions are active in development and staging.

2. **Practical risk is negligible:** PostgreSQL `BIGSERIAL` starts at 1 and increments. Reaching 2^53 requires 9 quadrillion posts. At 1,000 posts/second sustained, this would take ~285,000 years.

3. **Future-proofing:** If ashchan ever migrates to UUIDs for post IDs (currently used for user-facing PKs but not internal post IDs), the binary protocol must switch to a text-based post ID encoding or a fixed 16-byte UUID frame. This would be a protocol version bump (`ashchan-v2`).

4. **Required test cases:** The `BinaryProtocol` unit test suite must include round-trip tests for these values:
    ```php
    $testIds = [0, 1, 42, 2**32, 2**53 - 1]; // All must round-trip exactly
    $unsafeIds = [2**53, 2**53 + 1];           // Must throw assertion error
    ```

### 14.5 Cloudflare 100-Second Idle Timeout

**Risk:** Cloudflare terminates WebSocket connections that are idle for 100 seconds (no frames in either direction). The original design used 60s server pings + 30s pong timeout, which cuts it dangerously close: if a ping is sent at t=0, the pong arrives at t=0.1, then the next ping fires at t=60. The connection is "idle" from Cloudflare's perspective from t=0.1 to t=60 — a 59.9s gap. A single delayed ping (e.g., due to Swoole event loop contention) could push this past 100s.

**Mitigation:** Ping interval reduced to **45 seconds** (see §10.1, §11.3). This guarantees:
- Worst case idle gap: 45s (ping sent) + 30s (pong timeout) = 75s
- Even with a 20s delay, the gap reaches 65s — well under 100s
- Two ping/pong exchanges fit comfortably within each 100s Cloudflare window

### 14.6 Cross-Worker State Consistency

**Risk:** Swoole runs multiple workers. WebSocket fd's are worker-local, but the `Swoole\Table` shared state is global. Race conditions can occur when two workers simultaneously modify the same `open_posts` row (e.g., a client disconnects on worker 0 while a reclaim arrives on worker 1).

**Mitigation:** Open post ownership is tied to the fd, which is inherently single-worker. The `open_posts` Swoole Table row includes a `worker_id` column. Any operation on an open post first checks `$row['worker_id'] === $this->workerId`. Cross-worker reclaim requests are forwarded via `$server->sendMessage()` to the owning worker, which processes them sequentially in its event loop — no locks needed.

---

## Appendix A: Meguca Source File Reference

| File | Purpose |
|------|---------|
| `meguca/websockets/websockets.go` | WebSocket client struct, connection lifecycle, message routing |
| `meguca/websockets/handlers.go` | Message type → handler dispatch (text + binary) |
| `meguca/websockets/open_post.go` | Open post state struct and initialization |
| `meguca/websockets/post_creation.go` | Thread/reply creation via WebSocket |
| `meguca/websockets/post_updates.go` | Append, backspace, splice, close, image handlers |
| `meguca/websockets/synchronisation.go` | Client sync and thread subscription |
| `meguca/websockets/feeds/feed.go` | Thread feed goroutine, message dispatch loop |
| `meguca/websockets/feeds/feeds.go` | Feed registry, add/remove feed lifecycle |
| `meguca/websockets/feeds/clients.go` | Global client map, IP tracking, sync state |
| `meguca/websockets/feeds/cache.go` | In-memory thread state cache, eviction |
| `meguca/websockets/feeds/util.go` | Ticker, message buffer, baseFeed broadcast |
| `meguca/common/websockets.go` | Message type constants, encoding helpers |
| `meguca/common/posts.go` | Post data structures |
| `meguca/common/vars.go` | Constants (MaxLenBody=2000, MaxLinesBody=100) |
| `meguca/client/connection/state.ts` | Client WebSocket FSM, binary/text routing |
| `meguca/client/connection/messages.ts` | Message type enum, handler registry |
| `meguca/client/posts/posting/model.ts` | FormModel: input diffing, binary encode |
| `meguca/client/posts/posting/view.ts` | Textarea UI, resize, draft lifecycle |
| `meguca/client/posts/posting/index.ts` | Post authoring state machine |
| `meguca/client/posts/model.ts` | Post model: append, backspace, splice, DOM updates |
| `meguca/client/client.ts` | Binary message handlers (append, splice, close) |

## Appendix B: Glossary

| Term | Definition |
|------|-----------|
| **Open post** | A post in editing state; its body is streamed live to viewers |
| **Feed** | A per-thread message broker that dispatches updates to subscribed clients |
| **Splice** | A text operation that replaces a substring at a given position |
| **Reclaim** | Re-acquiring ownership of an open post after disconnection |
| **Sync message** | A snapshot of recent post states sent to newly-connected clients |
| **MessageConcat** | A batch of multiple text messages in a single WebSocket frame |
| **Spam score** | An accumulating cost per IP/session that triggers captcha when exceeded |
| **Feed ticker** | A 100ms timer that flushes buffered messages to all clients |
