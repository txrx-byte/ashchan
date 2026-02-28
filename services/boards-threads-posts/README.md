# Boards/Threads/Posts Service

**Service:** `boards-threads-posts`  
**Port:** 9503 (HTTP), 8445 (mTLS)  
**Framework:** Hyperf 3.x (Swoole-based PHP 8.2+)

## Purpose

The Boards/Threads/Posts service is the canonical data store for all imageboard content including:

- **Boards** - Forum board definitions and settings
- **Threads** - Discussion containers with bump tracking
- **Posts** - Individual posts (OP and replies)
- **Liveposting** - Real-time collaborative post editing

The service provides both a native REST API and a 4chan-compatible API layer for client compatibility.

## Features

- **Native REST API** - Full CRUD operations for boards, threads, and posts
- **4chan-Compatible API** - Read-only endpoints matching 4chan API specification
- **Liveposting** - WebSocket-backed real-time post editing
- **Three-Tier Caching** - Varnish (L1), Redis (L2), In-Memory (L3)
- **PII Encryption** - XChaCha20-Poly1305 encryption for IP addresses at rest
- **Automated Retention** - Scheduled PII deletion per data inventory policy
- **Event Publishing** - CloudEvents for cross-service communication
- **PHPStan 10** - Maximum static analysis compliance

## Quick Start

### Prerequisites

- PHP 8.2+ with Swoole extension
- PostgreSQL 14+
- Redis 6+
- Composer

### Installation

```bash
# Install dependencies
composer install

# Copy environment configuration
cp .env.example .env

# Edit .env with your configuration
# Required: DB_*, REDIS_*, PII_ENCRYPTION_KEY, IP_HASH_SALT

# Run database migrations
php bin/hyperf.php migrate

# Start the service
php bin/hyperf.php start
```

### Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `SERVICE_NAME` | No | `hyperf` | Application identifier |
| `APP_ENV` | No | `production` | Environment (local/production) |
| `DB_HOST` | Yes | `postgres` | PostgreSQL host |
| `DB_PORT` | Yes | `5432` | PostgreSQL port |
| `DB_DATABASE` | Yes | `ashchan` | Database name |
| `DB_USER` | Yes | `ashchan` | Database user |
| `DB_PASSWORD` | Yes | `ashchan` | Database password |
| `REDIS_HOST` | Yes | `redis` | Redis host |
| `REDIS_PORT` | Yes | `6379` | Redis port |
| `PII_ENCRYPTION_KEY` | Yes | - | 32-byte hex encryption key |
| `IP_HASH_SALT` | Yes | - | Secret salt for IP hashing |

### Generate Encryption Keys

```bash
# Generate PII encryption key (32-byte hex)
PII_KEY=$(openssl rand -hex 32)

# Generate IP hash salt
HMAC_KEY=$(openssl rand -base64 32)

# Add to .env
echo "PII_ENCRYPTION_KEY=$PII_KEY" >> .env
echo "IP_HASH_SALT=$HMAC_KEY" >> .env
```

## API Endpoints

### Native REST API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/boards` | List all boards |
| `GET` | `/api/v1/boards/{slug}` | Get single board |
| `GET` | `/api/v1/blotter` | Get site announcements |
| `GET` | `/api/v1/boards/{slug}/threads` | Get thread index |
| `GET` | `/api/v1/boards/{slug}/threads/{id}` | Get full thread |
| `GET` | `/api/v1/boards/{slug}/catalog` | Get board catalog |
| `GET` | `/api/v1/boards/{slug}/archive` | Get archived threads |
| `POST` | `/api/v1/boards/{slug}/threads` | Create new thread |
| `POST` | `/api/v1/boards/{slug}/threads/{id}/posts` | Reply to thread |
| `POST` | `/api/v1/posts/delete` | Delete own posts |
| `POST` | `/api/v1/posts/lookup` | Bulk post lookup (staff) |

### 4chan-Compatible API

| Endpoint | Description |
|----------|-------------|
| `GET /api/4chan/boards.json` | Board list |
| `GET /api/4chan/{board}/threads.json` | Thread list |
| `GET /api/4chan/{board}/catalog.json` | Full catalog |
| `GET /api/4chan/{board}/{page}.json` | Index page |
| `GET /api/4chan/{board}/thread/{no}.json` | Full thread |
| `GET /api/4chan/{board}/archive.json` | Archive list |

### Liveposting API (mTLS)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/v1/boards/{slug}/threads/{id}/open-post` | Allocate open post |
| `POST` | `/api/v1/posts/{id}/close` | Close (finalize) post |
| `PUT` | `/api/v1/posts/{id}/body` | Update post body |
| `POST` | `/api/v1/posts/{id}/reclaim` | Reclaim disconnected post |
| `POST` | `/api/v1/posts/close-expired` | Close expired posts |

See [API.md](docs/API.md) for complete API documentation.

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                   Boards/Threads/Posts Service                   │
│                                                                  │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐              │
│  │   Board     │  │   Thread    │  │  Livepost   │              │
│  │ Controller  │  │ Controller  │  │ Controller  │              │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘              │
│         │                │                │                      │
│  ┌──────▼────────────────▼────────────────▼──────┐              │
│  │              BoardService                      │              │
│  │  - Board/Thread/Post CRUD                      │              │
│  │  - Caching (Redis)                             │              │
│  │  - Event Publishing                            │              │
│  └──────────────────────┬─────────────────────────┘              │
│                         │                                        │
│  ┌──────────────────────▼─────────────────────────┐              │
│  │              Model Layer                        │              │
│  │  Board  Thread  Post  OpenPostBody  Blotter    │              │
│  └──────────────────────┬─────────────────────────┘              │
│                         │                                        │
│         ┌───────────────┼───────────────┐                        │
│         │               │               │                        │
│   ┌─────▼─────┐  ┌──────▼──────┐  ┌────▼────┐                    │
│   │PostgreSQL │  │    Redis    │  │  Event  │                    │
│   │ Database  │  │    Cache    │  │   Bus   │                    │
│   └───────────┘  └─────────────┘  └─────────┘                    │
└─────────────────────────────────────────────────────────────────┘
```

See [ARCHITECTURE.md](docs/ARCHITECTURE.md) for detailed architecture documentation.

## Directory Structure

```
services/boards-threads-posts/
├── app/
│   ├── Controller/
│   │   ├── BoardController.php
│   │   ├── ThreadController.php
│   │   ├── LivepostController.php
│   │   ├── FourChanApiController.php
│   │   └── HealthController.php
│   ├── Service/
│   │   ├── BoardService.php
│   │   ├── FourChanApiService.php
│   │   ├── ContentFormatter.php
│   │   ├── IpRetentionService.php
│   │   ├── PiiEncryptionService.php
│   │   └── SiteConfigService.php
│   ├── Model/
│   │   ├── Board.php
│   │   ├── Thread.php
│   │   ├── Post.php
│   │   ├── OpenPostBody.php
│   │   └── Blotter.php
│   ├── Database/
│   │   ├── PostgresConnection.php
│   │   └── PostgresConnector.php
│   └── Exception/
│       └── AppExceptionHandler.php
├── config/
│   ├── config.php
│   ├── container.php
│   └── routes.php
├── docs/
│   ├── ARCHITECTURE.md
│   ├── API.md
│   ├── SECURITY.md
│   ├── TYPE_HINTING_GUIDE.md
│   └── TROUBLESHOOTING.md
├── tests/
├── .env.example
├── composer.json
├── phpstan.neon
└── README.md
```

## Caching Strategy

| Cache Key | TTL | Purpose |
|-----------|-----|---------|
| `boards:all` | 300s | All active boards |
| `board:{slug}` | 300s | Single board |
| `blotter:recent` | 120s | Recent blotter entries |
| `thread:{id}` | 120s | Full thread data |
| `catalog:{slug}` | 60s | Board catalog |

See [ARCHITECTURE.md](docs/ARCHITECTURE.md) §Caching Strategy for details.

## Security

### PII Protection

- **IP Addresses:** Encrypted with XChaCha20-Poly1305 at rest
- **Delete Passwords:** Hashed with bcrypt
- **Poster IDs:** Deterministic hash (IP + thread + day)

### Input Validation

- Content length limits (20,000 chars max)
- Name/subject length limits (100 chars max)
- IP address format validation
- Board slug format validation (`^[a-z0-9]{1,32}$`)

### XSS Prevention

- All user content HTML-encoded before markup processing
- Allowed markup: greentext, quote links, spoilers, code blocks
- URL auto-linking with scheme restriction (http/https only)

See [SECURITY.md](docs/SECURITY.md) for complete security documentation.

## Data Retention

| Data Type | Retention Period | Action |
|-----------|------------------|--------|
| Post IP addresses | 30 days | Nullify |
| Post emails | 30 days | Nullify |
| Flood log entries | 24 hours | Delete |

Retention jobs run automatically via scheduler.

## Testing

```bash
# Run unit tests
composer test

# Run PHPStan analysis
composer phpstan

# Run all checks
make test
```

## Monitoring

### Health Check

```bash
curl http://localhost:9503/health
# Response: {"status":"ok"}
```

### Logs

Logs are JSON-formatted to STDERR:

```bash
docker logs boards-threads-posts 2>&1 | jq .
```

### Metrics (Future)

- Prometheus metrics endpoint
- Key metrics: posts/second, threads/second, cache hit rate

## Related Documentation

- [Architecture](docs/ARCHITECTURE.md) - System architecture, caching, events
- [API Reference](docs/API.md) - Complete API documentation
- [Security Model](docs/SECURITY.md) - Security architecture and threats
- [Type Hinting Guide](docs/TYPE_HINTING_GUIDE.md) - PHPStan 10 compliance
- [Troubleshooting](docs/TROUBLESHOOTING.md) - Common issues and solutions

## License

Apache License 2.0 - See LICENSE file for details.
