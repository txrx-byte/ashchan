# Auth/Accounts Service Troubleshooting Guide

**Last Updated:** 2026-02-28

## Overview

This guide provides solutions for common issues encountered when developing, deploying, and operating the Auth/Accounts service.

---

## Quick Reference

| Symptom | Likely Cause | Solution |
|---------|--------------|----------|
| "IP_HMAC_KEY or PII_ENCRYPTION_KEY must be configured" | Missing environment variables | Set both keys in `.env` |
| "Redis connection failed" | Redis unavailable | Check Redis connectivity |
| "Session validation always fails" | Key mismatch or Redis down | Verify encryption key, check Redis |
| Login always returns "Invalid credentials" | User banned or Redis down | Check ban status, Redis |
| PHPStan errors on `Db::` calls | Static method warnings | These are ignored by config |
| "Unsupported charset" error | Invalid DB charset | Use allowed charset list |

---

## Environment Configuration Issues

### Error: `IP_HMAC_KEY or PII_ENCRYPTION_KEY must be configured`

**Symptom:**

```
RuntimeException: ip_hmac_key site setting or PII_ENCRYPTION_KEY env must be configured
```

**Cause:** The service requires both encryption keys for secure operation.

**Solution:**

1. Generate secure keys:

```bash
# Generate PII encryption key (32-byte hex)
PII_KEY=$(openssl rand -hex 32)

# Generate IP HMAC key (any secure random string)
HMAC_KEY=$(openssl rand -base64 32)
```

2. Add to `.env`:

```env
PII_ENCRYPTION_KEY=<paste PII_KEY here>
IP_HMAC_KEY=<paste HMAC_KEY here>
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

## Authentication Issues

### Symptom: Login always returns "Invalid credentials"

**Possible Causes:**

1. **User is banned**
   - Check `users.banned` column
   - Check `users.ban_expires_at` for expired bans

2. **Redis is down**
   - Rate limiting may fail-open
   - Session creation may fail silently

3. **Password hash mismatch**
   - Verify password wasn't truncated
   - Check for encoding issues

**Debugging Steps:**

```sql
-- Check if user exists and banned
SELECT id, username, banned, ban_reason, ban_expires_at 
FROM users 
WHERE username = 'testuser';

-- Check for expired bans
SELECT * FROM users 
WHERE banned = true 
  AND ban_expires_at IS NOT NULL 
  AND ban_expires_at < NOW();
```

---

### Symptom: Session validation always fails

**Possible Causes:**

1. **Encryption key changed**
   - Session data encrypted with different key
   - Redis contains stale encrypted data

2. **Redis contains stale data**
   - Old sessions from previous deployment

3. **Database session expired**
   - Sessions past their TTL

**Solution:**

1. Verify `PII_ENCRYPTION_KEY` hasn't changed:

```bash
# Check current key
echo $PII_ENCRYPTION_KEY

# Compare with deployment records
```

2. Clear Redis session cache:

```bash
redis-cli KEYS "session:*" | xargs redis-cli DEL
```

3. Check session TTL in database:

```sql
SELECT COUNT(*) FROM sessions WHERE expires_at < NOW();
```

---

### Symptom: Rate limiting not working

**Possible Causes:**

1. **Redis is down**
   - Rate limiting fails open (allows requests)

2. **Lua script error**
   - Syntax error in rate limit script

3. **IP extraction failing**
   - `remote_addr` is empty

**Debugging Steps:**

1. Check Redis connectivity
2. Verify IP is being extracted:

```php
// Add debug logging
$remoteAddr = $request->server('remote_addr', '');
error_log("Remote IP: " . $remoteAddr);
```

3. Check Redis keys:

```bash
redis-cli KEYS "login_attempts:*"
```

---

## Encryption Issues

### Error: `Failed to encrypt PII data`

**Symptom:**

```
RuntimeException: Failed to encrypt PII data
```

**Cause:** libsodium encryption failed (rare, usually system issue)

**Solution:**

1. Check libsodium is installed:

```bash
php -m | grep sodium
# Should show: sodium
```

2. Verify PHP has access to CSPRNG:

```bash
php -r "echo bin2hex(random_bytes(16));"
# Should output 32 hex characters
```

3. Check system entropy:

```bash
cat /proc/sys/kernel/random/entropy_avail
# Should be > 1000
```

---

### Symptom: Decryption returns `[DECRYPTION_FAILED]`

**Possible Causes:**

1. **Wrong encryption key**
   - Key was rotated or changed

2. **Data tampered**
   - Poly1305 MAC verification failed

3. **Corrupted data**
   - Base64 decoding failed
   - Data too short

**Debugging Steps:**

1. Check encryption key matches:

```bash
# Compare with backup/deployment records
echo $PII_ENCRYPTION_KEY
```

2. Check data format:

```sql
-- Check for properly formatted encrypted data
SELECT ip_encrypted FROM sessions 
WHERE ip_encrypted NOT LIKE 'enc:%';
-- Should return 0 rows (or legacy unencrypted data)
```

3. Check logs for specific error:

```bash
docker logs auth-accounts 2>&1 | grep "PII decryption failed"
```

---

## Database Issues

### Error: `Unsupported charset`

**Symptom:**

```
InvalidArgumentException: Unsupported charset 'utf8mb4'. Allowed: utf8, utf-8, latin1, ...
```

**Cause:** Charset not in allowlist in `PostgresConnector`.

**Solution:**

Use a supported charset:

```env
DB_CHARSET=utf8
# or
DB_CHARSET=utf-8
```

**Note:** PostgreSQL uses `utf8` internally (equivalent to MySQL's `utf8mb4`).

---

### Error: `Invalid timezone identifier`

**Symptom:**

```
InvalidArgumentException: Invalid timezone identifier: 'US/Eastern '
```

**Cause:** Timezone contains invalid characters or whitespace.

**Solution:**

1. Use valid timezone format:

```env
# ✅ Good
DB_TIMEZONE=UTC
DB_TIMEZONE=America/New_York
DB_TIMEZONE=EST

# ❌ Bad
DB_TIMEZONE=US/Eastern  # trailing space
DB_TIMEZONE=GMT+5       # invalid format
```

2. Check for whitespace:

```bash
# Check for hidden characters
echo -n "$DB_TIMEZONE" | xxd
```

---

### Error: `Invalid schema name`

**Symptom:**

```
InvalidArgumentException: Invalid schema name: 'public; DROP TABLE users;--'
```

**Cause:** SQL injection attempt or misconfigured schema.

**Solution:**

1. Verify schema configuration:

```env
DB_SCHEMA=public
# or for multiple schemas
DB_SCHEMA=public,app
```

2. Check for injection in config files

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
public function getUsers(): array { }

// ✅ After
/** @return array<int, User> */
public function getUsers(): array { }
```

---

## Performance Issues

### Symptom: Slow session validation

**Possible Causes:**

1. **Redis cache miss**
   - Every validation hits database

2. **Database connection pool exhausted**

3. **Network latency to Redis/DB**

**Debugging Steps:**

1. Check Redis hit rate:

```bash
redis-cli INFO stats | grep keyspace_hits
redis-cli INFO stats | grep keyspace_misses
```

2. Check connection pool:

```sql
SELECT count(*) FROM pg_stat_activity 
WHERE application_name = 'auth-accounts';
```

3. Check network latency:

```bash
# To Redis
redis-cli --latency -h $REDIS_HOST

# To PostgreSQL
pgbench -h $DB_HOST -U $DB_USER $DB_DATABASE
```

**Solutions:**

- Increase Redis cache TTL if appropriate
- Increase database pool size
- Co-locate service with Redis/PostgreSQL

---

### Symptom: High memory usage

**Possible Causes:**

1. **Swoole worker not recycling**
   - Workers accumulate memory over time

2. **Large result sets**
   - Queries returning too many rows

3. **Memory leaks in dependencies**

**Solutions:**

1. Configure worker recycling:

```php
// config/autoload/server.php
'max_request' => 100000,  // Recycle after 100k requests
```

2. Check for N+1 queries:

```php
// ❌ Bad - N+1 query
foreach ($users as $user) {
    $sessions = $user->sessions;  // Query per user
}

// ✅ Good - eager loading
$users = User::with('sessions')->get();
```

3. Monitor memory:

```bash
# Check Swoole worker memory
ps aux | grep hyperf | awk '{print $6/1024 " MB " $11}'
```

---

## Logging and Debugging

### Enable Debug Logging

1. Set log level in `.env`:

```env
APP_ENV=local
LOG_LEVEL=debug
```

2. Check logs:

```bash
# View recent errors
docker logs auth-accounts 2>&1 | grep ERROR

# View PII encryption issues
docker logs auth-accounts 2>&1 | grep "pii-encryption"

# View all logs (JSON format)
docker logs auth-accounts 2>&1 | jq .
```

### Log Format

Logs are JSON-formatted:

```json
{
  "level": "error",
  "message": "PII decryption failed: authentication failed",
  "context": {
    "component": "pii-encryption"
  },
  "timestamp": "2026-02-28T12:00:00Z"
}
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
// Create interface for service
interface AuthServiceInterface {
    public function login(string $username, string $password, string $ip, string $userAgent): ?array;
}

// Inject interface, implement in final class
```

---

### Error: `Database connection failed in tests`

**Symptom:**

```
PDOException: could not find driver
```

**Cause:** PostgreSQL driver not installed or test database not configured.

**Solution:**

1. Install PostgreSQL driver:

```bash
docker-php-ext-install pdo_pgsql
```

2. Configure test database:

```env
# .env.testing
DB_HOST=localhost
DB_DATABASE=ashchan_test
DB_USER=ashchan
DB_PASSWORD=ashchan
```

3. Run migrations:

```bash
php bin/hyperf.php migrate --env=testing
```

---

## Emergency Procedures

### Rotate Encryption Keys

**Warning:** This will invalidate all existing encrypted data. Plan accordingly.

1. Generate new key:

```bash
NEW_KEY=$(openssl rand -hex 32)
```

2. Update deployment configuration

3. Deploy with migration script to re-encrypt data:

```php
// Migration script (run during maintenance window)
$users = User::query()->get();
foreach ($users as $user) {
    // Decrypt with old key, encrypt with new key
    $user->update(['email' => $newEncryptionService->encrypt(
        $oldEncryptionService->decrypt($user->email)
    )]);
}
```

### Clear All Sessions

**Use Case:** Security incident requiring mass logout.

```bash
# Clear Redis session cache
redis-cli KEYS "session:*" | xargs redis-cli DEL

# Clear database sessions
psql -h $DB_HOST -U $DB_USER -d $DB_DATABASE -c "TRUNCATE sessions;"
```

### Emergency Ban

**Use Case:** Immediate ban without going through API.

```sql
-- Ban user immediately
UPDATE users 
SET banned = true, 
    ban_reason = 'Emergency ban - security incident',
    ban_expires_at = NULL
WHERE username = 'attacker';

-- Clear their sessions
DELETE FROM sessions 
WHERE user_id = (SELECT id FROM users WHERE username = 'attacker');

-- Ban IP (in Redis)
redis-cli SETEX "ban:ip:<ip_hash>" 86400 '{"reason":"Emergency ban","banned_at":<timestamp>}'
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
- Levels: DEBUG, INFO, WARNING, ERROR, CRITICAL

### Support Channels

- Development team: #auth-service-dev
- Operations team: #auth-service-ops
- Security incidents: #security-incidents

---

## Related Documentation

- [Architecture](ARCHITECTURE.md) - System architecture
- [API Reference](API.md) - API documentation
- [Security Model](SECURITY.md) - Security considerations
- [Type Hinting Guide](TYPE_HINTING_GUIDE.md) - PHPStan 10 compliance
