# ashchan
[![PHP Composer](https://github.com/txrx-byte/ashchan/actions/workflows/php.yml/badge.svg)](https://github.com/txrx-byte/ashchan/actions/workflows/php.yml)
[![PHPStan Level 10](https://img.shields.io/badge/PHPStan-Level%2010-brightgreen.svg?style=flat)](https://phpstan.org/)

Ashchan is a high-performance, privacy-first imageboard built on Hyperf with a distributed microservices architecture. It features an **mTLS ServiceMesh** for zero-trust security, DNS-based service discovery, and runs entirely on **rootless Podman** (no Kubernetes).

## Features

- **mTLS ServiceMesh**: All service-to-service communication encrypted and authenticated via mutual TLS
- **DNS-Based Discovery**: Services addressed by name (`auth.ashchan.local`, `boards.ashchan.local`, etc.)
- **Rootless Podman**: No root required, simpler deployment than Kubernetes
- **Privacy-First**: Minimal data retention, IP hashing, compliance-ready (GDPR/CCPA)
- **Horizontal Scale**: Designed for traffic spikes and high availability

---

## Quick Start

### 1. Initialize mTLS Certificates

```bash
# Generate Root CA (one-time)
make mtls-init

# Generate service certificates
make mtls-certs
```

### 2. Configure Services

```bash
# Copy .env.example to .env for all services
make install
```

### 3. Start Services

```bash
# Start all services (PostgreSQL, Redis, MinIO, + 6 services)
make up

# Or use podman-compose directly
podman-compose up -d
```

### 4. Verify Health

```bash
# Check mTLS mesh status
make mtls-verify

# Check service health
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
| [docs/system-design.md](docs/system-design.md) | Request flows, caching, failure isolation |
| [docs/security.md](docs/security.md) | Security controls, encryption, audit logging |

### API & Contracts
| Document | Description |
|----------|-------------|
| [contracts/openapi/README.md](contracts/openapi/README.md) | API specifications per service |
| [contracts/events/README.md](contracts/events/README.md) | Domain event schemas |

### Database & Migrations
| Document | Description |
|----------|-------------|
| [db/README.md](db/README.md) | Database migrations and schema |

### Development & Code Quality
| Document | Description |
|----------|-------------|
| [PHPSTAN_GUIDE.md](PHPSTAN_GUIDE.md) | **PHPStan Level 10 configuration, usage, and best practices** |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Contribution guidelines and standards |

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

## Service Mesh Architecture

```
                                    ┌─────────────────┐
                                    │   Public Internet
                                    └────────┬────────┘
                                             │
                                    ┌────────▼────────┐
                                    │  API Gateway    │
                                    │  (Port 9501)    │
                                    └────────┬────────┘
                                             │ mTLS (Port 8443)
         ┌───────────────────┬───────────────┼───────────────┬───────────────────┐
         │                   │               │               │                   │
┌────────▼────────┐ ┌────────▼────────┐ ┌───▼─────────┐ ┌───▼─────────┐ ┌───────▼───────┐
│ Auth/Accounts   │ │Boards/Threads   │ │ Media/      │ │ Search/     │ │ Moderation/   │
│ mtls:8443       │ │ mtls:8443       │ │ Uploads     │ │ Indexing    │ │ Anti-Spam     │
└────────┬────────┘ └────────┬────────┘ └──────┬──────┘ └──────┬──────┘ └───────┬───────┘
         │                   │                 │               │                 │
         └───────────────────┴─────────────────┴───────────────┴─────────────────┘
                                    │
                        ┌───────────┴───────────┐
                        │   ServiceMesh Network │
                        │    10.90.0.0/24       │
                        │   DNS: ashchan.local  │
                        └───────────┬───────────┘
                                    │
              ┌─────────────────────┼─────────────────────┐
              │                     │                     │
     ┌────────▼────────┐  ┌────────▼────────┐  ┌────────▼────────┐
     │   PostgreSQL    │  │     Redis       │  │     MinIO       │
     └─────────────────┘  └─────────────────┘  └─────────────────┘
```

### Service DNS Names

| Service | DNS Name | Internal URL |
|---------|----------|--------------|
| API Gateway | `gateway.ashchan.local` | `https://gateway.ashchan.local:8443` |
| Auth/Accounts | `auth.ashchan.local` | `https://auth.ashchan.local:8443` |
| Boards/Threads/Posts | `boards.ashchan.local` | `https://boards.ashchan.local:8443` |
| Media/Uploads | `media.ashchan.local` | `https://media.ashchan.local:8443` |
| Search/Indexing | `search.ashchan.local` | `https://search.ashchan.local:8443` |
| Moderation/Anti-Spam | `moderation.ashchan.local` | `https://moderation.ashchan.local:8443` |

---

## Makefile Commands

### Development
```bash
make install      # Copy .env files for all services
make up           # Start all services
make down         # Stop all services
make logs         # Tail logs from all services
make migrate      # Run database migrations
make test         # Run all service tests
make lint         # Lint all PHP code
```

### mTLS ServiceMesh
```bash
make mtls-init      # Generate Root CA for ServiceMesh
make mtls-certs     # Generate all service certificates
make mtls-verify    # Verify mTLS mesh configuration
make mtls-rotate    # Rotate all service certificates
make mtls-status    # Show certificate status
```

### Helpers
```bash
make rebuild        # Rebuild all service images
make rebuild-<svc>  # Rebuild specific service
make restart        # Restart all services
make health         # Check service health
make clean          # Clean up Podman artifacts
make clean-certs    # Remove all generated certificates
```

---

## Certificate Management

### Generate Certificates

```bash
# Generate Root CA (valid for 10 years)
./scripts/mtls/generate-ca.sh

# Generate all service certificates (valid for 1 year)
./scripts/mtls/generate-all-certs.sh

# Generate single service certificate
./scripts/mtls/generate-cert.sh gateway gateway.ashchan.local
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

### Rotate Certificates

```bash
# Rotate all certificates (with rolling restart)
./scripts/mtls/rotate-certs.sh

# Or use make
make mtls-rotate
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

## Troubleshooting

### Common Issues

| Issue | Solution |
|-------|----------|
| Certificate verification failed | Run `make mtls-verify` to diagnose |
| DNS resolution fails | Check Podman network: `podman network inspect ashchan-mesh` |
| Service won't start | Check logs: `podman-compose logs <service>` |
| mTLS handshake fails | Verify certificates match: `make mtls-status` |

### Debug Commands

```bash
# Check service status
podman ps

# View service logs
podman-compose logs -f api-gateway

# Test mTLS connection
curl --cacert certs/ca/ca.crt \
     --cert certs/services/gateway/gateway.crt \
     --key certs/services/gateway/gateway.key \
     https://gateway.ashchan.local:8443/health

# Check DNS resolution
podman exec ashchan-gateway-1 getent hosts auth.ashchan.local
```

### See Also
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) - Detailed troubleshooting guide

---

## Development

### Requirements
- Podman 4.0+
- Podman Compose 2.0+
- OpenSSL (for certificate generation)
- PHP 8.2+ (for local development)

### Local Development Without Containers

```bash
cd services/api-gateway
composer install
cp .env.example .env
# Edit .env to use localhost for DB, Redis
php bin/hyperf.php start
```

### Running Tests

```bash
# Run all tests
make test

# Run single service tests
cd services/api-gateway
composer test
```

### Code Style

```bash
# Lint all services
make lint

# Lint single service
cd services/api-gateway
composer lint
```

### Static Analysis (PHPStan Level 10)

The project uses PHPStan at maximum strictness (Level 10) for comprehensive type safety:

```bash
# Analyze all services and root code
composer phpstan

# Analyze individual service
cd services/api-gateway
composer phpstan

# Analyze all services sequentially
composer phpstan:all-services
```

See [PHPSTAN_GUIDE.md](PHPSTAN_GUIDE.md) for complete documentation on:
- Configuration details
- Best practices for type-safe code
- CI/CD integration
- Troubleshooting common issues

---

## Deployment

### Production Checklist

- [ ] Generate production CA (separate from dev)
- [ ] Configure firewall rules
- [ ] Set up log aggregation
- [ ] Configure backup strategy
- [ ] Set up monitoring and alerts
- [ ] Test certificate rotation
- [ ] Document runbooks

### Systemd Service (Production)

```ini
# /etc/systemd/system/ashchan-gateway.service
[Unit]
Description=Ashchan API Gateway
After=network.target ashchan-postgres.service

[Service]
Type=simple
User=ashchan
ExecStart=/usr/bin/podman run --rm \
  --name ashchan-gateway \
  --network ashchan-mesh \
  -v /etc/ashchan/certs:/etc/mtls:ro \
  ashchan-gateway:latest
Restart=always

[Install]
WantedBy=multi-user.target
```

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

### Commit Messages
Use conventional commits: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`

### Code Style
- PSR-12 compliance
- Type hints required (`declare(strict_types=1);`)
- PHPStan static analysis

---

## License

See [LICENSE](LICENSE) for details.

---

## Status

✅ mTLS ServiceMesh architecture complete
✅ Certificate generation and rotation scripts
✅ DNS-based service discovery
✅ Rootless Podman deployment
✅ Service scaffolding and migrations
✅ OpenAPI contracts
✅ Event schemas
✅ Moderation system (ported from OpenYotsuba)

🚧 Domain logic implementation
🚧 Event publishing/consumption
🚧 Integration tests
