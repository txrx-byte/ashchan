# Message Queue Architecture Decision

**Decision Date:** 2026-02-28  
**Status:** ✅ Approved — Redis Streams Selected

---

## Executive Summary

Ashchan uses **Redis Streams** as its sole message queue and event bus infrastructure. RabbitMQ was evaluated but rejected due to operational complexity, additional attack surface, and misalignment with Ashchan's zero-new-dependencies philosophy.

**Architecture:**
- **Event Bus:** Redis Streams (`ashchan:events`) with CloudEvents-compatible payloads
- **Consumer Pattern:** Competing consumers via consumer groups (`XREADGROUP`)
- **Dead Letter Queue:** Separate stream (`ashchan:events:dlq`) for failed messages
- **Pub/Sub:** Redis Pub/Sub for real-time WebSocket fan-out (fire-and-forget)

---

## Decision Rationale

### Why Redis Streams?

| Criterion | Redis Streams | RabbitMQ | Winner |
|-----------|---------------|----------|--------|
| **Infrastructure** | Already running (zero new deps) | New Erlang/OTP service | **Redis** |
| **Throughput** | 100K–500K msg/s | 30K–50K msg/s | **Redis** |
| **Latency** | <1ms | 1–5ms (durable) | **Redis** |
| **Memory Footprint** | 0 MB marginal | ~100–200 MB baseline | **Redis** |
| **Attack Surface** | No new ports | Ports 5672 + 15672 | **Redis** |
| **Operational Burden** | None (existing monitoring) | Separate runbook, upgrades | **Redis** |
| **PHP Ecosystem** | `hyperf/redis` (already wired) | `php-amqplib` (new dep) | **Redis** |
| **Ordering** | Per-stream strict FIFO | Per-queue FIFO | **Draw** |
| **Dead Letter Queues** | Manual (separate stream) | First-class (`x-dead-letter-exchange`) | **RabbitMQ** |
| **Routing** | Single stream + consumer groups | Exchanges (direct/topic/fanout) | **RabbitMQ** |

### Enterprise Alignment

Redis Streams aligns with Ashchan's enterprise principles:

1. **Minimal Attack Surface** — No new ports, protocols, or authentication systems
2. **Operational Simplicity** — Zero new infrastructure to monitor, backup, or upgrade
3. **Horizontal Scalability** — Redis Cluster/Sentinel already part of architecture
4. **Security** — Single TLS configuration, single cert chain, single ACL system
5. **Performance** — 5–10× higher throughput for bursty imageboard traffic
6. **Team Expertise** — Codebase demonstrates Redis proficiency; no RabbitMQ artifacts

---

## Implementation Details

### Stream Topology

```
┌─────────────────────────────────────────────────────────────┐
│                     Redis (DB 6)                            │
│                                                             │
│  ┌─────────────────┐     ┌─────────────────┐               │
│  │ ashchan:events  │────▶│ Consumer Groups │               │
│  │ (Main Stream)   │     │ - search-index  │               │
│  │ MAXLEN ~100,000 │     │ - moderation    │               │
│  └────────┬────────┘     │ - gateway-cache │               │
│           │              │ - websocket-fan │               │
│           │              └─────────────────┘               │
│           │                                                │
│           ▼                                                │
│  ┌─────────────────┐                                       │
│  │ ashchan:events:dlq │                                    │
│  │ (Dead Letter)   │                                       │
│  └─────────────────┘                                       │
└─────────────────────────────────────────────────────────────┘
```

### Event Publishing

**Location:** `contracts/php/src/EventBus/EventPublisher.php`

```php
$event = CloudEvent::new(
    type: 'post.created',
    source: 'boards-threads-posts',
    subject: "post:{$postId}",
    data: ['post_id' => $postId, 'thread_id' => $threadId]
);

$eventPublisher->publish($event);
// Returns: stream entry ID (e.g., "1709123456789-0")
```

**Characteristics:**
- **Durability:** AOF persistence (Redis config)
- **Trimming:** Approximate `MAXLEN ~100,000` to prevent unbounded growth
- **Failure Mode:** Returns `false` on error; never throws (database is source of truth)
- **Idempotency:** Event ID (`event.id`) ensures exactly-once processing

### Event Consumption

**Location:** `contracts/php/src/EventBus/EventConsumer.php`

```php
class SearchIndexingConsumer extends EventConsumer
{
    protected string $group = 'search-indexing';
    
    protected function processEvent(CloudEvent $event): void
    {
        match ($event->type) {
            'post.created' => $this->indexPost($event->data),
            'post.updated' => $this->updatePost($event->data),
            'post.deleted' => $this->deletePost($event->data),
            default => null,
        };
    }
}
```

**Characteristics:**
- **Competing Consumers:** Multiple instances with same group name
- **Acknowledgment:** `XACK` on successful processing
- **Retry Logic:** Up to 3 retries before dead-letter
- **Stale Message Reclaim:** `XCLAIM` after 60s idle time
- **Filtering:** `supports()` method restricts event types

### Dead Letter Queue Handling

Messages that fail processing >3 times are moved to `ashchan:events:dlq`:

```php
// DLQ entry contains:
[
    'original_id' => '1709123456789-0',
    'event' => '{"type":"post.created",...}',
    'group' => 'search-indexing',
    'consumer' => 'hostname-123',
    'error' => 'Connection timeout',
    'failed_at' => '2026-02-28T12:34:56Z'
]
```

**Manual Recovery:**
```bash
# Inspect DLQ
redis-cli XREAD STREAMS ashchan:events:dlq 0-0 COUNT 10

# Manually reprocess (application-specific)
redis-cli XDEL ashchan:events:dlq 1709123456789-0
```

### Redis Pub/Sub for WebSocket Fan-Out

For real-time features (live thread updates), use **Redis Pub/Sub** (separate from Streams):

```php
// Publisher (boards-threads-posts)
$redis->publish('live:thread:123', json_encode([
    'type' => 'post.created',
    'post_id' => 456,
]));

// Subscriber (WebSocket worker)
$redis->subscribe(['live:thread:123'], function ($message) {
    $this->pushToClient($message);
});
```

**Characteristics:**
- **Fire-and-Forget:** No persistence; missed messages acceptable
- **Real-Time:** Sub-millisecond fan-out to all WebSocket workers
- **Complementary:** Used alongside Streams for durability + real-time push

---

## Configuration

### Environment Variables

```bash
# Event Bus (Redis Streams)
EVENTS_STREAM_NAME=ashchan:events
EVENTS_DLQ_STREAM=ashchan:events:dlq
EVENTS_REDIS_DB=6
EVENTS_BATCH_SIZE=100
EVENTS_POLL_INTERVAL=1000
EVENTS_MAX_RETRIES=3

# Consumer Group (per service)
EVENTS_CONSUMER_GROUP=search-indexing  # Unique per service
```

### Redis Configuration

```yaml
# config/autoload/redis.php
return [
    'default' => [
        'host' => env('REDIS_HOST', 'redis'),
        'port' => (int) env('REDIS_PORT', 6379),
        'db' => (int) env('REDIS_DB', 0),
    ],
    
    // Dedicated connection for event bus (DB 6)
    'events' => [
        'host' => env('REDIS_HOST', 'redis'),
        'port' => (int) env('REDIS_PORT', 6379),
        'db' => (int) env('EVENTS_REDIS_DB', 6),
    ],
];
```

### AOF Persistence (Recommended)

```conf
# redis.conf
appendonly yes
appendfsync everysec
auto-aof-rewrite-percentage 100
auto-aof-rewrite-min-size 64mb
```

---

## Service Integration

### Producers (Event Publishers)

| Service | Events Published |
|---------|------------------|
| `boards-threads-posts` | `post.created`, `post.updated`, `post.deleted`, `thread.created`, `thread.deleted` |
| `moderation-anti-spam` | `post.flagged`, `post.quarantined`, `user.banned` |
| `media-uploads` | `media.uploaded`, `media.deleted` |

### Consumers (Event Subscribers)

| Service | Consumer Group | Events Consumed |
|---------|----------------|-----------------|
| `search-indexing` | `search-index` | `post.created`, `post.updated`, `post.deleted` |
| `api-gateway` | `gateway-cache` | `post.*`, `thread.*` (cache invalidation) |
| `moderation-anti-spam` | `moderation` | `post.created` (risk scoring) |

---

## Monitoring

### Key Metrics

```bash
# Stream length (backlog)
redis-cli XLEN ashchan:events

# Consumer group lag
redis-cli XINFO GROUPS ashchan:events

# Pending messages (unacknowledged)
redis-cli XPENDING ashchan:events search-indexing

# DLQ depth
redis-cli XLEN ashchan:events:dlq

# Redis memory usage
redis-cli INFO memory
```

### Health Checks

```php
// Health check endpoint
$health = [
    'stream_length' => $publisher->streamLength(),
    'stream_info' => $publisher->streamInfo(),
    'dlq_length' => $redis->xLen('ashchan:events:dlq'),
];

// Alert if:
// - Stream length > 10,000 (consumer lag)
// - DLQ length > 100 (processing failures)
// - Pending messages > 1,000 (consumer stuck)
```

---

## Failure Scenarios

### 1. Consumer Crash

**Detection:** Messages remain pending in `XPENDING`  
**Recovery:** `XCLAIM` reassigns to another consumer after idle timeout (60s)  
**Data Loss:** None (messages persist until ACKed)

### 2. Redis Unavailable

**Detection:** `XADD`/`XREAD` returns `false`  
**Recovery:** Service logs error and continues (database is source of truth)  
**Data Loss:** Events during outage not published (acceptable; can backfill from DB)

### 3. DLQ Overflow

**Detection:** `XLEN ashchan:events:dlq` > threshold  
**Recovery:** Manual inspection and reprocessing  
**Prevention:** Fix root cause (bug, timeout, resource exhaustion)

### 4. Stream Growth

**Detection:** `XLEN ashchan:events` approaches `MAXLEN`  
**Recovery:** Automatic trimming (`~MAXLEN`)  
**Tuning:** Increase `MAXLEN` if consumers need longer replay window

---

## Migration Path (If RabbitMQ Needed Later)

While Redis Streams is the chosen solution, migration to RabbitMQ is possible:

1. **Abstraction Layer:** `EventPublisherInterface` already exists
2. **Dual-Write Phase:** Publish to both Redis Streams and RabbitMQ
3. **Consumer Migration:** Switch consumers one-by-one to RabbitMQ
4. **Cutover:** Disable Redis Streams publishing

**Why This Exists:** The `EventPublisherInterface` contract allows swapping implementations without changing producer code.

---

## References

- **Implementation:** `contracts/php/src/EventBus/EventPublisher.php`
- **Consumer Base:** `contracts/php/src/EventBus/EventConsumer.php`
- **Event Schema:** `contracts/php/src/EventBus/CloudEvent.php`
- **Evaluation:** `docs/RABBITMQ_EVALUATION.md`
- **Architecture:** `docs/architecture.md`

---

## Decision Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-02-28 | Select Redis Streams | Operational simplicity, zero new dependencies, 5–10× performance |
| 2026-02-28 | Reject RabbitMQ | Erlang/OTP complexity, new attack surface, misaligned with non-goals |
