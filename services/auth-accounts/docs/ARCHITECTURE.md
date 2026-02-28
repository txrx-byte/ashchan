# Auth/Accounts Service Architecture

**Last Updated:** 2026-02-28  
**Service:** `auth-accounts`  
**Framework:** Hyperf 3.x (Swoole-based PHP 8.2+)

## Overview

The Auth/Accounts service provides identity management and authentication for the Ashchan imageboard platform. It is a stateless, horizontally-scalable microservice built on the Hyperf framework with Swoole coroutine support.

## System Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         Auth/Accounts Service                            │
│                         (Port 9502 HTTP, 8444 mTLS)                      │
│                                                                          │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │                        HTTP Server Layer                          │   │
│  │  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐   │   │
│  │  │ HealthController│  │ AuthController  │  │ Exception       │   │   │
│  │  │                 │  │                 │  │ Handler         │   │   │
│  │  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘   │   │
│  └───────────┼────────────────────┼────────────────────┼────────────┘   │
│              │                    │                    │                │
│  ┌───────────▼────────────────────▼────────────────────▼────────────┐   │
│  │                        Service Layer                              │   │
│  │  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐   │   │
│  │  │ AuthService     │  │ PiiEncryption   │  │ SiteConfig      │   │   │
│  │  │                 │  │ Service         │  │ Service         │   │   │
│  │  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘   │   │
│  └───────────┼────────────────────┼────────────────────┼────────────┘   │
│              │                    │                    │                │
│  ┌───────────▼────────────────────▼────────────────────▼────────────┐   │
│  │                        Model Layer (ActiveRecord)                 │   │
│  │  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────────┐  │   │
│  │  │ User     │  │ Session  │  │ Consent  │  │ DeletionRequest  │  │   │
│  │  └──────────┘  └──────────┘  └──────────┘  └──────────────────┘  │   │
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
              │PostgreSQL │  │    Redis    │  │ Logger  │
              │  Database │  │    Cache    │  │         │
              └───────────┘  └─────────────┘  └─────────┘
```

## Layer Architecture

### 1. HTTP Server Layer (Controllers)

Controllers handle HTTP request/response lifecycle:

| Controller | Responsibility | Routes |
|------------|---------------|--------|
| `HealthController` | Health checks for load balancers | `GET /health` |
| `AuthController` | Authentication, authorization, consent, data rights | `/api/v1/auth/*`, `/api/v1/consent` |

**Key Design Decisions:**
- Controllers are `final` classes (no inheritance)
- Dependencies injected via constructor
- Input validation performed before service calls
- Constant-time error messages to prevent user enumeration

### 2. Service Layer (Business Logic)

Services encapsulate business logic and coordinate between models:

| Service | Responsibility |
|---------|---------------|
| `AuthService` | User lifecycle, session management, ban enforcement, consent tracking |
| `PiiEncryptionService` | XChaCha20-Poly1305 encryption for PII at rest |
| `SiteConfigService` | Database-backed configuration with Redis caching |

**Key Design Decisions:**
- Services are `final` classes (no inheritance)
- No direct HTTP dependencies (framework-agnostic)
- Graceful degradation when Redis unavailable
- Memory-safe key handling (keys wiped after use)

### 3. Model Layer (Data Access)

Models use ActiveRecord pattern via Hyperf's database component:

| Model | Table | Purpose |
|-------|-------|---------|
| `User` | `users` | Staff user accounts with ban state |
| `Session` | `sessions` | Authenticated session records |
| `Consent` | `consents` | GDPR/COPPA/CCPA consent records |
| `DeletionRequest` | `deletion_requests` | Data rights request tracking |

**Key Design Decisions:**
- Password hashes excluded from serialization
- Timestamps disabled where not needed
- Explicit type casts for all columns
- Relationships defined with return type hints

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

### Authentication Flow

```
┌──────────┐     ┌─────────────┐     ┌─────────────┐     ┌──────────┐
│  Client  │────▶│ AuthController│───▶│ AuthService │────▶│   User   │
└──────────┘     └─────────────┘     └─────────────┘     └──────────┘
                      │                    │
                      │                    ▼
                      │              ┌─────────────┐
                      │              │    Redis    │
                      │              │  (session)  │
                      │              └─────────────┘
                      │                    │
                      │                    ▼
                      │              ┌─────────────┐
                      │              │  PostgreSQL │
                      │              │  (session)  │
                      │              └─────────────┘
                      │
                      ▼
                 ┌─────────────┐
                 │  Response   │
                 └─────────────┘
```

### Session Validation Flow

```
1. Client sends request with Bearer token
2. AuthController extracts token from Authorization header
3. AuthService.validateToken():
   a. Hash token with SHA-256
   b. Check Redis cache (O(1))
      - If found: verify user not banned, return user data
      - If not found: query database
   c. Database lookup by token hash
   d. Check user ban status
   e. Cache result in Redis with remaining TTL
4. Return user data or null
```

## Dependency Injection

The service uses Hyperf's DI container with annotation-based injection:

```php
// config/container.php
$container = new Container((new DefinitionSourceFactory())());
ApplicationContext::setContainer($container);
```

### Constructor Injection Pattern

```php
final class AuthController
{
    public function __construct(
        private AuthService $authService,
        private PiiEncryptionService $piiEncryption,
        private HttpResponse $response,
        SiteConfigService $config,
    ) {
        // Dependencies automatically resolved by container
    }
}
```

### Service Bindings

```php
// config/autoload/dependencies.php
return [
    'db.connector.pgsql' => PostgresConnector::class,
];
```

## Configuration Architecture

### Environment Variables (`.env`)

| Variable | Purpose | Default |
|----------|---------|---------|
| `SERVICE_NAME` | Application identifier | `hyperf` |
| `DB_HOST` | PostgreSQL host | `postgres` |
| `DB_PORT` | PostgreSQL port | `5432` |
| `DB_DATABASE` | Database name | `ashchan` |
| `DB_USER` | Database user | `ashchan` |
| `DB_PASSWORD` | Database password | `ashchan` |
| `REDIS_HOST` | Redis host | `redis` |
| `REDIS_PORT` | Redis port | `6379` |
| `PII_ENCRYPTION_KEY` | 32-byte hex encryption key | *(required)* |
| `IP_HMAC_KEY` | Secret key for IP hashing | *(required)* |

### Database-Backed Settings (`site_settings` table)

| Setting | Type | Default | Purpose |
|---------|------|---------|---------|
| `session_ttl` | int | `604800` | Session lifetime (7 days) |
| `max_username_length` | int | `64` | Maximum username length |
| `max_password_length` | int | `256` | Maximum password length |
| `max_email_length` | int | `254` | Maximum email length |
| `login_rate_limit` | int | `10` | Login attempts per window |
| `login_rate_window` | int | `300` | Rate limit window (seconds) |
| `max_ban_duration` | int | `31536000` | Maximum ban (1 year) |
| `min_ban_duration` | int | `60` | Minimum ban (1 minute) |

## Caching Strategy

### Redis Cache Layers

| Cache Key Pattern | TTL | Purpose |
|-------------------|-----|---------|
| `session:{tokenHash}` | session_ttl | Session user data |
| `ban:user:{userId}` | 30s | User ban status |
| `ban:ip:{ipHash}` | variable | IP ban status |
| `site_config:all` | 60s | All site settings |

### Cache Invalidation

- **Sessions:** Deleted on logout, automatically expire
- **Ban status:** Invalidated immediately on ban/unban
- **Site config:** Refreshed on TTL expiry or manual clear

## Error Handling

### Exception Flow

```
┌─────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Controller │────▶│ AppExceptionHandler│───▶│  Response (500) │
└─────────────┘     └──────────────────┘     └─────────────────┘
                           │
                           ▼
                    ┌─────────────┐
                    │ STDERR Log  │
                    │ (full trace)│
                    └─────────────┘
```

### Exception Handler Behavior

- All unhandled exceptions caught by `AppExceptionHandler`
- Full stack trace logged to STDERR
- Client receives generic `{ "error": "Internal server error" }`
- No internal details exposed to clients

## Security Architecture

### Defense in Depth

1. **Input Validation:** Controller-level length and format checks
2. **Parameter Binding:** PDO prepared statements with proper types
3. **Password Hashing:** Argon2id (memory-hard KDF)
4. **Session Tokens:** 256-bit random, SHA-256 hashed for storage
5. **PII Encryption:** XChaCha20-Poly1305 AEAD
6. **IP Hashing:** HMAC-SHA256 with server-side secret
7. **Rate Limiting:** Redis sorted-set sliding window
8. **SQL Injection Prevention:** Allowlist validation on SET parameters

### Key Hierarchy

```
PII_ENCRYPTION_KEY (env var, 32-byte hex)
         │
         ▼
    BLAKE2b with salt
         │
         ▼
   KEK (Key Encryption Key)
         │
         ▼
   DEK (Data Encryption Key) + random nonce
         │
         ▼
   XChaCha20-Poly1305 encrypted PII
```

## Performance Characteristics

| Operation | Complexity | Notes |
|-----------|------------|-------|
| Session validation (cache hit) | O(1) | Redis lookup |
| Session validation (cache miss) | O(1) | DB lookup by indexed token |
| Ban status check (cache hit) | O(1) | Redis lookup with 30s TTL |
| Ban status check (cache miss) | O(1) | DB lookup by primary key |
| Login rate limit check | O(1) | Redis sorted-set operations |
| Bulk session deletion | O(n) | Single DELETE query |

## Scaling Considerations

### Horizontal Scaling

- Service is stateless (all state in PostgreSQL/Redis)
- Multiple instances can run behind load balancer
- Session tokens work across instances (shared Redis)
- Ban status cache synchronized via Redis

### Connection Pooling

```php
// config/autoload/databases.php
'pool' => [
    'min_connections' => 1,
    'max_connections' => 10,
    'connect_timeout' => 10.0,
    'wait_timeout' => 3.0,
    'heartbeat' => -1,
    'max_idle_time' => 60.0,
],
```

### Swoole Worker Configuration

```php
// config/autoload/server.php
'settings' => [
    'worker_num' => 2,           // Workers per container
    'max_request' => 100000,     // Recycle after N requests
    'max_coroutine' => 100000,   // Max concurrent coroutines
],
```

## Testing Architecture

### Unit Test Structure

```
tests/
├── bootstrap.php          # Test bootstrap
├── TestBootstrap.php      # Test setup helper
└── Unit/
    ├── Service/
    │   ├── AuthServiceTest.php
    │   ├── PiiEncryptionServiceTest.php
    │   └── SiteConfigServiceTest.php
    └── Model/
        ├── UserTest.php
        └── SessionTest.php
```

### Test Patterns

- Mock Redis for service tests
- Use in-memory SQLite for model tests (optional)
- DG\BypassFinals for testing final classes

## Monitoring and Observability

### Health Endpoints

- `GET /health` - Basic liveness check (no dependencies)
- Future: `GET /health/ready` - Readiness check (DB + Redis)

### Logging

- Format: JSON to STDERR
- Levels: All levels enabled in production
- Categories: `pii-encryption`, `site-config`, default

### Metrics (Future)

- Prometheus metrics via Hyperf/Prometheus
- Key metrics: login attempts, session validations, ban checks

## Related Documentation

- [API Reference](API.md) - Complete API documentation
- [Security Model](SECURITY.md) - Security considerations
- [Type Hinting Guide](TYPE_HINTING_GUIDE.md) - PHPStan 10 compliance
- [Troubleshooting](TROUBLESHOOTING.md) - Common issues and solutions
