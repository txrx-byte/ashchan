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
- Kubernetes dependency (uses rootless Podman instead).

## High-Level Topology

### mTLS ServiceMesh Architecture

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
│ mtls:8443       │ │ mtls:8443       │ │ (Port 9504) │ │ (Port 9505) │ │ (Port 9506)   │
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
- Connection: `postgres.ashchan.local:5432`
- Used by all services via internal network.

### Redis
- Cache, rate limits, queues, and distributed locks.
- Connection: `redis.ashchan.local:6379`
- Pub/sub for WebSocket fan-out.

### Object Storage (MinIO/S3)
- Media blobs with immutable hashes.
- Endpoint: `minio.ashchan.local:9000`
- Used by Media/Uploads service.

## Network Architecture

### ServiceMesh Network (`ashchan-mesh`)
- Subnet: `10.90.0.0/24`
- DNS domain: `ashchan.local`
- All service-to-service communication via mTLS on port 8443

### Public Network (`ashchan-public`)
- Subnet: `10.90.1.0/24`
- API Gateway only
- Public HTTP/HTTPS traffic

### DNS-Based Service Discovery

Services are addressed by DNS name:

| Service | DNS Name | Internal URL |
|---------|----------|--------------|
| API Gateway | `gateway.ashchan.local` | `https://gateway.ashchan.local:8443` |
| Auth/Accounts | `auth.ashchan.local` | `https://auth.ashchan.local:8443` |
| Boards/Threads/Posts | `boards.ashchan.local` | `https://boards.ashchan.local:8443` |
| Media/Uploads | `media.ashchan.local` | `https://media.ashchan.local:8443` |
| Search/Indexing | `search.ashchan.local` | `https://search.ashchan.local:8443` |
| Moderation/Anti-Spam | `moderation.ashchan.local` | `https://moderation.ashchan.local:8443` |

## Eventing and Async

### Domain Events
- Emitted by core services via Redis streams.
- Consumers for indexing, moderation, and cache invalidation.
- Retry with dead-letter queues for failed consumers.

### Event Schema
- Defined in `contracts/events/README.md`
- CloudEvents-compatible format.

## Caching Strategy

### Thread Pages
- Cached with write-through invalidation.
- Per-board and catalog caches with TTL.
- Stampede protection via distributed locks.

### Cache Invalidation
- Events trigger cache invalidation.
- Redis pub/sub for real-time updates.

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
- Certificates signed by internal CA (`ashchan-ca`).
- TLS 1.3 minimum, strong cipher suites only.
- See [docs/SERVICEMESH.md](docs/SERVICEMESH.md) for details.

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

### Rate Limiting & DDoS Protection
- Edge WAF (Cloudflare, AWS Shield) for production.
- Gateway-level rate limits.
- Per-service circuit breakers.

## Deployment

### Development (Rootless Podman)
- Podman Compose for local development.
- mTLS certificates generated locally.
- DNS-based service discovery via Podman network.

### Production
- Rootless Podman on hardened hosts.
- Centralized CA (Vault or HSM).
- Multi-host networking via WireGuard/VXLAN.
- Systemd for service management.

### Not Kubernetes
- No K8s manifests or operators.
- Simpler deployment model.
- Full control over networking and security.

## Service Identity

Each service has a unique identity based on DNS names and X.509 certificates:

| Service | Certificate CN | Subject Alternative Names |
|---------|----------------|---------------------------|
| API Gateway | `gateway.ashchan.local` | `gateway.ashchan.local`, `gateway` |
| Auth/Accounts | `auth.ashchan.local` | `auth.ashchan.local`, `auth` |
| Boards/Threads/Posts | `boards.ashchan.local` | `boards.ashchan.local`, `boards` |
| Media/Uploads | `media.ashchan.local` | `media.ashchan.local`, `media` |
| Search/Indexing | `search.ashchan.local` | `search.ashchan.local`, `search` |
| Moderation/Anti-Spam | `moderation.ashchan.local` | `moderation.ashchan.local`, `moderation` |

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
