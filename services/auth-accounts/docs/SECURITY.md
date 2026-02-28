# Auth/Accounts Service Security Model

**Last Updated:** 2026-02-28  
**Classification:** Internal Documentation

## Overview

This document describes the security architecture, threat model, and defensive measures implemented in the Auth/Accounts service.

---

## Threat Model

### Assets Protected

| Asset | Sensitivity | Protection Method |
|-------|-------------|-------------------|
| User passwords | Critical | Argon2id hashing |
| Session tokens | High | SHA-256 hashing, secure storage |
| IP addresses | Medium | HMAC hashing + encryption |
| User emails | Medium | PII encryption at rest |
| Consent records | Medium | Append-only, tamper-evident |

### Threat Actors

1. **External Attackers:** No direct database access, must go through API
2. **Malicious Users:** Authenticated users attempting privilege escalation
3. **Compromised Infrastructure:** Redis/Database access by attackers
4. **Insider Threats:** Administrators with elevated access

---

## Authentication Security

### Password Hashing

**Algorithm:** Argon2id (memory-hard key derivation function)

```php
password_hash($password, PASSWORD_ARGON2ID)
```

**Why Argon2id:**

- Winner of the Password Hashing Competition (2015)
- Resistant to GPU/ASIC attacks (memory-hard)
- Combines Argon2i (side-channel resistance) and Argon2d (GPU resistance)
- PHP 8.2 default parameters provide strong security

**Parameters (PHP 8.2 defaults):**

| Parameter | Value | Purpose |
|-----------|-------|---------|
| Memory | 64 MB | Memory-hard computation |
| Iterations | 4 | Time cost |
| Parallelism | 4 | Thread parallelism |

### Timing Attack Prevention

**Problem:** Attackers can measure response time to determine if a username exists.

**Solution:** Constant-time credential verification

```php
if (!$user instanceof User) {
    // Perform dummy password_verify with fixed-cost hash
    // This ensures "user not found" takes same time as "wrong password"
    password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$dXNlckBhc2hjaGFu$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
    return null;
}

if (!password_verify($password, $user->password_hash)) {
    return null;
}
```

**Why This Works:**

- `password_verify()` is constant-time by design
- Dummy hash has same algorithm/cost as real hashes
- Response time identical for both cases

---

## Session Security

### Token Generation

**Method:** Cryptographically secure random bytes

```php
$token = bin2hex(random_bytes(32));  // 256-bit token
```

**Properties:**

- 256 bits of entropy (128-bit security margin)
- `random_bytes()` uses CSPRNG (OS-level)
- Hex encoding for safe transport

### Token Storage

**Security Principle:** Raw token never persisted

```
Client receives:     raw_token (256-bit hex)
Database stores:     SHA-256(raw_token) (64-char hex)
Redis stores:        session:SHA-256(raw_token) → user_data
```

**Why Hash Tokens:**

1. **Database Compromise:** Attacker cannot use stolen hashes as tokens
2. **Redis Compromise:** Keys use hash, not raw token
3. **Defense in Depth:** Additional layer if storage exposed

### Session Validation Flow

```
1. Client sends: Authorization: Bearer <raw_token>
2. Server computes: token_hash = SHA-256(raw_token)
3. Check Redis: GET session:<token_hash>
   - Hit: Return cached user data
   - Miss: Query database WHERE token = <token_hash>
4. Always verify: Check user ban status (not just cached data)
```

### Session Expiry

| Property | Value | Notes |
|----------|-------|-------|
| Default TTL | 604800s (7 days) | Configurable via `session_ttl` |
| Redis TTL | Matches DB expiry | Auto-cleanup on expiry |
| Validation | Check on every request | Not just at login |

---

## PII Protection

### Encryption Algorithm

**Cipher:** XChaCha20-Poly1305 (IETF AEAD)

**Why XChaCha20-Poly1305:**

- 256-bit key, 192-bit nonce (larger than ChaCha20's 96-bit)
- Authenticated encryption (AEAD) - detects tampering
- No known practical attacks
- Faster than AES on systems without hardware acceleration
- Recommended by cryptography experts for new designs

### Key Derivation

```
PII_ENCRYPTION_KEY (env var, 32-byte hex)
         │
         ▼
    BLAKE2b(key, salt="ashchan-pii-encryption-v1")
         │
         ▼
   KEK (32-byte derived key)
```

**Why BLAKE2b:**

- Faster than SHA-256
- Secure key derivation function
- Salt prevents cross-context key reuse

### Wire Format

```
enc:<base64(nonce || ciphertext || tag)>

Where:
  - nonce:      24 bytes (XCHACHA20_NPUB)
  - ciphertext: len(plaintext) bytes
  - tag:        16 bytes (Poly1305 MAC)
```

**Example:**

```
enc:AAAA...AAAA  (base64 of 24-byte nonce + ciphertext + 16-byte tag)
```

### Memory Safety

**Problem:** Encryption keys lingering in memory can be extracted.

**Solution:** Explicit memory wiping

```php
public function __destruct()
{
    if ($this->encryptionKey !== '') {
        // Overwrite with zeros before deallocation
        $length = strlen($this->encryptionKey);
        $this->encryptionKey = str_repeat("\0", $length);
    }
}
```

**Note:** `sodium_memzero()` is used where possible, but `str_repeat()` fallback ensures compatibility with typed properties.

---

## IP Address Handling

### Threat: IP Address Rainbow Tables

**Problem:** IPv4 address space is only 2^32 addresses. Plain SHA-256 hashing is vulnerable to precomputed rainbow tables.

**Solution:** HMAC-SHA256 with server-side secret

```php
$ipHash = hash_hmac('sha256', $ip, $this->ipHmacKey);
```

**Why HMAC:**

- Requires secret key to compute hashes
- Precomputed tables useless without key
- Key stored separately from data

### Dual Storage Strategy

| Purpose | Method | Column |
|---------|--------|--------|
| Lookups | HMAC-SHA256 | `ip_hash` |
| Recovery | XChaCha20-Poly1305 | `ip_encrypted` |

**Rationale:**

- HMAC for fast, deterministic lookups (ban checks, consent)
- Encryption for admin recovery (debugging, legal requests)

---

## Rate Limiting

### Login Brute-Force Protection

**Mechanism:** Redis sorted-set sliding window

**Algorithm:**

```
Key: login_attempts:<SHA-256(ip)>
Data structure: Sorted set (score = microtime, member = unique_id)

On each login attempt:
1. Remove entries older than window (ZREMRANGEBYSCORE)
2. Count remaining entries (ZCARD)
3. If count >= limit: reject
4. Add new entry (ZADD)
5. Set expiry (EXPIRE)
```

**Lua Script (Atomic):**

```lua
local key = KEYS[1]
local now = tonumber(ARGV[1])
local window_start = tonumber(ARGV[2])
local max_reqs = tonumber(ARGV[3])
local member = ARGV[4]
local window = tonumber(ARGV[5])

redis.call('ZREMRANGEBYSCORE', key, '-inf', window_start)
local count = redis.call('ZCARD', key)
if count >= max_reqs then
    return 1  -- Rate limited
end
redis.call('ZADD', key, now, member)
redis.call('EXPIRE', key, window)
return 0  -- OK
```

**Why Lua:**

- Atomic execution (no race conditions)
- Single round-trip to Redis
- Prevents concurrent request bypass

### Default Limits

| Parameter | Value | Description |
|-----------|-------|-------------|
| `login_rate_limit` | 10 | Max attempts per window |
| `login_rate_window` | 300s | Window size (5 minutes) |

### Fail-Open Behavior

If Redis is unavailable, rate limiting is bypassed:

```php
try {
    // Rate limit check
} catch (\Throwable) {
    // Redis down - allow request (fail-open)
    return false;
}
```

**Rationale:** Availability > Security for rate limiting (authentication still works)

---

## SQL Injection Prevention

### Parameter Binding

**Problem:** String interpolation in SQL queries allows injection.

**Solution:** PDO prepared statements with proper type binding

```php
public function bindValues(PDOStatement $statement, array $bindings): void
{
    foreach ($bindings as $key => $value) {
        $type = match (true) {
            is_int($value) => \PDO::PARAM_INT,
            is_bool($value) => \PDO::PARAM_BOOL,
            is_null($value) => \PDO::PARAM_NULL,
            default => \PDO::PARAM_STR,
        };
        $statement->bindValue(/* ... */);
    }
}
```

**Why Type-Specific Binding:**

- PostgreSQL is strictly typed
- Boolean as PARAM_STR causes query failures
- Integer injection prevented by type enforcement

### SET Statement Validation

**Problem:** `SET NAMES`, `SET timezone`, etc. cannot use parameter binding.

**Solution:** Allowlist validation

```php
// Charset allowlist
private const ALLOWED_CHARSETS = ['utf8', 'utf-8', 'latin1', /* ... */];

// Timezone regex
private const TIMEZONE_PATTERN = '/^[A-Za-z_\/\+\-0-9]{1,64}$/';

// Identifier regex (schema names, app names)
private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_\-\.]{0,62}$/';
```

**Validation Flow:**

```
Input → Validate against allowlist/regex → Reject or SET
                                    ↓
                            Throw InvalidArgumentException
```

---

## Information Leakage Prevention

### Generic Error Messages

**Problem:** Specific error messages reveal system internals.

**Solution:** Generic client responses, detailed server logs

```php
// Client sees:
{ "error": "Invalid credentials" }

// Server logs:
[AppExceptionHandler] RuntimeException: Account is banned in /path/to/file.php:line
```

### User Enumeration Prevention

| Endpoint | Attack | Mitigation |
|----------|--------|------------|
| Login | Determine if username exists | Constant-time response, generic error |
| Register | Determine if username taken | Return "Username already taken" (acceptable - public info) |
| Ban | Learn ban reason | Return generic "Invalid credentials" |

### Exception Handler

```php
public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
{
    // Log full details to STDERR
    fwrite(STDERR, sprintf(
        "[AppExceptionHandler] %s: %s in %s:%d\n%s",
        get_class($throwable),
        $throwable->getMessage(),
        $throwable->getFile(),
        $throwable->getLine(),
        $throwable->getTraceAsString()
    ));

    // Client gets generic error
    return $response->withStatus(500)->withBody(new SwooleStream(
        json_encode(['error' => 'Internal server error'])
    ));
}
```

---

## Access Control

### Role-Based Permissions

| Role | Login | Register | Ban/Unban | Data Export |
|------|-------|----------|-----------|-------------|
| `admin` | ✓ | ✓ | ✓ | ✓ (own data) |
| `manager` | ✓ | ✗ | ✓ | ✓ (own data) |
| `mod` | ✓ | ✗ | ✓ | ✓ (own data) |
| `janitor` | ✓ | ✗ | ✗ | ✓ (own data) |
| `user` | ✓ | ✗ | ✗ | ✓ (own data) |

### Authorization Checks

```php
// Registration requires admin
$caller = $this->authService->validateToken($token);
if ($caller === null || ($caller['role'] ?? '') !== 'admin') {
    return $this->response->json(['error' => 'Admin only'])->withStatus(403);
}

// Ban requires staff role
if ($caller === null || !in_array($caller['role'] ?? '', self::BAN_ROLES, true)) {
    return $this->response->json(['error' => 'Insufficient privileges'])->withStatus(403);
}
```

---

## Compliance Features

### GDPR (General Data Protection Regulation)

| Requirement | Implementation |
|-------------|----------------|
| Right to Access | `/api/v1/auth/data-request` with `type: data_export` |
| Right to Erasure | `/api/v1/auth/data-request` with `type: data_deletion` |
| Consent Tracking | Append-only `consents` table with policy versioning |
| Data Minimization | PII encrypted at rest, hashed for lookups |
| Purpose Limitation | Consent types track specific purposes |

### COPPA (Children's Online Privacy Protection Act)

| Requirement | Implementation |
|-------------|----------------|
| Age Verification | `age_verification` consent type |
| Parental Consent | Flag via consent records |
| Data Deletion | Same as GDPR right to erasure |

### CCPA (California Consumer Privacy Act)

| Requirement | Implementation |
|-------------|----------------|
| Right to Know | Data export via `/api/v1/auth/data-request` |
| Right to Delete | Data deletion via `/api/v1/auth/data-request` |
| Opt-Out | Track via consent records |

---

## Security Checklist

### Deployment

- [ ] `PII_ENCRYPTION_KEY` set to random 32-byte hex value
- [ ] `IP_HMAC_KEY` set to random secret string
- [ ] Database credentials rotated from defaults
- [ ] Redis authentication enabled (if exposed)
- [ ] mTLS enabled for service-to-service communication
- [ ] Firewall rules restrict database/Redis access

### Operations

- [ ] Logs reviewed for security events
- [ ] Failed login attempts monitored
- [ ] Ban actions audited
- [ ] Data requests tracked
- [ ] Encryption key rotation planned

### Development

- [ ] PHPStan level 10 passing
- [ ] No hardcoded secrets in code
- [ ] Input validation on all user input
- [ ] Prepared statements for all queries
- [ ] Security tests in CI/CD

---

## Related Documentation

- [Architecture](ARCHITECTURE.md) - System architecture
- [API Reference](API.md) - API documentation
- [Type Hinting Guide](TYPE_HINTING_GUIDE.md) - PHPStan 10 compliance
- [Troubleshooting](TROUBLESHOOTING.md) - Common issues
