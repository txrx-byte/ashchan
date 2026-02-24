# ashchan

**English** | [中文](README.zh.md) | [日本語](README.ja.md)

[![PHP Composer](https://github.com/txrx-byte/ashchan/actions/workflows/php.yml/badge.svg)](https://github.com/txrx-byte/ashchan/actions/workflows/php.yml)
![enbyware](https://pride-badges.pony.workers.dev/static/v1?label=enbyware&labelColor=%23555&stripeWidth=8&stripeColors=FCF434%2CFFFFFF%2C9C59D1%2C2C2C2C)

Ashchan is a high-performance, privacy-first imageboard built on **Hyperf/Swoole** with a distributed microservices architecture. It runs natively on **PHP-CLI via Swoole** without containerization dependencies, providing a simpler deployment model with direct process management.

## Features

- **Zero Public Exposure**: Cloudflare Tunnel ingress — origin server has no public IP or open ports
- **End-to-End Encryption**: Cloudflare TLS → tunnel encryption → mTLS service mesh — 100% encrypted
- **Native PHP-CLI**: Direct Swoole-based PHP processes without container overhead
- **mTLS Security**: Service-to-service communication secured via mutual TLS certificates
- **Multi-Layer Caching**: Cloudflare CDN → Varnish HTTP cache → Redis application cache
- **Privacy-First**: Minimal data retention, IP hashing, compliance-ready (GDPR/CCPA)
- **Horizontal Scale**: Designed for traffic spikes and high availability
- **Systemd Integration**: Production-ready service management

---

## Quick Start

### Requirements

- PHP 8.2+ with Swoole extension
- PostgreSQL 16+
- Redis 7+
- MinIO or S3-compatible storage (for media)
- OpenSSL (for certificate generation)
- Composer (PHP dependency manager)
- Make (build tool)

#### Alpine Linux (apk)

```bash
# PHP 8.4 + required extensions
sudo apk add --no-cache \
  php84 php84-openssl php84-pdo php84-pdo_pgsql php84-mbstring \
  php84-curl php84-pcntl php84-phar php84-iconv php84-dom php84-xml \
  php84-xmlwriter php84-tokenizer php84-fileinfo php84-ctype \
  php84-posix php84-session php84-sockets \
  php84-pecl-swoole php84-pecl-redis \
  openssl composer postgresql-client redis make

# Create php symlink if not present
sudo ln -sf $(which php84) /usr/local/bin/php
```

#### Ubuntu/Debian (apt)

```bash
sudo apt-get install -y \
  php8.2 php8.2-cli php8.2-swoole php8.2-pgsql php8.2-redis \
  php8.2-mbstring php8.2-curl php8.2-xml php8.2-dom \
  openssl composer postgresql-client redis-server make
```

### Installation

```bash
# 1. Install PHP dependencies for all services
make install

# 2. Generate mTLS certificates
make mtls-init && make mtls-certs

# 3. Configure services (edit .env files as needed)
# Each service has its own .env file in services/<service-name>/.env

# 4. Start all services
make up

# 5. Run database migrations
make migrate

# 6. Seed the database
make seed
```

### Quick Development Start

```bash
# Complete bootstrap (installs deps, generates certs, starts services)
make bootstrap

# Or for quick restart during development
make dev-quick
```

### Verify Health

```bash
# Check all services
make health

# Check individual service
curl http://localhost:9501/health

# Check certificate status
make mtls-status
```

---

## Documentation

### Architecture & Design
| Document | Description |
|----------|-------------|
| [docs/architecture.md](docs/architecture.md) | System architecture, service boundaries, network topology |
| [docs/SERVICEMESH.md](docs/SERVICEMESH.md) | **mTLS architecture, certificate management, security** |
| [docs/VARNISH_CACHE.md](docs/VARNISH_CACHE.md) | **Varnish HTTP cache layer, invalidation, tuning** |
| [docs/system-design.md](docs/system-design.md) | Request flows, caching, failure isolation |
| [docs/security.md](docs/security.md) | Security controls, encryption, audit logging |
| [docs/FIREWALL_HARDENING.md](docs/FIREWALL_HARDENING.md) | **Firewall, fail2ban, sysctl hardening (Linux & FreeBSD)** |
| [docs/ACTIVITYPUB_FEDERATION.md](docs/ACTIVITYPUB_FEDERATION.md) | **ActivityPub federation design — decentralized imageboard protocol** |
| [docs/FEATURE_MATRIX.md](docs/FEATURE_MATRIX.md) | **Comparative feature matrix (4chan, meguca, vichan, Ashchan)** |

### API & Contracts
| Document | Description |
|----------|-------------|
| [docs/FOURCHAN_API.md](docs/FOURCHAN_API.md) | **4chan-compatible read-only API (egress in exact 4chan format)** |
| [contracts/openapi/README.md](contracts/openapi/README.md) | API specifications per service |
| [contracts/events/README.md](contracts/events/README.md) | Domain event schemas |

### Database & Migrations
| Document | Description |
|----------|-------------|
| [db/README.md](db/README.md) | Database migrations and schema |

### Services
| Service | Port | Description |
|---------|------|-------------|
| [services/api-gateway](services/api-gateway) | 9501 | API Gateway, routing, rate limiting |
| [services/auth-accounts](services/auth-accounts) | 9502 | Auth/Accounts service |
| [services/boards-threads-posts](services/boards-threads-posts) | 9503 | Boards/Threads/Posts service |
| [services/media-uploads](services/media-uploads) | 9504 | Media uploads and processing |
| [services/search-indexing](services/search-indexing) | 9505 | Search backend |
| [services/moderation-anti-spam](services/moderation-anti-spam) | 9506 | Moderation and anti-spam |

---

## Architecture

```
╔═════════════════════════ PUBLIC INTERNET ═══════════════════════════╗
║                                                                          ║
║  Client ── TLS 1.3 ──▶ Cloudflare Edge (WAF, DDoS, CDN)                  ║
║                              │                                          ║
║                       Cloudflare Tunnel                                 ║
║                       (outbound-only, encrypted)                        ║
║                              │                                          ║
╚══════════════════════════════┼═══════════════════════════════╝
                              │
╔══════════════════════════════┼═ ORIGIN (no public ports) ══════╗
║                              │                                          ║
║                     ┌────────▼───────┐                                   ║
║                     │ cloudflared      │                                   ║
║                     └────────┬───────┘                                   ║
║                              │                                          ║
║                     ┌────────▼───────┐                                   ║
║                     │ nginx (80)       │─── Static/Media ──┐             ║
║                     └────────┬───────┘                   │             ║
║                              │                          │             ║
║                     ┌────────▼───────┐                   │             ║
║                     │ Anubis (8080)   │  PoW challenge    │             ║
║                     └────────┬───────┘                   │             ║
║                              │                          │             ║
║                     ┌────────▼───────┐                   │             ║
║                     │ Varnish (6081)  │  HTTP cache       │             ║
║                     └────────┬───────┘                   │             ║
║                              │                          │             ║
║                     ┌────────▼────────────────────────┘             ║
║                     │        API Gateway (9501)          │             ║
║                     └─────────┬───────────────────────┘             ║
║                              │ mTLS                                    ║
║      ┌───────┬────────┬────────┼────────┬────────┐                    ║
║      │       │        │        │        │        │                    ║
║   ┌──▼──┐ ┌──▼───┐ ┌──▼───┐ ┌──▼───┐ ┌──▼───┐                    ║
║   │ Auth│ │Boards │ │ Media │ │Search │ │ Mod. │                    ║
║   │ 9502│ │ 9503  │ │ 9504  │ │ 9505  │ │ 9506  │                    ║
║   └──┬──┘ └──┬───┘ └──┬───┘ └──┬───┘ └──┬───┘                    ║
║      │       │        │        │        │                           ║
║      └───────┴────────┴────────┴────────┘                           ║
║                     │                                              ║
║      ┌─────────────┼──────────────────┐                           ║
║      │              │                  │                           ║
║  ┌───▼───────┐  ┌──▼────────┐  ┌──▼───────┐                      ║
║  │ PostgreSQL │  │  Redis     │  │ MinIO     │                      ║
║  │   5432     │  │  6379      │  │ 9000/9001 │                      ║
║  └───────────┘  └────┬───────┘  └──────────┘                      ║
║                       │                                              ║
║              Redis Streams (DB 6)                                     ║
║              ashchan:events                                           ║
║       ┌────────────┼────────────┐                                    ║
║       │            │            │                                    ║
║  ┌────▼─────┐  ┌──▼──────┐  ┌─▼────────┐                             ║
║  │ Cache     │  │ Post    │  │ Search    │                             ║
║  │ Invalidate│  │ Scoring │  │ Indexing  │                             ║
║  │ +Varnish  │  │ (Mod.)  │  │ Consumer  │                             ║
║  └───────────┘  └─────────┘  └───────────┘                             ║
║                                                                          ║
╚══════════════════════════════════════════════════════════════════════════╝
```

**End-to-end encryption:** Client ↔ Cloudflare (TLS 1.3) → Cloudflare Tunnel (encrypted) → nginx → Anubis (PoW) → Varnish (cache) → API Gateway → backend services (mTLS). The origin server has **no public IP** and **no open inbound ports** — `cloudflared` creates an outbound-only tunnel.

### Service Communication

Services communicate via HTTP/HTTPS over localhost or configured host addresses. For production deployments with mTLS:

| Service | HTTP Port | mTLS Port | Address |
|---------|-----------|-----------|---------|
| API Gateway | 9501 | 8443 | localhost or configured host |
| Auth/Accounts | 9502 | 8443 | localhost or configured host |
| Boards/Threads/Posts | 9503 | 8443 | localhost or configured host |
| Media/Uploads | 9504 | 8443 | localhost or configured host |
| Search/Indexing | 9505 | 8443 | localhost or configured host |
| Moderation/Anti-Spam | 9506 | 8443 | localhost or configured host |

---

## Makefile Commands

### Development
```bash
make install      # Copy .env.example to .env for all services
make up           # Start all services (native PHP processes)
make down         # Stop all services
make logs         # View combined logs
make migrate      # Run database migrations
make seed         # Seed the database
make test         # Run all service tests
make lint         # Lint all PHP code
make phpstan      # Run PHPStan static analysis
```

### Bootstrap & Quick Start
```bash
make bootstrap    # Complete setup (deps, certs, services, migrations, seed)
make dev-quick    # Quick restart for development iteration
```

### mTLS Certificates
```bash
make mtls-init    # Generate Root CA for ServiceMesh
make mtls-certs   # Generate all service certificates
make mtls-verify  # Verify mTLS configuration
make mtls-rotate  # Rotate all service certificates
make mtls-status  # Show certificate expiration status
```

### Service Management
```bash
make start-<svc>  # Start a specific service
make stop-<svc>   # Stop a specific service
make restart      # Restart all services
make health       # Check health of all services
make clean        # Clean runtime artifacts
make clean-certs  # Remove all generated certificates
```

### Static Binary Build (Optional)

Build portable, self-contained executables with no PHP runtime dependency. Uses [static-php-cli](https://github.com/crazywhalecc/static-php-cli) to compile PHP + Swoole + all extensions into a single static binary per service.

```bash
make build-static           # Build all services as static binaries
make build-static-gateway   # Build only the gateway
make build-static-boards    # Build only boards service
make build-static-php       # Build the static PHP binary only
make build-static-clean     # Remove build artifacts
```

Output binaries go to `build/static-php/dist/`:
```bash
./build/static-php/dist/ashchan-gateway start     # No PHP install needed
PORT=9501 ./ashchan-gateway start                  # Override port via env
```

See [build/static-php/build.sh](build/static-php/build.sh) for full options and environment variables.

---

## Certificate Management

### Generate Certificates

```bash
# Generate Root CA (valid for 10 years)
./scripts/mtls/generate-ca.sh

# Generate all service certificates (valid for 1 year)
./scripts/mtls/generate-all-certs.sh

# Generate single service certificate
./scripts/mtls/generate-cert.sh gateway localhost
```

### Verify Certificates

```bash
# Verify entire mesh
./scripts/mtls/verify-mesh.sh

# Check single certificate
openssl x509 -in certs/services/gateway/gateway.crt -text -noout

# Verify certificate chain
openssl verify -CAfile certs/ca/ca.crt certs/services/gateway/gateway.crt
```

### Certificate Locations

```
certs/
├── ca/
│   ├── ca.crt              # Root CA certificate
│   ├── ca.key              # Root CA private key
│   └── ca.cnf              # CA configuration
└── services/
    ├── gateway/
    │   ├── gateway.crt     # Gateway certificate
    │   └── gateway.key     # Gateway private key
    ├── auth/
    ├── boards/
    ├── media/
    ├── search/
    └── moderation/
```

---

## Development

### Running Individual Services

```bash
# Start a single service for development
cd services/api-gateway
composer install
cp .env.example .env
# Edit .env to configure DB, Redis, etc.
php bin/hyperf.php start
```

### Running Tests

```bash
# Run all tests
make test

# Run single service tests
cd services/boards-threads-posts
composer test

# Run with coverage
composer test -- --coverage-html coverage/
```

### Code Style

```bash
# Lint all services
make lint

# Run PHPStan
make phpstan

# Fix code style (per service)
cd services/api-gateway
composer cs-fix
```

---

## Deployment

### Production Requirements

- **PHP 8.2+** with extensions: swoole, openssl, curl, pdo, pdo_pgsql, redis, mbstring, json, pcntl
- **PostgreSQL 16+** for persistent storage
- **Redis 7+** for caching, rate limiting, and queues
- **MinIO** or S3-compatible storage for media files
- **Systemd** for process management (recommended)

### Systemd Service Example

```ini
# /etc/systemd/system/ashchan-gateway.service
[Unit]
Description=Ashchan API Gateway
After=network.target postgresql.service redis.service

[Service]
Type=simple
User=ashchan
Group=ashchan
WorkingDirectory=/opt/ashchan/services/api-gateway
Environment=APP_ENV=production
ExecStart=/usr/bin/php bin/hyperf.php start
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### Production Checklist

- [ ] Generate production CA (separate from dev)
- [ ] Configure firewall rules for service ports
- [ ] Set up log aggregation (e.g., journald → Loki)
- [ ] Configure backup strategy for PostgreSQL
- [ ] Set up monitoring and alerts (e.g., Prometheus)
- [ ] Test certificate rotation procedure
- [ ] Document runbooks for common operations
- [ ] Configure rate limiting per your traffic expectations

---

## Troubleshooting

### Common Issues

| Issue | Solution |
|-------|----------|
| Service won't start | Check logs: `journalctl -u ashchan-<service>` |
| Database connection error | Verify PostgreSQL is running and `.env` is correct |
| Redis connection error | Verify Redis is running and password matches |
| mTLS handshake fails | Regenerate certs: `make mtls-certs` |
| Port already in use | Check for existing processes: `lsof -i :<port>` |

### Debug Commands

```bash
# Check service status
systemctl status ashchan-gateway

# View service logs
journalctl -u ashchan-gateway -f

# Test mTLS connection
curl --cacert certs/ca/ca.crt \
     --cert certs/services/gateway/gateway.crt \
     --key certs/services/gateway/gateway.key \
     https://localhost:8443/health

# Check PHP extensions
php -m | grep -E 'swoole|openssl|pdo|redis'
```

### See Also
- [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) - Detailed troubleshooting guide

---

## Contributing

See [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) for guidelines.

### Commit Messages
Use conventional commits: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`

### Code Style
- PSR-12 compliance
- Type hints required (`declare(strict_types=1);`)
- PHPStan Level 10 static analysis

---

## License

Licensed under the Apache License, Version 2.0. See [LICENSE](LICENSE) for the full text.

---

## Status

✅ mTLS certificate generation and rotation scripts  
✅ Service scaffolding and migrations  
✅ OpenAPI contracts  
✅ Event schemas  
✅ Moderation system (ported from OpenYotsuba)  
✅ Native PHP-CLI deployment model  

🚧 Domain logic implementation  
🚧 Event publishing/consumption  
🚧 Integration tests
