# Static PHP CLI Evaluation for Ashchan

**Document Type:** Architecture Decision  
**Date:** February 2026  
**Status:** Decided - Native PHP-CLI with Swoole

---

## Executive Summary

This document evaluates deployment options for Ashchan's microservices. After evaluation, we have chosen **native PHP-CLI via Swoole** as the deployment model, avoiding containerization complexity while maintaining all required functionality.

### Decision

**Deploy using native PHP-CLI processes managed by Systemd (production) or Make targets (development).**

**Rationale:**
1. **Simplicity**: No container orchestration complexity (Podman, Docker, Kubernetes)
2. **Performance**: Direct process execution without container overhead
3. **Debugging**: Easier troubleshooting with standard PHP tooling
4. **Flexibility**: Can still containerize later if needed

---

## Requirements

### Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-1 | Run Hyperf/Swoole applications | **Critical** | ✅ Met |
| FR-2 | Support PHP 8.2+ | **Critical** | ✅ Met |
| FR-3 | Support required extensions | **Critical** | ✅ Met |
| FR-4 | mTLS for service communication | **High** | ✅ Met |
| FR-5 | Process management | **High** | ✅ Met |

### Non-Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| NFR-1 | Easy deployment | **High** | ✅ Met |
| NFR-2 | Low operational overhead | **High** | ✅ Met |
| NFR-3 | Easy debugging | **Medium** | ✅ Met |
| NFR-4 | Production-ready | **High** | ✅ Met |

---

## Chosen Solution: Native PHP-CLI

### Overview

Each Ashchan microservice runs as a standalone PHP process using the Swoole extension for high-performance async I/O.

```bash
# Start a service
php bin/hyperf.php start

# Service runs as a persistent process with Swoole event loop
```

### Process Management

| Environment | Method | Notes |
|-------------|--------|-------|
| Development | Makefile targets | `make up`, `make down`, `make restart` |
| Production | Systemd | Service units with auto-restart |

### Required PHP Extensions

| Extension | Purpose |
|-----------|---------|
| `swoole` | Async I/O, HTTP server |
| `openssl` | mTLS, encryption |
| `pdo_pgsql` | PostgreSQL database |
| `redis` | Caching, queues |
| `mbstring` | String handling |
| `curl` | HTTP client |
| `pcntl` | Process control |

### Pros

| Pro | Impact |
|-----|--------|
| ✅ No container complexity | Simpler operations |
| ✅ Direct debugging | Standard PHP tools work |
| ✅ Lower resource overhead | No container runtime |
| ✅ Faster startup | No image pull/unpack |
| ✅ Standard systemd management | Production-ready |
| ✅ Easy local development | Just run PHP |

### Cons

| Con | Impact | Mitigation |
|-----|--------|------------|
| ❌ No built-in isolation | Process-level only | Use systemd limits, AppArmor |
| ❌ Manual dependency management | Install PHP extensions manually | Document requirements |
| ❌ No image registry | Can't push/pull images | Use config management tools |

---

## Alternatives Considered

### Option 1: Podman/Docker Containers

**Rejected** due to complexity.

- Required podman-compose or docker-compose
- Network configuration issues with DNS resolution
- Resource overhead from container runtime
- Additional layer of abstraction for debugging

### Option 2: Static PHP Binary (swoole-cli)

**Deferred** for future consideration.

- Could be useful for truly portable deployments
- Build complexity not justified currently
- Can revisit if containerization becomes necessary

### Option 3: Kubernetes

**Rejected** as overkill for the use case.

- Significant operational overhead
- Requires orchestration expertise
- Better suited for much larger deployments

---

## Deployment Architecture

### Development

```
┌─────────────────────────────────────────────────────────────┐
│  Developer Workstation                                       │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  make up                                                    │
│  ├── php bin/hyperf.php start (api-gateway)                 │
│  ├── php bin/hyperf.php start (auth-accounts)               │
│  ├── php bin/hyperf.php start (boards-threads-posts)        │
│  ├── php bin/hyperf.php start (media-uploads)               │
│  ├── php bin/hyperf.php start (search-indexing)             │
│  └── php bin/hyperf.php start (moderation-anti-spam)        │
│                                                              │
│  + PostgreSQL (localhost:5432)                              │
│  + Redis (localhost:6379)                                   │
│  + MinIO (localhost:9000)                                   │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### Production

```
┌─────────────────────────────────────────────────────────────┐
│  Production Server                                           │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  Systemd Services:                                          │
│  ├── ashchan-gateway.service                                │
│  ├── ashchan-auth.service                                   │
│  ├── ashchan-boards.service                                 │
│  ├── ashchan-media.service                                  │
│  ├── ashchan-search.service                                 │
│  └── ashchan-moderation.service                             │
│                                                              │
│  + PostgreSQL (dedicated server or localhost)               │
│  + Redis (dedicated server or localhost)                    │
│  + MinIO/S3 (object storage)                                │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### Systemd Unit Example

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

# Resource limits
LimitNOFILE=65535
MemoryMax=512M
CPUQuota=200%

[Install]
WantedBy=multi-user.target
```

---

## Migration from Container-Based Deployment

### Removed Files

- `podman-compose.yml` - Container orchestration
- `podman-compose.runtime.yml` - Runtime containers
- `Dockerfile.runtime` - Container image definition
- `services/*/Dockerfile` - Per-service container definitions
- `docker/` - Docker/Podman build scripts
- `.dockerignore` - Docker ignore rules

### Updated Files

- `Makefile` - Native PHP process management
- `bootstrap.sh` - Setup without containers
- `dev-quick.sh` - Quick restart without containers
- `README.md` - Documentation for native deployment
- `docs/architecture.md` - Architecture documentation
- `docs/SERVICEMESH.md` - mTLS without container networking
- `docs/TROUBLESHOOTING.md` - Native PHP troubleshooting

### mTLS Implementation

mTLS is now configured directly in Hyperf/Swoole server settings rather than relying on container networking:

```php
// config/autoload/server.php
'options' => [
    'open_ssl' => true,
    'ssl_cert_file' => env('MTLS_CERT_FILE'),
    'ssl_key_file' => env('MTLS_KEY_FILE'),
    'ssl_verify_peer' => true,
    'ssl_ca_file' => env('MTLS_CA_FILE'),
],
```

---

## Future Considerations

### If Containerization Becomes Necessary

1. **swoole-cli static binaries**: Build static PHP binary with Swoole
2. **Single image**: All services in one container image
3. **OCI compliance**: Use Podman or Docker for building
4. **Registry**: Push to container registry for deployment

### When to Consider Containers

- Need for strict process isolation
- Multi-tenant deployments
- Regulated environments requiring container security
- Team prefers container workflow

---

## Conclusion

Native PHP-CLI deployment via Swoole provides the simplest path to production for Ashchan while maintaining all required functionality including mTLS security, horizontal scaling, and systemd-based process management.

The containerization option remains available for future if requirements change, but the current architecture avoids the operational complexity of container orchestration.

---

## References

- Hyperf Documentation: https://hyperf.wiki
- Swoole Documentation: https://www.swoole.co.uk/docs
- Systemd Service Units: https://www.freedesktop.org/software/systemd/man/systemd.service.html
