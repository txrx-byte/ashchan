# Architecture

## Goals
- High throughput, low latency for read-heavy workloads.
- Horizontal scalability for traffic spikes.
- Strong isolation between services and data domains.
- Privacy-first design with minimal data retention.
- Operational safety with clear failure boundaries.

## Non-Goals
- Monolithic deployment.
- Tight coupling between services.
- User tracking beyond operational security needs.

## High-Level Topology
- API Gateway fronts all public HTTP traffic and enforces auth, rate limits, and routing.
- Core domain services expose internal HTTP + async event interfaces.
- Shared infra: PostgreSQL, Redis, object storage, and a log pipeline.

## Service Boundaries
### API Gateway
- Request routing, versioning, token verification, per-route rate limits.
- WebSocket upgrade ready for live thread updates.

### Auth/Accounts
- Anonymous identity issuance and optional registered accounts.
- Consents and compliance flags (age gate, legal holds).

### Boards/Threads/Posts
- Canonical source of truth for boards, threads, posts, and metadata.
- Emits domain events for indexing, moderation, and cache invalidation.

### Media/Uploads
- Signed upload URLs and ingest validation.
- Media hashing, deduplication, and metadata extraction.

### Search/Indexing
- Consumes domain events and updates search backend.
- Read-optimized query API for the gateway.

### Moderation/Anti-spam
- Risk scoring, throttling policies, and enforcement actions.
- Human moderation queue and audit trail.

## Data Stores
- PostgreSQL: durable domain data and compliance logs.
- Redis: cache, rate limits, queues, and distributed locks.
- Object storage: media blobs with immutable hashes.

## Eventing and Async
- Domain events emitted by core services.
- Consumers for indexing, moderation, and cache invalidation.
- Retry with dead-letter queues for failed consumers.

## Caching Strategy
- Thread pages cached with write-through invalidation.
- Per-board and catalog caches with TTL and stampede protection.

## WebSockets Readiness
- Gateway uses pub/sub fan-out from Posts events.
- Backpressure and per-connection rate limits.

## Observability
- Structured logs with correlation IDs.
- Tracing spans across gateway and services.
- Metrics for rate limits, queues, and moderation actions.

## Security
- mTLS between services.
- Least-privilege service accounts and secrets rotation.
- Input validation and strict content sanitization.

## Deployment Targets
- Rootless Podman for local development.
- Kubernetes manifests for production with overlays.
