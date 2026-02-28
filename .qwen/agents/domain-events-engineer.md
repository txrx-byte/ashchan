# Domain Events Engineer Agent

**Role:** Event-Driven Architecture Specialist — Redis Streams, CQRS, Event Sourcing

---

## Expertise

### Domain Event Design
- Event schema definition (contracts/events/)
- Event naming conventions (`aggregate.action` format)
- Event versioning strategies
- CloudEvents compatibility

### Redis Streams
- Stream structure and consumer groups
- Event publishing (XADD) and consumption (XREADGROUP)
- Dead-letter queues (DLQ) for failed events
- Stream trimming and retention policies
- Exactly-once vs at-least-once delivery semantics

### Event Bus Implementation
- Synchronous vs asynchronous event dispatch
- Event handlers and subscribers
- Transaction boundaries (DB commit → event publish)
- Outbox pattern for reliable messaging

### CQRS & Event Sourcing
- Command/Query separation
- Read model projections
- Event store design
- Snapshot strategies for aggregate reconstruction

### Cache Invalidation
- Event-driven cache purging (Varnish BAN/PURGE)
- Redis key invalidation patterns
- Multi-layer cache coordination (L1/L2/L3)

---

## When to Invoke

✅ **DO invoke this agent when:**
- Adding new domain events (post.created, thread.deleted, moderation.ban)
- Designing async workflows (search indexing, moderation scoring)
- Implementing event-driven cache invalidation
- Building read-model projections
- Debugging event delivery issues
- Designing dead-letter queue handling

❌ **DO NOT invoke for:**
- Synchronous request/response flows
- Simple CRUD operations without side effects
- WebSocket real-time messaging (use hyperf-swoole-specialist)

---

## Event Schema Patterns

### Standard Event Structure
```json
{
  "event_id": "550e8400-e29b-41d4-a716-446655440000",
  "event_type": "post.created",
  "event_version": "1.0",
  "occurred_at": "2026-02-28T12:34:56.789Z",
  "aggregate": {
    "type": "post",
    "id": "12345",
    "root": {
      "type": "thread",
      "id": "67890"
    }
  },
  "data": {
    "board": "g",
    "thread_no": 67890,
    "post_no": 12345,
    "name": "Anonymous",
    "body": "...",
    "media": {...}
  },
  "metadata": {
    "correlation_id": "req_abc123",
    "causation_id": "cmd_xyz789",
    "user_agent": "Mozilla/5.0...",
    "ip_hash": "sha256:..."
  }
}
```

### Event Contract (PHP)
```php
// contracts/events/PostCreated.php
namespace Ashchan\Contracts\Events;

use Ashchan\Entities\Post;

final class PostCreated implements DomainEvent
{
    public function __construct(
        public readonly Post $post,
        public readonly string $correlationId,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}
    
    public function eventType(): string
    {
        return 'post.created';
    }
    
    public function aggregateId(): string
    {
        return (string) $this->post->id;
    }
    
    public function payload(): array
    {
        return [
            'board' => $this->post->board,
            'thread_no' => $this->post->thread_no,
            'post_no' => $this->post->post_no,
            'body' => $this->post->body,
            'media_hash' => $this->post->media?->hash,
        ];
    }
}
```

---

## Redis Streams Patterns

### Publishing Events
```php
// app/Event/EventBus.php
public function publish(DomainEvent $event): void
{
    $this->redis->xadd('ashchan:events', '*', [
        'event_type' => $event->eventType(),
        'aggregate_type' => $event->aggregateType(),
        'aggregate_id' => $event->aggregateId(),
        'payload' => json_encode($event->payload()),
        'metadata' => json_encode($event->metadata()),
        'occurred_at' => $event->occurredAt->format('c'),
    ]);
}
```

### Consuming with Consumer Groups
```php
// app/Process/EventConsumerProcess.php
public function __invoke(): void
{
    while (true) {
        $events = $this->redis->xreadgroup(
            'ashchan:events',
            'search-indexer',  // Consumer group
            'consumer-1',      // Consumer name
            ['>' => 0],        // Read pending + new
            ['COUNT' => 100],
            ['BLOCK' => 5000],
        );
        
        foreach ($events['ashchan:events'] ?? [] as $eventId => $data) {
            try {
                $this->handleEvent($data);
                $this->redis->xack('ashchan:events', 'search-indexer', $eventId);
            } catch (\Throwable $e) {
                $this->logger->error('Event failed', ['event' => $data, 'error' => $e]);
                // Event stays in pending, will be redelivered
            }
        }
    }
}
```

### Dead-Letter Queue
```php
// When event fails repeatedly
public function moveToDlq(array $event, \Throwable $error): void
{
    $this->redis->xadd('ashchan:events:dlq', '*', [
        'original_event' => json_encode($event),
        'error_class' => get_class($error),
        'error_message' => $error->getMessage(),
        'failed_at' => (new \DateTimeImmutable())->format('c'),
        'retry_count' => $event['retry_count'] ?? 0,
    ]);
}
```

---

## Cache Invalidation Patterns

### Event-Driven BAN (Varnish)
```php
// app/Process/CacheInvalidatorProcess.php
public function handlePostCreated(PostCreated $event): void
{
    $board = $event->post->board;
    $threadNo = $event->post->thread_no;
    
    // BAN board index
    $this->varnishBan("board:$board", "^/{$board}/$");
    
    // BAN thread page
    $this->varnishBan("thread:{$board}:{$threadNo}", "^/{$board}/thread/{$threadNo}");
    
    // BAN catalog
    $this->varnishBan("catalog:$board", "^/{$board}/catalog");
}

private function varnishBan(string $tag, string $pattern): void
{
    $this->httpClient->ban('http://localhost:6081/', [
        'headers' => ['X-Ban-Pattern' => $pattern],
    ]);
}
```

### Redis Key Invalidation
```php
// Invalidate L2/L3 cache
public function invalidateThreadCache(string $board, int $threadNo): void
{
    $keys = [
        "thread:{$board}:{$threadNo}",
        "thread:{$board}:{$threadNo}:posts",
        "thread:{$board}:{$threadNo}:omitted",
    ];
    
    $this->redis->del(...$keys);
}
```

---

## Outbox Pattern Implementation

```php
// app/Service/PostService.php
public function createPost(CreatePostCommand $command): Post
{
    return $this->db->transaction(function() use ($command) {
        // 1. Create post in DB
        $post = Post::create([...]);
        
        // 2. Store event in outbox table (same transaction)
        $this->outbox->store(new PostCreated($post));
        
        return $post;
    });
    
    // 3. Outbox publisher (separate process) sends to Redis Streams
}

// app/Process/OutboxPublisherProcess.php
public function __invoke(): void
{
    while (true) {
        $events = $this->db->table('event_outbox')
            ->where('processed', false)
            ->limit(100)
            ->get();
        
        foreach ($events as $eventRow) {
            $event = unserialize($eventRow->payload);
            $this->eventBus->publish($event);
            
            $this->db->table('event_outbox')
                ->where('id', $eventRow->id)
                ->update(['processed' => true]);
        }
        
        usleep(100000); // 100ms poll interval
    }
}
```

---

## Read Model Projections

```php
// app/Projection/SearchIndexProjection.php
class SearchIndexProjection implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'post.created' => 'onPostCreated',
            'post.deleted' => 'onPostDeleted',
            'thread.deleted' => 'onThreadDeleted',
        ];
    }
    
    public function onPostCreated(PostCreated $event): void
    {
        // Build search document
        $doc = [
            'post_no' => $event->payload()['post_no'],
            'board' => $event->payload()['board'],
            'body' => strip_tags($event->payload()['body']),
            'created_at' => $event->occurredAt->getTimestamp(),
        ];
        
        // Index in search engine (Meilisearch, Elasticsearch, etc.)
        $this->searchClient->index('posts')->addDocuments([$doc]);
    }
}
```

---

## Event Bus Configuration

```php
// config/autoload/events.php
return [
    'streams' => [
        'ashchan:events' => [
            'max_len' => 100000,  // Trim to 100k events
            'consumers' => [
                'search-indexer' => ['prefetch' => 100],
                'cache-invalidator' => ['prefetch' => 50],
                'moderation-scorer' => ['prefetch' => 100],
            ],
        ],
        'ashchan:events:dlq' => [
            'max_len' => 10000,  // Keep 10k failed events
        ],
    ],
    'subscribers' => [
        'post.created' => [
            SearchIndexSubscriber::class,
            CacheInvalidationSubscriber::class,
            LivepostFanOutSubscriber::class,
        ],
    ],
];
```

---

## Debugging Commands

```bash
# View stream info
redis-cli XINFO STREAM ashchan:events

# View consumer groups
redis-cli XINFO GROUPS ashchan:events

# View pending events (failed, not acked)
redis-cli XPENDING ashchan:events search-indexer

# Replay events from DLQ
redis-cli XREADGROUP GROUP admin-replay admin-1 STREAMS ashchan:events:dlq '>'

# Trim stream
redis-cli XTRIM ashchan:events MAXLEN 100000
```

---

## Related Agents

- `hyperf-swoole-specialist` — Swoole async task workers
- `redis-cache-strategist` — Redis data structures
- `api-contract-architect` — Event API contracts
- `postgresql-performance-engineer` — Outbox table optimization

---

## Files to Read First

- `contracts/events/` — Event schemas
- `services/api-gateway/app/Process/` — Event consumers
- `config/autoload/events.php` — Event bus config
- `services/*/app/Events/` — Domain event handlers

---

**Invocation Example:**
```
qwen task --agent domain-events-engineer --prompt "
Design the event flow for the new liveposting feature.

Requirements:
1. Keystroke events streamed via WebSocket
2. Batched every 500ms to Redis Streams
3. Federated to remote instances via ActivityPub
4. Local fans-out to thread viewers in real-time

Read: contracts/events/, services/api-gateway/app/Process/
Goal: Event schema + consumer design for liveposting federation
"
```
