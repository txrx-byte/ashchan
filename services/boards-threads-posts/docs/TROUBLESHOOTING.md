# Boards/Threads/Posts Service Troubleshooting Guide

**Last Updated:** 2026-02-28

## Overview

This guide provides solutions for common issues encountered when developing, deploying, and operating the Boards/Threads/Posts service.

---

## Quick Reference

| Symptom | Likely Cause | Solution |
|---------|--------------|----------|
| "Board ID is missing or invalid" | Board not loaded from DB | Check board slug, verify DB connection |
| "Failed to generate ID" | PostgreSQL sequence issue | Check `posts_id_seq` exists |
| "Redis connection failed" | Redis unavailable | Check Redis connectivity |
| Thread creation slow | N+1 queries, no batch loading | Verify batch loading patterns |
| Cache not invalidating | Redis SCAN failing | Check Redis permissions |
| PHPStan errors on `Db::` calls | Static method warnings | These are ignored by config |
| "PII_ENCRYPTION_KEY must be configured" | Missing environment variable | Set key in `.env` |

---

## Environment Configuration Issues

### Error: `PII_ENCRYPTION_KEY must be configured`

**Symptom:**

```
RuntimeException: PII_ENCRYPTION_KEY not set — PII will be stored in plaintext
```

**Cause:** The service requires the encryption key for secure IP address storage.

**Solution:**

1. Generate secure key:

```bash
# Generate PII encryption key (32-byte hex)
PII_KEY=$(openssl rand -hex 32)
```

2. Add to `.env`:

```env
PII_ENCRYPTION_KEY=<paste PII_KEY here>
IP_HASH_SALT=<random secret string>
```

3. Restart the service

**Prevention:** Include key generation in deployment scripts.

---

### Error: `Redis connection failed`

**Symptom:**

```
RedisException: Connection refused
```

**Cause:** Redis is unavailable or misconfigured.

**Solution:**

1. Check Redis connectivity:

```bash
redis-cli -h $REDIS_HOST -p $REDIS_PORT ping
# Should return: PONG
```

2. Verify environment variables:

```env
REDIS_HOST=redis
REDIS_PORT=6379
```

3. Check firewall rules (if Redis is on separate host)

**Graceful Degradation:** The service continues operating with database fallback when Redis is unavailable. Redis failures are logged but don't cause request failures.

---

### Error: `Database connection failed`

**Symptom:**

```
PDOException: SQLSTATE[08006] [7] connection refused
```

**Cause:** PostgreSQL is unavailable or credentials are incorrect.

**Solution:**

1. Check PostgreSQL connectivity:

```bash
psql -h $DB_HOST -p $DB_PORT -U $DB_USER -d $DB_DATABASE
```

2. Verify environment variables:

```env
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=ashchan
DB_USER=ashchan
DB_PASSWORD=ashchan
```

3. Check PostgreSQL logs for connection issues

---

## Thread and Post Issues

### Symptom: Thread creation is slow

**Possible Causes:**

1. **N+1 queries in thread index**
   - Each thread loading OP separately
   - Each thread loading replies separately

2. **Missing batch loading**

3. **Database connection pool exhausted**

**Debugging Steps:**

1. Check query logs for N+1 pattern:

```bash
# Look for repeated similar queries
docker logs boards-threads-posts 2>&1 | grep "SELECT.*FROM posts"
```

2. Verify batch loading in code:

```php
// ✅ Good - batch load all OPs
$threadIds = $threads->pluck('id')->toArray();
$allOps = Post::query()
    ->whereIn('thread_id', $threadIds)
    ->where('is_op', true)
    ->get()
    ->keyBy('thread_id');
```

3. Check database connection pool:

```sql
SELECT count(*) FROM pg_stat_activity
WHERE application_name = 'boards-threads-posts';
```

**Solutions:**

- Ensure window function is used for latest replies
- Increase database pool size if needed
- Add indexes on `thread_id`, `is_op`, `deleted`

---

### Symptom: "Failed to generate ID"

**Symptom:**

```
RuntimeException: Failed to generate ID: ...
```

**Cause:** PostgreSQL sequence `posts_id_seq` doesn't exist or is inaccessible.

**Solution:**

1. Check sequence exists:

```sql
SELECT sequencename FROM pg_sequences WHERE sequencename = 'posts_id_seq';
```

2. Create sequence if missing:

```sql
CREATE SEQUENCE IF NOT EXISTS posts_id_seq;
```

3. Grant permissions:

```sql
GRANT USAGE, SELECT ON SEQUENCE posts_id_seq TO ashchan;
```

---

### Symptom: "Board ID is missing or invalid"

**Symptom:**

```
RuntimeException: Board ID is missing or invalid: null
```

**Cause:** Board was not loaded from database before use.

**Solution:**

1. Verify board slug is correct:

```sql
SELECT id, slug FROM boards WHERE slug = 'b';
```

2. Check board loading in controller:

```php
$board = $this->boardService->getBoard($slug);
if (!$board) {
    return $this->response->json(['error' => 'Board not found'])->withStatus(404);
}
```

3. Verify board cache is working:

```bash
redis-cli GET "board:b"
```

---

## Cache Issues

### Symptom: Cache not invalidating after board update

**Possible Causes:**

1. **Redis SCAN failing**
   - Permission issues
   - Redis cluster configuration

2. **Cache keys not matching pattern**

**Debugging Steps:**

1. Check Redis keys:

```bash
redis-cli KEYS "board:*"
```

2. Test SCAN manually:

```bash
redis-cli SCAN 0 MATCH "board:*" COUNT 100
```

3. Check Redis logs for errors

**Solutions:**

1. Verify SCAN implementation:

```php
private function invalidateBoardCaches(): void
{
    try {
        $this->redis->del('boards:all');
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
    } catch (\Throwable $e) {
        // Redis unavailable - log but don't fail
    }
}
```

2. Manual cache clear if needed:

```bash
redis-cli KEYS "board:*" | xargs redis-cli DEL
redis-cli DEL "boards:all"
```

---

### Symptom: Stale thread data in cache

**Possible Causes:**

1. **Cache TTL too long**
2. **Cache not invalidated on post creation**
3. **Redis replication lag (cluster mode)**

**Solution:**

1. Check cache TTL:

```bash
redis-cli TTL "thread:12345"
# Should be ~120 seconds
```

2. Verify cache invalidation on post creation:

```php
// In BoardService::createPost()
// Invalidate thread cache after post creation
$this->redis->del("thread:{$threadId}");
```

3. Manual cache clear:

```bash
redis-cli DEL "thread:12345"
```

---

## N+1 Query Fixes

### Symptom: Slow catalog loading

**Problem:** Loading catalog triggers N+1 queries for OP data.

**Before (N+1):**

```php
// ❌ Bad - query per thread
foreach ($threads as $thread) {
    $op = Post::query()
        ->where('thread_id', $thread->id)
        ->where('is_op', true)
        ->first();
}
```

**After (Batch):**

```php
// ✅ Good - single query for all OPs
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

---

### Symptom: Slow thread index with many replies

**Problem:** Loading ALL replies then filtering in PHP.

**Before (Inefficient):**

```php
// ❌ Bad - loads all replies
$allReplies = Post::query()
    ->whereIn('thread_id', $threadIds)
    ->where('is_op', false)
    ->get()
    ->groupBy('thread_id');

foreach ($threads as $thread) {
    $replies = $allReplies[$thread->id]->take(5);
}
```

**After (Window Function):**

```php
// ✅ Good - PostgreSQL returns only latest 5 per thread
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

---

## Event Delivery Failures

### Symptom: Events not published

**Possible Causes:**

1. **EventPublisher not configured**
2. **Message queue unavailable**
3. **Exception in event publishing**

**Debugging Steps:**

1. Check event publisher injection:

```php
public function __construct(
    private EventPublisherInterface $eventPublisher,
) {
}
```

2. Verify event publishing code:

```php
try {
    $this->eventPublisher->publish(
        CloudEvent::create(
            type: EventTypes::POST_CREATED,
            source: 'boards-threads-posts',
            data: ['post_id' => $post->id]
        )
    );
} catch (\Throwable $e) {
    // Log but don't fail the request
    error_log("Event publishing failed: " . $e->getMessage());
}
```

3. Check message queue connectivity:

```bash
# For RabbitMQ
rabbitmqctl list_queues
```

**Solutions:**

- Ensure event publishing is wrapped in try-catch
- Configure dead-letter queue for failed events
- Monitor event delivery metrics

---

## Performance Issues

### Symptom: Slow post creation

**Possible Causes:**

1. **Transaction contention**
2. **Sequence allocation bottleneck**
3. **Cache write latency**

**Debugging Steps:**

1. Check PostgreSQL locks:

```sql
SELECT * FROM pg_locks WHERE NOT granted;
```

2. Check sequence performance:

```sql
SELECT last_value, is_called FROM posts_id_seq;
```

3. Profile post creation:

```php
$start = microtime(true);
// ... create post ...
$duration = microtime(true) - $start;
error_log("Post creation took {$duration}s");
```

**Solutions:**

- Use `CONCURRENTLY` for index creation during migrations
- Consider batch sequence allocation for high-traffic boards
- Async cache writes (don't block on Redis)

---

### Symptom: High memory usage

**Possible Causes:**

1. **Swoole worker not recycling**
2. **Large result sets**
3. **Memory leaks in dependencies**

**Solutions:**

1. Configure worker recycling:

```php
// config/autoload/server.php
'max_request' => 100000,  // Recycle after 100k requests
```

2. Check for N+1 queries loading large datasets

3. Monitor memory:

```bash
ps aux | grep hyperf | awk '{print $6/1024 " MB " $11}'
```

---

## Liveposting Issues

### Symptom: Open post not closing

**Possible Causes:**

1. **Edit password mismatch**
2. **Post already closed**
3. **Expiry time passed**

**Debugging Steps:**

1. Check open post exists:

```sql
SELECT post_id, updated_at FROM open_post_bodies WHERE post_id = 12389;
```

2. Check post editing state:

```sql
SELECT is_editing, edit_expires_at FROM posts WHERE id = 12389;
```

3. Verify password hash:

```php
if (!password_verify($password, $post->edit_password_hash)) {
    return null;  // Reclaim failed
}
```

---

### Symptom: Expired posts not closing

**Possible Causes:**

1. **Scheduler not running**
2. **Cron job misconfigured**
3. **Exception in closeExpired**

**Solution:**

1. Verify scheduler is running:

```bash
# Check for scheduler process
ps aux | grep hyperf.php schedule:run
```

2. Manually trigger close:

```bash
curl -X POST http://localhost:9503/api/v1/posts/close-expired
```

3. Check logs for errors:

```bash
docker logs boards-threads-posts 2>&1 | grep "closeExpired"
```

---

## PHPStan Issues

### Warning: `Static call to instance method Db::table()`

**Symptom:**

```
PHPStan reports: Static call to instance method Hyperf\DbConnection\Db::table()
```

**Cause:** This is a known framework pattern that PHPStan cannot analyze.

**Solution:** These errors are ignored in `phpstan.neon`:

```neon
ignoreErrors:
    - '#Static call to instance method Hyperf\DbConnection\Db::.*#'
```

If you see this error, ensure your `phpstan.neon` is being used:

```bash
php vendor/bin/phpstan analyse --configuration phpstan.neon
```

---

### Error: `Return type has no value type`

**Symptom:**

```
Method X::getY() return type has no value type
```

**Cause:** Missing generic type annotation for arrays.

**Solution:**

```php
// ❌ Before
/** @return array */
public function getThreads(): array { }

// ✅ After
/** @return array<int, array<string, mixed>> */
public function getThreads(): array { }
```

---

## Encryption Issues

### Symptom: Decryption returns `[DECRYPTION_FAILED]`

**Possible Causes:**

1. **Wrong encryption key**
   - Key was rotated or changed

2. **Data tampered**
   - Poly1305 MAC verification failed

3. **Corrupted data**
   - Base64 decoding failed

**Debugging Steps:**

1. Check encryption key matches:

```bash
echo $PII_ENCRYPTION_KEY
```

2. Check data format:

```sql
-- Check for properly formatted encrypted data
SELECT ip_address FROM posts
WHERE ip_address NOT LIKE 'enc:%' AND ip_address IS NOT NULL;
-- Should return 0 rows (or legacy unencrypted data)
```

3. Check logs for specific error:

```bash
docker logs boards-threads-posts 2>&1 | grep "PII decryption failed"
```

---

## Logging and Debugging

### Enable Debug Logging

1. Set log level in `.env`:

```env
APP_ENV=local
```

2. Check logs:

```bash
# View recent errors
docker logs boards-threads-posts 2>&1 | grep ERROR

# View PII encryption issues
docker logs boards-threads-posts 2>&1 | grep "pii-encryption"

# View all logs (JSON format)
docker logs boards-threads-posts 2>&1 | head -100
```

### Log Format

Logs are JSON-formatted to STDERR:

```json
{"level":"error","message":"PII decryption failed","context":{},"timestamp":"2026-02-28T12:00:00Z"}
```

---

## Testing Issues

### Error: `Cannot modify final class`

**Symptom:**

```
PHPUnit\Framework\MockObject\GeneratorException: Cannot mock final class
```

**Cause:** Service classes are `final` and cannot be mocked directly.

**Solution:**

1. Use DG\BypassFinals in tests:

```php
// tests/bootstrap.php
DG\BypassFinals::enable();
```

2. Or test through interfaces:

```php
// Use PiiEncryptionServiceInterface for injection
public function __construct(
    private PiiEncryptionServiceInterface $piiEncryption,
) {
}
```

---

## Emergency Procedures

### Clear All Caches

**Use Case:** Cache corruption or stale data issues.

```bash
# Clear all service caches
redis-cli KEYS "boards:*" | xargs redis-cli DEL
redis-cli KEYS "board:*" | xargs redis-cli DEL
redis-cli KEYS "thread:*" | xargs redis-cli DEL
redis-cli KEYS "catalog:*" | xargs redis-cli DEL
redis-cli KEYS "blotter:*" | xargs redis-cli DEL
```

### Force Close All Open Posts

**Use Case:** Liveposting system stuck.

```sql
-- Close all open posts
UPDATE posts
SET is_editing = false,
    edit_password_hash = NULL,
    edit_expires_at = NULL
WHERE is_editing = true;

-- Clear open_post_bodies
DELETE FROM open_post_bodies;
```

### Emergency Board Archive

**Use Case:** Board needs immediate archival.

```sql
-- Archive board
UPDATE boards SET archived = true WHERE slug = 'b';

-- Archive all threads
UPDATE threads SET archived = true WHERE board_id = (SELECT id FROM boards WHERE slug = 'b');
```

---

## Getting Help

### Internal Resources

- [Architecture Documentation](ARCHITECTURE.md)
- [API Reference](API.md)
- [Security Model](SECURITY.md)
- [Type Hinting Guide](TYPE_HINTING_GUIDE.md)

### Logs

- Location: STDERR (container logs)
- Format: JSON
- Levels: DEBUG, INFO, WARNING, ERROR

### Support Channels

- Development team: #boards-service-dev
- Operations team: #boards-service-ops
- Security incidents: #security-incidents

---

## Related Documentation

- [Architecture](ARCHITECTURE.md) - System architecture
- [API Reference](API.md) - API documentation
- [Security Model](SECURITY.md) - Security considerations
- [Type Hinting Guide](TYPE_HINTING_GUIDE.md) - PHPStan 10 compliance
