# Architecture

## Goals
- High throughput, low latency for read-heavy workloads.
- Horizontal scalability for traffic spikes.
- Strong isolation between services and data domains.
- Privacy-first design with minimal data retention.
- Operational safety with clear failure boundaries.
- Zero-trust security with mTLS service-to-service authentication.

## Non-Goals
- Monolithic deployment.
- Tight coupling between services.
- User tracking beyond operational security needs.
- Container orchestration complexity (uses native PHP-CLI instead).

## High-Level Topology

### Native PHP-CLI Architecture

```
                                    ┌─────────────────┐
                                    │   Public Internet
                                    └────────┬────────┘
                                             │
                                    ┌────────▼────────┐
                                    │  API Gateway    │
                                    │  (Port 9501)    │
                                    │  TLS Termination│
                                    └────────┬────────┘
                                             │ mTLS (Port 8443)
         ┌───────────────────┬───────────────┼───────────────┬───────────────────┐
         │                   │               │               │                   │
┌────────▼────────┐ ┌────────▼────────┐ ┌───▼─────────┐ ┌───▼─────────┐ ┌───────▼───────┐
│ Auth/Accounts   │ │Boards/Threads   │ │ Media/      │ │ Search/     │ │ Moderation/   │
│ (Port 9502)     │ │ (Port 9503)     │ │ Uploads     │ │ Indexing    │ │ Anti-Spam     │
│ mTLS:8443       │ │ mTLS:8443       │ │ (Port 9504) │ │ (Port 9505) │ │ (Port 9506)   │
└────────┬────────┘ └────────┬────────┘ └──────┬──────┘ └──────┬──────┘ └───────┬───────┘
         │                   │                 │               │                 │
         └───────────────────┴─────────────────┴───────────────┴─────────────────┘
                                    │
              ┌─────────────────────┼─────────────────────┐
              │                     │                     │
     ┌────────▼────────┐  ┌────────▼────────┐  ┌────────▼────────┐
     │   PostgreSQL    │  │     Redis       │  │     MinIO       │
     │   (Port 5432)   │  │   (Port 6379)   │  │ (Port 9000/9001)│
     └─────────────────┘  └─────────────────┘  └─────────────────┘
```

### Service Boundaries

#### API Gateway
- Request routing, versioning, token verification, per-route rate limits.
- TLS termination for public traffic.
- mTLS client for all service-to-service communication.
- WebSocket upgrade ready for live thread updates.

#### Auth/Accounts
- Anonymous identity issuance and optional registered accounts.
- Consents and compliance flags (age gate, legal holds).
- mTLS server on port 8443.

#### Boards/Threads/Posts
- Canonical source of truth for boards, threads, posts, and metadata.
- Emits domain events for indexing, moderation, and cache invalidation.
- mTLS server on port 8443.

#### Media/Uploads
- Signed upload URLs and ingest validation.
- Media hashing, deduplication, and metadata extraction.
- mTLS server on port 8443.

#### Search/Indexing
- Consumes domain events and updates search backend.
- Read-optimized query API for the gateway.
- mTLS server on port 8443.

#### Moderation/Anti-Spam
- Risk scoring, throttling policies, and enforcement actions.
- Human moderation queue and audit trail.
- mTLS server on port 8443.

## Data Stores

### PostgreSQL
- Durable domain data and compliance logs.
- Connection: `localhost:5432` or configured host
- Used by all services via TCP connection.

### Redis
- Cache, rate limits, queues, and distributed locks.
- Connection: `localhost:6379` or configured host
- Pub/sub for WebSocket fan-out.

### Object Storage (MinIO/S3)
- Media blobs with immutable hashes.
- Endpoint: `localhost:9000` or configured S3-compatible endpoint
- Used by Media/Uploads service.

## Deployment Architecture

### Native PHP-CLI (Swoole)

Each service runs as a standalone PHP process using the Swoole extension:

```bash
# Start a service
php bin/hyperf.php start

# Services are managed via:
# - Systemd (production)
# - make up/down (development)
# - Direct PHP execution (debugging)
```

### Process Management

| Method | Use Case |
|--------|----------|
| Systemd | Production deployments with auto-restart |
| Make targets | Development and testing |
| Direct PHP | Debugging and troubleshooting |

### Service Communication

Services communicate via HTTP/HTTPS:

| Service | HTTP Port | mTLS Port | Environment Variable |
|---------|-----------|-----------|---------------------|
| API Gateway | 9501 | 8443 | `GATEWAY_URL` |
| Auth/Accounts | 9502 | 8443 | `AUTH_SERVICE_URL` |
| Boards/Threads/Posts | 9503 | 8443 | `BOARDS_SERVICE_URL` |
| Media/Uploads | 9504 | 8443 | `MEDIA_SERVICE_URL` |
| Search/Indexing | 9505 | 8443 | `SEARCH_SERVICE_URL` |
| Moderation/Anti-Spam | 9506 | 8443 | `MODERATION_SERVICE_URL` |

## Eventing and Async

### Domain Events
- Emitted by core services via Redis streams.
- Consumers for indexing, moderation, and cache invalidation.
- Retry with dead-letter queues for failed consumers.

### Event Schema
- Defined in `contracts/events/README.md`
- CloudEvents-compatible format.

## Caching Strategy

### Varnish HTTP Cache (L1)
- In-memory HTTP object cache between Anubis and the API Gateway.
- Caches board pages, threads, catalogs, and read-only API responses.
- 30s TTL for most pages, 10s for 4chan API, 60s for archives.
- Grace periods serve stale content during backend fetches (stampede protection).
- Invalidated via HTTP BAN/PURGE from the `CacheInvalidatorProcess`.
- See [docs/VARNISH_CACHE.md](VARNISH_CACHE.md) for full details.

### Application Cache (L2 — Gateway Redis)
- Common data (boards list, blotter) cached in Redis DB 0 with 60s TTL.
- Staff session validation cached with 60s TTL.

### Domain Cache (L3 — Service Redis)
- Board/thread/catalog data cached in backend service Redis DB 2.
- TTLs: 120-600s depending on data type.
- Invalidated via `BoardService::invalidateBoardCaches()` on writes.

### Cache Invalidation Flow
- Domain events (Redis Streams) trigger the gateway's `CacheInvalidatorProcess`.
- Process issues HTTP BAN requests to Varnish for pattern-based invalidation.
- Process also deletes Redis cache keys for application-level invalidation.
- Sub-second propagation from write to cache eviction.

## WebSockets Readiness

### Live Updates
- Gateway uses pub/sub fan-out from Posts events.
- Backpressure and per-connection rate limits.
- Connection state tracked in Redis.

## Observability

### Logging
- Structured logs with correlation IDs.
- JSON format for log aggregation.
- mTLS handshake events logged for audit.

### Metrics
- Rate limits, queue depths, moderation actions.
- Certificate expiration monitoring.
- mTLS handshake success/failure rates.

### Tracing
- Distributed tracing across gateway and services.
- Correlation IDs propagated via headers.

## Security

### mTLS (Mutual TLS)
- All service-to-service communication encrypted and authenticated.
- Certificates signed by internal CA.
- TLS 1.3 minimum, strong cipher suites only.
- See [docs/SERVICEMESH.md](SERVICEMESH.md) for details.

### Certificate Management
- Root CA valid for 10 years.
- Service certificates valid for 1 year.
- Automatic rotation scripts available.
- See `scripts/mtls/` for tooling.

### Input Validation
- Strict schema validation at gateway and service boundaries.
- Content sanitization for user-generated content.
- Content Security Policy headers.

### Audit Logging
- Structured logs with correlation IDs.
- Immutable audit log for compliance actions.
- Tamper-evident hashing for sensitive operations.

### Data Encryption
- TLS in transit (mTLS for internal, TLS for public).
- Encrypted at rest for sensitive fields (PII, consents).
- Key management via environment variables.

### Cloudflare Tunnel (Zero Public Exposure)
- Origin server has **no public IP** and **no open inbound ports**.
- `cloudflared` daemon creates an outbound-only encrypted tunnel to Cloudflare's edge.
- Cloudflare provides WAF, DDoS protection, CDN, and bot management at the edge.
- Eliminates the need for origin IP secrecy or Authenticated Origin Pulls.
- TLS is end-to-end: client ↔ Cloudflare (TLS 1.3) + tunnel (encrypted) + mTLS (service mesh).

### Rate Limiting & DDoS Protection
- Cloudflare WAF and DDoS protection at the edge.
- nginx rate limits as secondary defense.
- Gateway-level rate limits per route.
- Per-service circuit breakers.

## Deployment

### Development
- Direct PHP process execution via Makefile.
- mTLS certificates generated locally.
- Services communicate via localhost.
- No public internet exposure required.

### Production
- **Cloudflare Tunnel** (`cloudflared`) for zero-exposure ingress.
- Systemd service units for process management.
- Centralized CA (Vault or HSM recommended).
- No public IP, no open firewall ports — all traffic arrives via tunnel.
- Static assets and media served via Cloudflare CDN edge cache.

### Service Identity

Each service has a unique identity based on X.509 certificates:

| Service | Certificate CN | Subject Alternative Names |
|---------|----------------|---------------------------|
| API Gateway | `gateway` | `gateway`, `localhost` |
| Auth/Accounts | `auth` | `auth`, `localhost` |
| Boards/Threads/Posts | `boards` | `boards`, `localhost` |
| Media/Uploads | `media` | `media`, `localhost` |
| Search/Indexing | `search` | `search`, `localhost` |
| Moderation/Anti-Spam | `moderation` | `moderation`, `localhost` |

## Failure Isolation

### Service Outages
- Search outage does not block posting.
- Media outage blocks upload but not text posts.
- Moderation backlog does not block viewing.

### Circuit Breakers
- Per-service timeout and retry limits.
- Fallback behavior for non-critical services.

### Database Isolation
- Separate connection pools per service.
- Read replicas for high-traffic boards.
