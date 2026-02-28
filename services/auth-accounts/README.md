# Auth/Accounts Service

**Service Name:** `auth-accounts`  
**Port:** 9502 (HTTP), 8444 (mTLS)  
**Framework:** Hyperf 3.x (Swoole-based PHP 8.2+ framework)

## Purpose

The Auth/Accounts service provides identity management and authentication for the Ashchan imageboard platform. It handles:

- **Staff user authentication** - Registration, login, session management for admin/moderator/janitor accounts
- **Session management** - Secure token-based sessions with Redis caching
- **Ban enforcement** - User and IP-based bans with automatic expiry handling
- **Consent tracking** - GDPR/COPPA/CCPA compliance for privacy policies and age verification
- **Data rights** - User data export and deletion requests (Right to be Forgotten)
- **PII protection** - Encryption of personally identifiable information at rest

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                     Auth/Accounts Service                        │
│                                                                  │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐  │
│  │  Controllers │  │   Services   │  │        Models        │  │
│  │              │  │              │  │                      │  │
│  │ - Auth       │  │ - Auth       │  │ - User               │  │
│  │ - Health     │  │ - PiiEncrypt │  │ - Session            │  │
│  └──────┬───────┘  │ - SiteConfig │  │ - Consent            │  │
│         │         └──────┬───────┘  │ - DeletionRequest    │  │
│         │                │          └──────────────────────┘  │
│         └────────────────┼────────────────────────────────────┘
│                          │
│         ┌────────────────┼────────────────┐
│         │                │                │
│    ┌────▼────┐     ┌─────▼─────┐   ┌─────▼─────┐
│    │PostgreSQL│     │   Redis   │   │  Logger   │
│    │ Database │     │  Cache    │   │           │
│    └──────────┘     └───────────┘   └───────────┘
└─────────────────────────────────────────────────────────────────┘
```

## Directory Structure

```
services/auth-accounts/
├── app/
│   ├── Controller/          # HTTP request handlers
│   │   ├── AuthController.php
│   │   └── HealthController.php
│   ├── Database/            # Custom database connectivity
│   │   ├── PostgresConnection.php
│   │   └── PostgresConnector.php
│   ├── Exception/Handler/   # Global exception handling
│   │   └── AppExceptionHandler.php
│   ├── Model/               # Database models (ActiveRecord pattern)
│   │   ├── User.php
│   │   ├── Session.php
│   │   ├── Consent.php
│   │   └── DeletionRequest.php
│   └── Service/             # Business logic layer
│       ├── AuthService.php
│       ├── PiiEncryptionService.php
│       └── SiteConfigService.php
├── config/
│   ├── autoload/            # Auto-loaded configuration files
│   │   ├── databases.php
│   │   ├── redis.php
│   │   ├── server.php
│   │   └── ...
│   ├── config.php           # Main configuration
│   ├── container.php        # Dependency injection container
│   └── routes.php           # Route definitions
├── tests/
│   └── Unit/                # Unit tests
├── .env.example             # Environment variable template
├── composer.json            # PHP dependencies
├── phpstan.neon             # PHPStan static analysis config
└── README.md                # This file
```

## Installation

### Prerequisites

- PHP 8.2+ with Swoole extension
- PostgreSQL 14+
- Redis 6+
- Composer

### Setup

```bash
# Install dependencies
composer install

# Copy environment configuration
cp .env.example .env

# Edit .env with your settings
# Required variables:
#   - DB_HOST, DB_DATABASE, DB_USER, DB_PASSWORD
#   - REDIS_HOST, REDIS_PORT
#   - PII_ENCRYPTION_KEY (32-byte hex key for encryption)
#   - IP_HMAC_KEY (secret key for IP hashing)

# Run database migrations (handled by platform)
# Start the service
php bin/hyperf.php start
```

## Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_NAME` | Application name | `hyperf` |
| `APP_ENV` | Environment (production/local) | `production` |
| `SERVICE_NAME` | Service identifier | `auth` |
| `DB_HOST` | PostgreSQL host | `postgres` |
| `DB_PORT` | PostgreSQL port | `5432` |
| `DB_DATABASE` | Database name | `ashchan` |
| `DB_USER` | Database user | `ashchan` |
| `DB_PASSWORD` | Database password | `ashchan` |
| `REDIS_HOST` | Redis host | `redis` |
| `REDIS_PORT` | Redis port | `6379` |
| `PII_ENCRYPTION_KEY` | 32-byte hex key for PII encryption | *(required)* |
| `IP_HMAC_KEY` | Secret key for IP address hashing | *(required)* |
| `MTLS_ENABLED` | Enable mTLS for service mesh | `false` |
| `MTLS_PORT` | mTLS listener port | `8443` |

### Site Settings (Database-Backed)

The following settings are stored in the `site_settings` table and can be modified via admin panel:

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `session_ttl` | int | `604800` | Session lifetime in seconds (7 days) |
| `max_username_length` | int | `64` | Maximum username length |
| `max_password_length` | int | `256` | Maximum password length |
| `max_email_length` | int | `254` | Maximum email length |
| `login_rate_limit` | int | `10` | Max login attempts per window |
| `login_rate_window` | int | `300` | Rate limit window in seconds |
| `max_ban_duration` | int | `31536000` | Maximum ban duration (1 year) |
| `min_ban_duration` | int | `60` | Minimum ban duration (1 minute) |

## API Endpoints

See [API.md](docs/API.md) for complete API documentation.

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/v1/auth/login` | Authenticate and obtain session token |
| `POST` | `/api/v1/auth/logout` | Invalidate session token |
| `GET` | `/api/v1/auth/validate` | Validate session token |
| `POST` | `/api/v1/auth/register` | Create new staff user (admin only) |
| `POST` | `/api/v1/auth/ban` | Ban user and/or IP |
| `POST` | `/api/v1/auth/unban` | Remove user ban |
| `POST` | `/api/v1/auth/data-request` | Request data export/deletion |

### Consent

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/v1/consent` | Record consent decision |

### Health

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/health` | Health check for load balancers |

## Security Model

### Password Hashing

- Algorithm: **Argon2id** (memory-hard key derivation function)
- Parameters: Default PHP 8.2 Argon2id settings
- Storage: `password_hash` column (excluded from serialization)

### Session Tokens

- Generation: 256-bit cryptographically secure random bytes
- Storage: SHA-256 hash stored in database (raw token never persisted)
- Redis Cache: SHA-256 hash used as key (prevents token exposure if Redis compromised)
- Validation: O(1) Redis lookup with database fallback

### PII Encryption

- Algorithm: **XChaCha20-Poly1305** (IETF AEAD cipher)
- Key Derivation: BLAKE2b with application-specific salt
- Wire Format: `enc:` + base64(nonce || ciphertext || tag)
- Memory Safety: Keys wiped from memory on destruction

### IP Address Handling

- Hashing: HMAC-SHA256 with server-side secret key
- Purpose: Prevents rainbow table attacks on IPv4 address space
- Encryption: Full IP encryption for admin recovery (XChaCha20-Poly1305)

### Rate Limiting

- Mechanism: Redis sorted-set sliding window
- Scope: Per-IP login attempts
- Default: 10 attempts per 5 minutes
- Lua Script: Atomic operations prevent race conditions

## Database Schema

### Users Table

```sql
CREATE TABLE users (
    id              SERIAL PRIMARY KEY,
    username        VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    email           VARCHAR(254),
    role            VARCHAR(50) NOT NULL DEFAULT 'user',
    banned          BOOLEAN NOT NULL DEFAULT FALSE,
    ban_reason      VARCHAR(500),
    ban_expires_at  TIMESTAMP,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);
```

### Sessions Table

```sql
CREATE TABLE sessions (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id),
    token       CHAR(64) NOT NULL,  -- SHA-256 hex
    ip_address  TEXT NOT NULL,       -- Encrypted PII
    user_agent  VARCHAR(512),
    expires_at  TIMESTAMP NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
```

### Consents Table

```sql
CREATE TABLE consents (
    id              SERIAL PRIMARY KEY,
    ip_hash         CHAR(64) NOT NULL,   -- HMAC-SHA256
    ip_encrypted    TEXT NOT NULL,        -- Encrypted PII
    user_id         INTEGER REFERENCES users(id),
    consent_type    VARCHAR(50) NOT NULL,
    policy_version  VARCHAR(20) NOT NULL,
    consented       BOOLEAN NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);
```

### Deletion Requests Table

```sql
CREATE TABLE deletion_requests (
    id            SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES users(id),
    status        VARCHAR(20) NOT NULL,  -- pending, processing, completed, denied
    request_type  VARCHAR(50) NOT NULL,  -- data_export, data_deletion
    requested_at  TIMESTAMP NOT NULL,
    completed_at  TIMESTAMP
);
```

## Testing

```bash
# Run unit tests
php vendor/bin/phpunit --configuration phpunit.xml

# Run with coverage (requires Xdebug)
php vendor/bin/phpunit --configuration phpunit.xml --coverage-html coverage/

# Run specific test class
php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/Service/AuthServiceTest.php
```

## Static Analysis

```bash
# Run PHPStan level 10 analysis
php vendor/bin/phpstan analyse --configuration phpstan.neon

# Run with baseline generation
php vendor/bin/phpstan analyse --configuration phpstan.neon --generate-baseline
```

## User Roles

| Role | Permissions |
|------|-------------|
| `admin` | Full system access, user management, ban management |
| `manager` | Moderation management, limited admin functions |
| `mod` | Ban/unban users, content moderation |
| `janitor` | Board cleanup, basic moderation |
| `user` | Standard authenticated user |

## Compliance Features

### GDPR (General Data Protection Regulation)

- **Right to Access**: Data export via `/api/v1/auth/data-request`
- **Right to Erasure**: Data deletion via `/api/v1/auth/data-request`
- **Consent Tracking**: Append-only consent records with policy versioning
- **Data Minimization**: PII encrypted at rest, hashed for lookups

### COPPA (Children's Online Privacy Protection Act)

- Age verification consent tracking
- Parental consent flags (via consent records)

### CCPA (California Consumer Privacy Act)

- Data deletion requests
- Data export capabilities
- Consent opt-out tracking

## Performance Characteristics

| Operation | Complexity | Notes |
|-----------|------------|-------|
| Session validation (cache hit) | O(1) | Redis lookup |
| Session validation (cache miss) | O(1) | Database lookup by indexed token |
| Ban status check (cache hit) | O(1) | Redis lookup with 30s TTL |
| Ban status check (cache miss) | O(1) | Database lookup by primary key |
| Login rate limit check | O(1) | Redis sorted-set operations |
| Bulk session deletion | O(n) | Single DELETE query (no N+1) |

## Troubleshooting

For comprehensive troubleshooting guidance, see [Troubleshooting Guide](docs/TROUBLESHOOTING.md).

### Common Issues

**"IP_HMAC_KEY or PII_ENCRYPTION_KEY must be configured"**

Ensure both environment variables are set in `.env`:
```
IP_HMAC_KEY=your-secret-hmac-key
PII_ENCRYPTION_KEY=$(openssl rand -hex 32)
```

**"Redis connection failed"**

The service implements graceful degradation - Redis failures are logged but don't cause request failures. Database fallback is used automatically.

**"Session validation always fails"**

Check that:
1. Redis is running and accessible
2. The `PII_ENCRYPTION_KEY` hasn't changed (affects session data)
3. Database connection is working

### Logs

Logs are written to STDERR in JSON format. Check your container logs or Swoole log directory:

```bash
# View recent errors
docker logs auth-accounts 2>&1 | grep ERROR

# View PII encryption issues
docker logs auth-accounts 2>&1 | grep "pii-encryption"
```

## Related Documentation

### Service Documentation

- [Architecture](docs/ARCHITECTURE.md) - Detailed system architecture and data flow
- [API Reference](docs/API.md) - Complete API endpoint documentation
- [Security Model](docs/SECURITY.md) - Security architecture and threat model
- [Type Hinting Guide](docs/TYPE_HINTING_GUIDE.md) - PHPStan 10 compliance guide
- [Troubleshooting](docs/TROUBLESHOOTING.md) - Common issues and solutions

### Project Documentation

- [Main Project README](../../README.md) - Ashchan platform overview
- [Japanese README](../../README.ja.md) - 日本語ドキュメント
- [Chinese README](../../README.zh.md) - 中文文档

## License

Apache License 2.0 - See [LICENSE](../../LICENSE) for details.
