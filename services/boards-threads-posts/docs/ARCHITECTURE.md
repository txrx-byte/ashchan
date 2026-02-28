# Boards/Threads/Posts Service Architecture

**Last Updated:** 2026-02-28
**Service:** `boards-threads-posts`
**Framework:** Hyperf 3.x (Swoole-based PHP 8.2+)

## Overview

The Boards/Threads/Posts service is the canonical data store for all imageboard content including boards, threads, posts, and associated metadata. It provides both a native REST API and a 4chan-compatible API layer for client compatibility. The service is built on the Hyperf framework with Swoole coroutine support for high-concurrency workloads.

## System Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    Boards/Threads/Posts Service                          │
│                    (Port 9503 HTTP, 8445 mTLS)                           │
│                                                                          │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │                        HTTP Server Layer                          │   │
│  │  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐   │   │
│  │  │ BoardController │  │ ThreadController│  │ FourChanApi     │   │   │
│  │  │                 │  │                 │  │ Controller      │   │   │
│  │  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘   │   │
│  │  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐   │   │
│  │  │ LivepostController│ │ HealthController│  │ Exception       │   │   │
│  │  │                 │  │                 │  │ Handler         │   │   │
│  │  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘   │   │
│  └───────────┼────────────────────┼────────────────────┼────────────┘   │
│              │                    │                    │                │
│  ┌───────────▼────────────────────▼────────────────────▼────────────┐   │
│  │                        Service Layer                              │   │
│  │  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐   │   │
│  │  │ BoardService    │  │ FourChanApi     │  │ ContentFormatter│   │   │
│  │  │                 │  │ Service         │  │                 │   │   │
│  │  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘   │   │
│  │  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐   │   │
│  │  │ IpRetention     │  │ PiiEncryption   │  │ SiteConfig      │   │   │
│  │  │ Service         │  │ Service         │  │ Service         │   │   │
│  │  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘   │   │
│  └───────────┼────────────────────┼────────────────────┼────────────┘   │
│              │                    │                    │                │
│  ┌───────────▼────────────────────▼────────────────────▼────────────┐   │
│  │                        Model Layer (ActiveRecord)                 │   │
│  │  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────────┐  │   │
│  │  │ Board    │  │ Thread   │  │ Post     │  │ OpenPostBody     │  │   │
│  │  └──────────┘  └──────────┘  └──────────┘  └──────────────────┘  │   │
│  │  ┌──────────┐                                                      │   │
│  │  │ Blotter  │                                                      │   │
│  │  └──────────┘                                                      │   │
│  └───────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │                     Database Layer                                │   │
│  │  ┌─────────────────┐  ┌─────────────────┐                         │   │
│  │  │ PostgresConn    │  │ PostgresConn    │                         │   │
│  │  │ (custom)        │  │ Connector       │                         │   │
│  │  └─────────────────┘  └─────────────────┘                         │   │
│  └───────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                    ┌───────────────┼───────────────┐
                    │               │               │
              ┌─────▼─────┐  ┌──────▼──────┐  ┌────▼────┐
              │PostgreSQL │  │    Redis    │  │ Event   │
              │  Database │  │    Cache    │  │ Bus     │
              └───────────┘  └─────────────┘  └─────────┘
```

## Layer Architecture

### 1. HTTP Server Layer (Controllers)

Controllers handle HTTP request/response lifecycle and input validation:

| Controller | Responsibility | Routes |
|------------|---------------|--------|
| `BoardController` | Board CRUD, blotter, admin board management | `/api/v1/boards/*`, `/api/v1/admin/boards/*` |
| `ThreadController` | Thread/post CRUD, staff actions, IP lookup | `/api/v1/boards/{slug}/threads/*` |
| `LivepostController` | Open post lifecycle for WebSocket liveposting | `/api/v1/posts/*` |
| `FourChanApiController` | 4chan-compatible API (read-only) | `/api/4chan/*` |
| `HealthController` | Health checks for load balancers | `/health` |

**Key Design Decisions:**
- Controllers are `final` classes (no inheritance)
- Dependencies injected via constructor
- Input validation performed before service calls
- IP extraction from `X-Forwarded-For` / `X-Real-IP` headers
- Staff level detection via `X-Staff-Level` header

### 2. Service Layer (Business Logic)

Services encapsulate business logic and coordinate between models:

| Service | Responsibility |
|---------|---------------|
| `BoardService` | Board/thread/post CRUD, caching, event publishing |
| `FourChanApiService` | Transform ashchan data to 4chan API JSON format |
| `ContentFormatter` | Greentext, quote links, spoilers, code blocks |
| `IpRetentionService` | Automated PII retention and deletion |
| `PiiEncryptionService` | XChaCha20-Poly1305 encryption for IP addresses |
| `SiteConfigService` | Database-backed configuration with Redis caching |

**Key Design Decisions:**
- Services are `final` classes (no inheritance)
- No direct HTTP dependencies (framework-agnostic)
- Graceful degradation when Redis unavailable
- Memory-safe key handling (keys wiped after use)
- Event publishing for cross-service communication

### 3. Model Layer (Data Access)

Models use ActiveRecord pattern via Hyperf's database component:

| Model | Table | Purpose |
|-------|-------|---------|
| `Board` | `boards` | Board definitions and settings |
| `Thread` | `threads` | Thread containers with bump tracking |
| `Post` | `posts` | Individual posts (OP and replies) |
| `OpenPostBody` | `open_post_bodies` | Liveposting draft storage |
| `Blotter` | `blotter` | Site announcements |

**Key Design Decisions:**
- Thread IDs allocated from `posts_id_seq` (unified ID space)
- Per-board post numbers via `next_post_no` counter
- IP addresses encrypted at rest with XChaCha20-Poly1305
- Poster IDs deterministic per IP+thread+day
- Soft deletes via `deleted` flag (not physical deletion)

### 4. Database Layer (Custom Connectivity)

Custom PostgreSQL connection and connector for security:

| Class | Purpose |
|-------|---------|
| `PostgresConnection` | Proper PDO type binding for PostgreSQL |
| `PostgresConnector` | Input validation on connection parameters |

**Key Design Decisions:**
- SQL injection prevention via parameter validation
- Proper PDO type mapping (bool → PARAM_BOOL)
- Allowlist-based charset/timezone validation

## Data Flow

### Thread Creation Flow

```
┌──────────┐     ┌─────────────────┐     ┌─────────────┐     ┌──────────┐
│  Client  │────▶│ ThreadController│───▶│ BoardService│────▶│  Thread  │
└──────────┘     └─────────────────┘     └─────────────┘     └──────────┘
                        │                      │
                        │                      ▼
                        │              ┌─────────────┐
                        │              │   Content   │
                        │              │  Formatter  │
                        │              └─────────────┘
                        │                      │
                        ▼                      ▼
                 ┌─────────────┐     ┌─────────────┐
                 │  Response   │     │EventPublisher│
                 └─────────────┘     └─────────────┘
                                            │
                                            ▼
                                     ┌─────────────┐
                                     │   Message   │
                                     │    Queue    │
                                     └─────────────┘
```

### Post Creation Flow

```
1. Client POST /api/v1/boards/{slug}/threads/{id}/posts
2. ThreadController validates:
   - Thread exists
   - Thread not locked/archived
   - Content length limits
   - Media required (unless text-only board)
3. BoardService.createPost():
   a. Allocate board_post_no atomically
   b. Parse name/tripcode
   c. Format content (greentext, quotes, etc.)
   d. Generate poster_id (deterministic hash)
   e. Encrypt IP address
   f. Insert post within transaction
   g. Update thread counters (reply_count, image_count)
   h. Update bumped_at if not sage
   i. Publish post.created event
4. Return post data with assigned ID
```

### Board Listing Flow (Cached)

```
1. Client GET /api/v1/boards
2. BoardService.listBoards():
   a. Check Redis cache key "boards:all"
      - Hit: Return cached JSON
      - Miss: Continue to DB
   b. Query: SELECT * FROM boards WHERE archived=false ORDER BY category, slug
   c. Cache result for 300 seconds
3. Return boards array
```

## Caching Strategy

### Three-Tier Caching Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        Caching Layers                            │
│                                                                  │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐  │
│  │   L1: Varnish   │  │   L2: Redis     │  │  L3: Service    │  │
│  │   (HTTP Cache)  │  │   (App Cache)   │  │  (In-Memory)    │  │
│  │                 │  │                 │  │                 │  │
│  │  - Full pages   │  │  - Query results│  │  - Config values│  │
│  │  - 4chan API    │  │  - Session data │  │  - Static data  │  │
│  │  - 10s TTL      │  │  - 60-300s TTL  │  │  - No expiry    │  │
│  └─────────────────┘  └─────────────────┘  └─────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

### Redis Cache Keys

| Key Pattern | TTL | Purpose | Invalidation |
|-------------|-----|---------|--------------|
| `boards:all` | 300s | All active boards | Board CRUD |
| `board:{slug}` | 300s | Single board | Board CRUD |
| `blotter:recent` | 120s | Recent blotter entries | Blotter update |
| `thread:{id}` | 120s | Full thread data | Post created/deleted |
| `catalog:{slug}` | 60s | Board catalog | Thread/post changes |
| `site_config:all` | 60s | All site settings | Setting change |

### Cache Invalidation Strategy

```php
// Board cache invalidation uses SCAN (not KEYS)
private function invalidateBoardCaches(): void
{
    $cursor = null;
    $keysToDelete = [];
    do {
        $result = $this->redis->scan($cursor, 'board:*', 100);
        if ($result !== false && count($result[1]) > 0) {
            $keysToDelete = array_merge($keysToDelete, $result[1]);
        }
    } while ($cursor > 0);
    if (count($keysToDelete) > 0) {
        $this->redis->del(...$keysToDelete);
    }
}
```

**Why SCAN not KEYS:**
- KEYS blocks Redis during execution (O(N) on entire keyspace)
- SCAN is non-blocking, returns cursor-based batches
- Production-safe for large keyspaces

### N+1 Query Prevention

Batch loading patterns prevent N+1 queries:

```php
// ❌ Bad - N+1 query
foreach ($threads as $thread) {
    $op = Post::query()->where('thread_id', $thread->id)->where('is_op', true)->first();
}

// ✅ Good - batch load all OPs
$threadIds = $threads->pluck('id')->toArray();
$allOps = Post::query()
    ->whereIn('thread_id', $threadIds)
    ->where('is_op', true)
    ->get()
    ->keyBy('thread_id');

foreach ($threads as $thread) {
    $op = $allOps->get($thread->id);  // O(1) lookup
}
```

### Window Function for Latest Replies

```php
// Get latest 5 replies per thread using PostgreSQL window function
$replyRows = Db::select(
    "SELECT p.* FROM (
        SELECT p2.*, ROW_NUMBER() OVER (PARTITION BY p2.thread_id ORDER BY p2.id DESC) AS rn
        FROM posts p2
        WHERE p2.thread_id = ANY(?)
        AND p2.is_op = false
        AND p2.deleted = false
    ) p WHERE p.rn <= 5",
    ['{' . implode(',', $threadIds) . '}']
);
```

**Why Window Functions:**
- Single query instead of N queries
- PostgreSQL optimizes window functions efficiently
- Avoids loading ALL replies into memory

## Event Publishing

### Domain Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `thread.created` | New thread created | `{thread_id, board_id, author_id}` |
| `post.created` | New post created | `{post_id, thread_id, board_id, is_op}` |
| `thread.deleted` | Thread deleted | `{thread_id, board_id}` |
| `post.deleted` | Post deleted | `{post_id, thread_id}` |

### Event Flow

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  BoardService   │────▶│ EventPublisher  │────▶│  Message Queue  │
│                 │     │ (CloudEvents)   │     │  (RabbitMQ)     │
└─────────────────┘     └─────────────────┘     └─────────────────┘
                                                        │
                    ┌───────────────────────────────────┼───────────┐
                    │                                   │           │
              ┌─────▼─────┐                     ┌──────▼──────┐     │
              │  Search   │                     │  Moderation │     │
              │  Indexer  │                     │  Service    │     │
              └───────────┘                     └─────────────┘     │
```

### Event Publishing Pattern

```php
// In BoardService::createThread()
$this->eventPublisher->publish(
    CloudEvent::create(
        type: EventTypes::THREAD_CREATED,
        source: 'boards-threads-posts',
        data: [
            'thread_id' => $thread->id,
            'board_id' => $board->id,
            'author_id' => $authorId ?? null,
        ]
    )
);
```

## Connection Pool Configuration

### Database Pool

```php
// config/autoload/databases.php
'default' => [
    'driver' => 'pgsql',
    'host' => env('DB_HOST', 'postgres'),
    'port' => env('DB_PORT', 5432),
    'database' => env('DB_DATABASE', 'ashchan'),
    'username' => env('DB_USER', 'ashchan'),
    'password' => env('DB_PASSWORD', 'ashchan'),
    'pool' => [
        'min_connections' => 1,
        'max_connections' => 10,
        'connect_timeout' => 10.0,
        'wait_timeout' => 3.0,
        'heartbeat' => -1,
        'max_idle_time' => 60.0,
    ],
],
```

### Redis Pool

```php
// config/autoload/redis.php
'default' => [
    'host' => env('REDIS_HOST', 'redis'),
    'port' => env('REDIS_PORT', 6379),
    'auth' => env('REDIS_AUTH', null),
    'db' => 0,
    'pool' => [
        'min_connections' => 1,
        'max_connections' => 10,
        'connect_timeout' => 10.0,
        'wait_timeout' => 3.0,
        'heartbeat' => -1,
        'max_idle_time' => 60.0,
    ],
],
```

### Swoole Worker Configuration

```php
// config/autoload/server.php
'mode' => SWOOLE_PROCESS,
'settings' => [
    'worker_num' => 4,           // Workers per container
    'max_request' => 100000,     // Recycle after N requests
    'max_coroutine' => 100000,   // Max concurrent coroutines
    'hook_flags' => SWOOLE_HOOK_ALL,
],
```

## Performance Characteristics

| Operation | Complexity | Notes |
|-----------|------------|-------|
| Board list (cache hit) | O(1) | Redis lookup |
| Board list (cache miss) | O(N) | DB query, N = board count |
| Thread index | O(P × R) | P = threads per page, R = replies loaded |
| Full thread view | O(N) | N = post count in thread |
| Catalog | O(T × L) | T = threads, L = last_replies count |
| Post creation | O(1) | Single insert + counter update |
| Thread creation | O(1) | Transaction with sequence allocation |

## Scaling Considerations

### Horizontal Scaling

- Service is stateless (all state in PostgreSQL/Redis)
- Multiple instances behind load balancer
- Cache shared via Redis cluster
- Database connections pooled per instance

### Read Replicas

Future enhancement: Route read queries to read replicas:

```php
// Future: Read/write splitting
'read' => [
    'host' => env('DB_READ_HOST', 'postgres-read'),
],
'write' => [
    'host' => env('DB_HOST', 'postgres'),
],
```

### Sharding Strategy

For extreme scale, shard by board:

```
boards-threads-posts-b    → /b/ board data
boards-threads-posts-g    → /g/ board data
boards-threads-posts-v    → /v/ board data
```

## Monitoring and Observability

### Health Endpoints

- `GET /health` - Basic liveness check (no dependencies)
- Future: `GET /health/ready` - Readiness check (DB + Redis)

### Logging

- Format: JSON to STDERR
- Categories: `pii-encryption`, `site-config`, `ip-retention`
- Levels: DEBUG/INFO in local, WARNING+ in production

### Metrics (Future)

- Prometheus metrics via Hyperf/Prometheus
- Key metrics: posts/second, threads/second, cache hit rate

## Related Documentation

- [API Reference](API.md) - Complete API documentation
- [Security Model](SECURITY.md) - Security considerations
- [Type Hinting Guide](TYPE_HINTING_GUIDE.md) - PHPStan 10 compliance
- [Troubleshooting](TROUBLESHOOTING.md) - Common issues
