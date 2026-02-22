# RabbitMQ Evaluation for Ashchan

## Executive Summary

Ashchan's architecture documents describe an event-driven system using Redis streams, but **zero event publishing or consuming code exists today**. All inter-service communication is synchronous HTTP via `ProxyClient`. The event schemas (CloudEvents-compatible) are defined in `contracts/events/` but nothing emits or reads them. This evaluation assesses whether RabbitMQ is the right tool to fill this gap, compared to implementing the originally-planned Redis streams approach.

**Recommendation: Do NOT adopt RabbitMQ.** Implement the originally-planned Redis Streams architecture instead. The rationale follows.

---

## 1. Current State Assessment

| Capability | Status | Implementation |
|---|---|---|
| Event schemas | ✅ Defined | 4 JSON Schemas in `contracts/events/` |
| Event publishing | 🚧 Not implemented | No `XADD` calls anywhere |
| Event consuming | 🚧 Not implemented | Env vars exist in search-indexing, no code |
| Async queue | 🚧 Not implemented | No `async_queue.php`, no process workers |
| Sync HTTP forwarding | ✅ Implemented | `ProxyClient` (cURL + mTLS) |
| Redis caching | ✅ Implemented | Thread/catalog cache, rate limiting |
| WebSocket fan-out | 🚧 Not implemented | Architecture docs only |

### What Needs Filling

1. **Domain event publishing** — boards-threads-posts emitting `post.created`, `thread.created`
2. **Domain event consuming** — search-indexing, moderation, gateway cache invalidation
3. **Async media processing** — media-uploads has queue env vars but processes synchronously
4. **WebSocket fan-out** — live thread updates via pub/sub

---

## 2. RabbitMQ vs Redis Streams — Feature Comparison

| Criterion | RabbitMQ (AMQP 0-9-1) | Redis Streams (XADD/XREADGROUP) |
|---|---|---|
| **Message durability** | Disk-backed queues, publisher confirms, HA mirroring | AOF/RDB persistence, `XADD` with `MAXLEN` |
| **Consumer groups** | Built-in (exclusive consumers, competing consumers) | Native `XREADGROUP` with `XCLAIM` for redelivery |
| **Dead-letter queues** | First-class (`x-dead-letter-exchange`) | Manual: move to separate stream on NACK |
| **Routing flexibility** | Exchanges (direct, topic, fanout, headers) | Single stream; fan-out requires multiple consumer groups |
| **Back-pressure** | `prefetch_count`, flow control, credit-based | Consumer controls read batch size |
| **Ordering** | Per-queue FIFO (single consumer) | Per-stream strict ordering with consumer groups |
| **Throughput** | ~30K–50K msg/s per queue (durable, confirmed) | ~100K–500K msg/s per stream (in-memory) |
| **Latency** | ~1–5ms (persisted), sub-ms (transient) | Sub-millisecond |
| **Protocol** | AMQP 0-9-1, MQTT, STOMP | Redis protocol (RESP) |
| **Management UI** | Built-in (port 15672) | Redis Insight / CLI |
| **Clustering** | Quorum queues, federation, shovel | Redis Cluster / Sentinel |
| **PHP ecosystem** | `php-amqplib/php-amqplib`, Hyperf `hyperf/amqp` | Already using `hyperf/redis`; `XADD`/`XREAD` are raw calls |
| **Operational burden** | Separate Erlang/OTP process, Mnesia DB, port 5672/15672 | Already running — zero new infrastructure |

---

## 3. Evaluation Against Ashchan's Goals

### 3.1 Speed / Efficiency

| Factor | RabbitMQ | Redis Streams | Winner |
|---|---|---|---|
| Raw throughput | 30–50K msg/s | 100–500K msg/s | **Redis** |
| Publish latency | 1–5ms (durable) | <1ms | **Redis** |
| Memory overhead | ~100–200 MB baseline (Erlang VM) | 0 MB marginal (already running) | **Redis** |
| Connection overhead | Separate TCP + AMQP handshake per service | Reuses existing connection pool | **Redis** |
| Serialization | AMQP framing overhead | Raw JSON over RESP | **Redis** |

For an imageboard with bursty traffic but moderate absolute volume, Redis Streams are **5–10× faster** at the message layer and add **zero new memory/CPU footprint**.

### 3.2 Security

| Factor | RabbitMQ | Redis Streams | Winner |
|---|---|---|---|
| Transport encryption | TLS (separate cert management) | TLS (single Redis TLS config) | **Redis** (simpler) |
| Authentication | Username/password, x509, LDAP | `requirepass`, ACLs (Redis 6+) | **Draw** |
| Authorization | Per-vhost/queue permissions | Per-key ACLs (`ACL SETUSER`) | **Draw** |
| mTLS integration | Requires separate CA/cert chain for AMQP | Same Redis connection, optionally TLS | **Redis** (fewer certs) |
| Attack surface | Port 5672 + 15672 (management UI) | No new ports | **Redis** |
| Audit trail | Management API logs | Redis `MONITOR` / slowlog | **Draw** |

RabbitMQ would add a **new attack surface** (2 ports, Erlang cookie, management UI) that needs hardening. Redis is already in the threat model.

### 3.3 Operational Complexity

| Factor | RabbitMQ | Redis Streams | Impact |
|---|---|---|---|
| New infrastructure | Yes — Erlang/OTP runtime, `rabbitmq-server` | No — already running | **Critical** |
| Monitoring | Prometheus plugin, new dashboards | Existing Redis monitoring | **Moderate** |
| Upgrades | Separate upgrade cycle (Erlang + RabbitMQ) | Part of Redis upgrade | **Moderate** |
| Backup/DR | Separate backup strategy | Existing Redis RDB/AOF | **Low** |
| Dev environment | New dependency in `make bootstrap` | Nothing changes | **Critical** |
| Static PHP build | `php-amqplib` is pure PHP (OK) but adds ~10MB | No new deps | **Low** |

Ashchan explicitly lists as a **non-goal**: *"Container orchestration complexity (uses native PHP-CLI instead)"*. Adding RabbitMQ contradicts this philosophy — it's a complex Erlang application that demands its own operational runbook.

### 3.4 Ecosystem Fit

| Factor | Assessment |
|---|---|
| Hyperf integration | `hyperf/amqp` exists but adds DI complexity; `hyperf/redis` is already wired |
| Event schema compatibility | Both can carry CloudEvents JSON payloads identically |
| Consumer group naming | Search-indexing `.env` already defines `EVENTS_CONSUMER_GROUP=search-indexing` for Redis |
| Existing Redis usage | 6 DB slots allocated, connection pools configured, all services depend on `hyperf/redis` |
| Team familiarity | Codebase shows Redis expertise; no RabbitMQ artifacts exist |

---

## 4. When RabbitMQ WOULD Be Justified

RabbitMQ would be the better choice if Ashchan needed:

1. **Complex routing topologies** — e.g., routing moderation events differently per board, per severity, per region. Redis Streams has no exchange/binding concept.
2. **Cross-datacenter federation** — RabbitMQ's shovel/federation plugins excel here. Redis Cluster is simpler but less flexible.
3. **Protocol diversity** — If services were polyglot (Node.js, Go, Python) and needed MQTT/STOMP alongside AMQP. Ashchan is PHP-only.
4. **Strict delivery guarantees with complex retry** — RabbitMQ's dead-letter exchanges with TTL-based retry are more elegant than Redis's manual `XCLAIM`+`XDEL` pattern.
5. **Very high queue depth** — Millions of unconsumed messages. Redis Streams hold everything in memory; RabbitMQ pages to disk (lazy queues).

**None of these apply to Ashchan today.** The event volume is modest (bounded by post creation rate), routing is simple (broadcast to N consumer groups), and all services are PHP/Hyperf.

---

## 5. Recommendation: Implement Redis Streams

### Architecture

```
┌─────────────────────┐
│ boards-threads-posts │
│                     │
│  PostService        │──── XADD ashchan:events ────┐
│  ThreadService      │                             │
└─────────────────────┘                             │
                                                    │
┌─────────────────────┐                             │
│   media-uploads     │                             │
│                     │──── XADD ashchan:events ────┤
│  MediaService       │                             │
└─────────────────────┘                             │
                                                    ▼
                                          ┌─────────────────┐
                                          │  Redis Stream    │
                                          │ ashchan:events   │
                                          │                 │
                                          │ Consumer Groups: │
                                          │  • search-indexing│
                                          │  • moderation    │
                                          │  • cache-invalidation│
                                          └────────┬────────┘
                           ┌───────────────────────┼───────────────────────┐
                           │                       │                       │
                  ┌────────▼────────┐    ┌────────▼────────┐    ┌────────▼────────┐
                  │ search-indexing │    │   moderation    │    │  api-gateway    │
                  │                │    │                 │    │                 │
                  │ XREADGROUP     │    │ XREADGROUP      │    │ XREADGROUP      │
                  │ Index post     │    │ Score post      │    │ Invalidate cache│
                  └────────────────┘    └─────────────────┘    └─────────────────┘
```

### Why a Single Stream with Consumer Groups

- **Simplicity**: One stream (`ashchan:events`) with typed CloudEvents payloads. Each consumer group reads independently.
- **Fan-out**: Each consumer group gets its own cursor. A `post.created` event is read by search, moderation, AND cache invalidation independently.
- **Filtering**: Consumers ignore event types they don't care about (cheap — just skip the JSON decode).
- **Ordering**: Strict per-stream ordering guarantees events are processed in write order.

---

## 6. Implementation Plan (Redis Streams)

### Phase 1: Core Infrastructure (Week 1) ✅ IMPLEMENTED

#### 1.1 Create shared event library — `contracts/php/EventBus/` ✅

```
contracts/php/
└── EventBus/
    ├── EventPublisher.php      # XADD wrapper
    ├── EventConsumer.php       # XREADGROUP loop (Hyperf Process)
    ├── CloudEvent.php          # Envelope DTO
    └── EventTypes.php          # Constants for event type strings
```

**`CloudEvent.php`** — Value object matching the existing JSON schema:
```php
final readonly class CloudEvent
{
    public function __construct(
        public string $id,           // UUIDv4
        public string $type,         // e.g. "post.created"
        public \DateTimeImmutable $occurredAt,
        public array $payload,
    ) {}

    public function toJson(): string { /* ... */ }
    public static function fromJson(string $json): self { /* ... */ }
}
```

**`EventPublisher.php`** — Thin XADD wrapper:
```php
final class EventPublisher
{
    private const STREAM = 'ashchan:events';
    private const MAXLEN = 100_000;  // Capped stream

    public function __construct(private RedisProxy $redis) {}

    public function publish(CloudEvent $event): string
    {
        return $this->redis->xAdd(
            self::STREAM,
            '*',                     // Auto-generate ID
            ['event' => $event->toJson()],
            self::MAXLEN,
        );
    }
}
```

**`EventConsumer.php`** — Hyperf Process-based long-running consumer:
```php
abstract class EventConsumer extends AbstractProcess
{
    protected string $stream = 'ashchan:events';
    protected string $group;          // Set by subclass
    protected string $consumer;       // Hostname-based
    protected int $batchSize = 100;
    protected int $pollIntervalMs = 1000;
    protected int $maxRetries = 3;

    public function handle(): void
    {
        $this->createGroupIfNotExists();
        while (ProcessManager::isRunning()) {
            $messages = $this->redis->xReadGroup(
                $this->group, $this->consumer,
                [$this->stream => '>'],
                $this->batchSize,
                $this->pollIntervalMs,
            );
            foreach ($messages as $id => $data) {
                try {
                    $event = CloudEvent::fromJson($data['event']);
                    $this->process($event);
                    $this->redis->xAck($this->stream, $this->group, $id);
                } catch (\Throwable $e) {
                    $this->handleFailure($id, $data, $e);
                }
            }
            // Reclaim pending messages older than 60s
            $this->reclaimStaleMessages();
        }
    }

    abstract protected function process(CloudEvent $event): void;

    // Subclasses override to filter:
    protected function supports(string $eventType): bool { return true; }
}
```

#### 1.2 Dead-letter handling ✅

Failed messages after `maxRetries` attempts get moved to a dead-letter stream:

```
ashchan:events:dlq    ← XADD on final failure
```

A CLI command (`php bin/hyperf.php events:dlq:retry`) replays dead-lettered events.

#### 1.3 Environment configuration ✅

Add to each service's `.env.example`:
```env
# Event Bus
EVENTS_STREAM_NAME=ashchan:events
EVENTS_DLQ_STREAM=ashchan:events:dlq
EVENTS_MAXLEN=100000
EVENTS_POLL_INTERVAL=1000
EVENTS_BATCH_SIZE=100
EVENTS_MAX_RETRIES=3
```

No new Redis DB needed — events live in DB 0 (gateway) or a new DB 6 dedicated to the event bus (preferred for isolation).

---

### Phase 2: Publisher Integration (Week 2) ✅ IMPLEMENTED

#### 2.1 boards-threads-posts — Emit `post.created` and `thread.created` ✅

In `BoardService::createPost()` and `BoardService::createThread()`, add event emission **after** the database write succeeds:

```php
// In BoardService::createPost(), after $post->save():
$this->eventPublisher->publish(new CloudEvent(
    id: Uuid::uuid4()->toString(),
    type: EventTypes::POST_CREATED,
    occurredAt: new \DateTimeImmutable(),
    payload: [
        'board_id' => $boardSlug,
        'thread_id' => $threadId,
        'post_id' => $post->id,
        'author_id' => $authorId,
        'created_at' => $post->created_at->toIso8601String(),
        'content' => $post->content,
        'media_refs' => $mediaRefs,
    ],
));
```

**Failure mode**: If `XADD` fails, log the error but do NOT roll back the post creation. The post is the source of truth; missed events can be replayed via a backfill command.

#### 2.2 media-uploads — Emit `media.ingested` ✅

After successful upload processing in `MediaService::processUpload()`:

```php
$this->eventPublisher->publish(new CloudEvent(
    id: Uuid::uuid4()->toString(),
    type: EventTypes::MEDIA_INGESTED,
    occurredAt: new \DateTimeImmutable(),
    payload: [
        'media_id' => $media->id,
        'hash' => $media->hash,
        'mime_type' => $media->mime_type,
        'size' => $media->size,
        'board_id' => $boardSlug,
        'post_id' => $postId,
    ],
));
```

#### 2.3 moderation-anti-spam — Emit `moderation.decision` ✅

After moderation decisions are recorded:

```php
$this->eventPublisher->publish(new CloudEvent(
    id: Uuid::uuid4()->toString(),
    type: EventTypes::MODERATION_DECISION,
    occurredAt: new \DateTimeImmutable(),
    payload: [
        'target_type' => 'post',
        'target_id' => $postId,
        'decision' => $decision,  // 'approve' | 'reject' | 'quarantine'
        'reason' => $reason,
        'moderator_id' => $moderatorId,
    ],
));
```

---

### Phase 3: Consumer Integration (Week 3) ✅ IMPLEMENTED

#### 3.1 search-indexing consumer ✅

```php
// services/search-indexing/app/Process/EventConsumerProcess.php

#[Process(name: 'event-consumer')]
final class EventConsumerProcess extends EventConsumer
{
    protected string $group = 'search-indexing';

    public function __construct(
        private SearchService $searchService,
        ContainerInterface $container,
    ) {
        parent::__construct($container);
    }

    protected function supports(string $eventType): bool
    {
        return in_array($eventType, [
            EventTypes::POST_CREATED,
            EventTypes::THREAD_CREATED,
            EventTypes::MODERATION_DECISION,
        ]);
    }

    protected function process(CloudEvent $event): void
    {
        if (!$this->supports($event->type)) return;

        match ($event->type) {
            EventTypes::POST_CREATED => $this->searchService->indexPost(
                $event->payload['board_id'],
                $event->payload['thread_id'],
                $event->payload['post_id'],
                $event->payload['content'],
            ),
            EventTypes::MODERATION_DECISION => $this->handleModeration($event),
            default => null,
        };
    }
}
```

#### 3.2 moderation-anti-spam consumer ✅

Consumes `post.created` events for automated risk scoring:

```php
#[Process(name: 'event-consumer')]
final class PostScoringProcess extends EventConsumer
{
    protected string $group = 'moderation';

    protected function supports(string $eventType): bool
    {
        return $eventType === EventTypes::POST_CREATED;
    }

    protected function process(CloudEvent $event): void
    {
        if (!$this->supports($event->type)) return;
        $this->riskScorer->scorePost($event->payload);
    }
}
```

#### 3.3 api-gateway cache invalidation consumer ✅

```php
#[Process(name: 'cache-invalidator')]
final class CacheInvalidatorProcess extends EventConsumer
{
    protected string $group = 'cache-invalidation';

    protected function process(CloudEvent $event): void
    {
        match ($event->type) {
            EventTypes::POST_CREATED,
            EventTypes::THREAD_CREATED => $this->invalidateThreadCache(
                $event->payload['board_id'],
                $event->payload['thread_id'] ?? null,
            ),
            EventTypes::MODERATION_DECISION => $this->invalidateOnModeration($event),
            default => null,
        };
    }
}
```

---

### Phase 4: Operational Tooling (Week 4) ✅ IMPLEMENTED

#### 4.1 CLI commands ✅

| Command | Purpose |
|---|---|
| `php bin/hyperf.php events:stats` | Show stream length, consumer group lag, pending counts |
| `php bin/hyperf.php events:dlq:list` | List dead-lettered events |
| `php bin/hyperf.php events:dlq:retry [--id=]` | Replay one or all DLQ events |
| `php bin/hyperf.php events:backfill --since=` | Re-emit events from DB for a time range |
| `php bin/hyperf.php events:trim [--maxlen=]` | Manually trim stream |

#### 4.2 Health checks ⬚

Add to each service's `/health` endpoint:

```json
{
  "status": "healthy",
  "event_bus": {
    "stream_length": 42381,
    "consumer_group": "search-indexing",
    "pending_count": 0,
    "last_delivered_id": "1708646400000-0",
    "lag_seconds": 0.3
  }
}
```

#### 4.3 Monitoring ⬚

- **Alert on consumer lag** > 60s (consumer is stuck or crashed)
- **Alert on DLQ growth** > 10 messages in an hour
- **Alert on stream length** > `EVENTS_MAXLEN` (trimming not keeping up)
- **Log** every event publish and consume with correlation ID

#### 4.4 Makefile additions ✅

```makefile
events-stats:    ## Show event stream statistics
	@php services/api-gateway/bin/hyperf.php events:stats

events-dlq:      ## Show dead-lettered events
	@php services/api-gateway/bin/hyperf.php events:dlq:list
```

---

### Phase 5: WebSocket Fan-out (Week 5, Optional) ⬚ NOT YET

Use Redis Pub/Sub (separate from Streams) for real-time WebSocket updates:

```
boards-threads-posts
  │ XADD ashchan:events       ← durable event (for consumers)
  │ PUBLISH thread:{id}       ← ephemeral notification (for WebSockets)
  │
  └─► api-gateway
       │ XREADGROUP (cache invalidation)
       │ SUBSCRIBE thread:{id} (WebSocket fan-out to connected clients)
```

This dual-write approach (Stream + Pub/Sub) gives both durability and real-time push. The Pub/Sub channel is fire-and-forget — missed messages are acceptable because the client will fetch the latest state on reconnect.

---

## 7. Security Considerations

### 7.1 Redis ACLs (Redis 7+)

Create per-service ACL users to restrict stream access:

```redis
ACL SETUSER boards-publisher on >password ~ashchan:events* +xadd +xlen
ACL SETUSER search-consumer  on >password ~ashchan:events* +xreadgroup +xack +xclaim +xinfo
ACL SETUSER gateway-consumer on >password ~ashchan:events* +xreadgroup +xack +xclaim +xinfo ~cache:* +get +set +del
```

Each service authenticates with its own Redis user, granting **only** the commands it needs. Publishers cannot consume; consumers cannot publish.

### 7.2 Event Payload Sanitization

- **Never include raw IP addresses** in events. Hash first.
- **Truncate content** in events if it exceeds a reasonable size (e.g., 10KB). The consumer can fetch the full post from the source service if needed.
- **No PII in event payloads** — use opaque IDs (`author_id`) not usernames or emails.

### 7.3 Stream Encryption at Rest

- If Redis is configured with `aof-use-rdb-preamble yes`, the AOF/RDB files contain event payloads. Ensure the Redis data directory has restrictive permissions (`0700`).
- For production multi-host deployments, enable Redis TLS (`tls-port 6380`, `tls-cert-file`, `tls-key-file`, `tls-ca-cert-file`).

### 7.4 Stream Size Limits

- `MAXLEN ~100000` on every `XADD` prevents unbounded memory growth.
- The `~` prefix allows Redis to trim efficiently (may keep slightly more than 100K entries).
- For boards with extreme traffic, consider per-board streams: `ashchan:events:{board_slug}`.

---

## 8. Performance Benchmarks (Expected)

| Metric | Value | Notes |
|---|---|---|
| Publish latency | <0.5ms | XADD to local Redis, single key |
| Consumer throughput | ~50K events/s | Per consumer group, batch size 100 |
| Memory per 100K events | ~50–100 MB | Depends on payload size (avg ~500 bytes) |
| Consumer group overhead | ~1 KB per group | PEL + metadata |
| Redelivery latency | <100ms | XCLAIM after idle timeout |

For Ashchan's expected volume (~100–10K posts/day), Redis Streams is approximately **1000× overprovisioned**. There is no performance concern.

---

## 9. Migration Path if RabbitMQ Becomes Necessary Later

If future requirements (multi-datacenter, complex routing, polyglot services) make RabbitMQ necessary:

1. **Abstract the EventPublisher/EventConsumer** interfaces now (this plan does this).
2. Swap the Redis Streams implementation for an AMQP implementation behind the same interface.
3. Run both in parallel during migration (dual-write, then cutover consumers).
4. The CloudEvents envelope format is transport-agnostic — no schema changes needed.

This is a ~1 week effort if the interfaces are clean from day one.

---

## 10. Summary

| Dimension | RabbitMQ | Redis Streams | Decision |
|---|---|---|---|
| Speed | Good (30–50K msg/s) | Excellent (100–500K msg/s) | **Redis** |
| Efficiency | New Erlang VM (~200MB RAM) | Zero marginal cost | **Redis** |
| Security | New attack surface (2 ports) | Existing attack surface | **Redis** |
| Complexity | New infrastructure + ops | Already running | **Redis** |
| Ecosystem fit | `hyperf/amqp` (new dep) | `hyperf/redis` (existing) | **Redis** |
| Future flexibility | Better routing/federation | Simpler, abstraction protects | **Redis** |
| Delivery guarantees | Stronger out-of-box | Adequate with DLQ pattern | **Draw** |
| Dev experience | New local dependency | Nothing changes | **Redis** |

**Final verdict:** RabbitMQ is a superb message broker, but it solves problems Ashchan doesn't have. Redis Streams delivers the required functionality with zero new infrastructure, lower latency, higher throughput, and a smaller attack surface. The implementation plan above provides a clean abstraction layer that allows swapping to RabbitMQ (or NATS, Kafka, etc.) in the future if requirements change.
