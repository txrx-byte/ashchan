# Ashchan — Copilot Instructions

## Architecture Overview

Ashchan is a privacy-first imageboard running **6 Hyperf 3.1/Swoole microservices** on PHP-CLI (no containers). Services communicate via HTTP with optional **mTLS** on dedicated ports.

| Service | Dir | HTTP Port | Purpose |
|---------|-----|-----------|---------|
| api-gateway | `services/api-gateway/` | 9501 | Routing, SSR, rate limiting, staff UI, media proxy |
| auth-accounts | `services/auth-accounts/` | 9502 | Anonymous/registered identity, consents |
| boards-threads-posts | `services/boards-threads-posts/` | 9503 | Core domain: boards, threads, posts |
| media-uploads | `services/media-uploads/` | 9504 | Upload handling, hashing, MinIO/S3 storage |
| search-indexing | `services/search-indexing/` | 9505 | Search backend, event-driven indexing |
| moderation-anti-spam | `services/moderation-anti-spam/` | 9506 | Risk scoring, moderation queue, bans |

**Request flow:** nginx (80/443) → Anubis (8080) → API Gateway (9501) → backend services. The gateway uses `ProxyClient` (cURL-based, `services/api-gateway/app/Service/ProxyClient.php`) to forward requests with mTLS support. Route resolution is in `GatewayController::ROUTE_MAP`.

**Data stores:** PostgreSQL 16+ (shared DB `ashchan`), Redis 7+ (each service uses a separate `REDIS_DB`), MinIO/S3 for media blobs.

**Async events:** Domain events via Redis streams (`contracts/events/`). CloudEvents-compatible envelope: `{id, type, occurred_at, payload}`.

## Developer Workflow

```bash
make deps          # Install composer deps for all services
make bootstrap     # Full setup: deps + certs + migrate + seed
make up / make down  # Start/stop all services
make start-boards  # Start individual service (gateway|auth|boards|media|search|moderation)
make health        # Health-check all services on /health endpoints
make status        # Show running services and ports
make clean         # Clear runtime caches
```

**Run tests:** `composer test` (root-level, runs boards-threads-posts PHPUnit suite) or `cd services/<svc> && composer test`.

**Static analysis:** Each service runs PHPStan at **level 10** (maximum): `cd services/<svc> && composer phpstan`. Bootstrap files (`phpstan-bootstrap.php`) define Swoole constants.

**Database:** `make migrate` runs `db/install.sql`; `make seed` runs `db/seed.sql`. Direct `psql` with env defaults `DB_HOST=localhost`, `DB_USER=ashchan`, `DB_PASSWORD=ashchan`.

## Code Conventions

### Service Structure
Each service under `services/<name>/` follows:
```
app/Controller/   # HTTP controllers (final class, constructor injection)
app/Service/      # Business logic layer
app/Model/        # Hyperf ORM models (Eloquent-compatible)
config/autoload/  # PHP array config (server.php, databases.php, middlewares.php, redis.php)
config/routes.php # Route definitions
bin/hyperf.php    # Swoole entry point
```

### Controllers
- Always `final class` with constructor DI
- Return `Psr\Http\Message\ResponseInterface` via `$this->response->json([...])->withStatus(code)`
- Input via `RequestInterface`: `$request->all()`, `$request->query()`, `$request->input()`, `$request->file()`
- Two routing styles coexist: file-based (`Router::get(...)` in `config/routes.php`) and annotation-based (`#[Controller]`, `#[RequestMapping]`)
- Manual input validation (no form request objects)

### Models
- Extend `Hyperf\DbConnection\Model\Model`
- `@property` PHPDoc annotations for all columns (PHPStan + IDE support)
- Explicit `$fillable` arrays; `$casts` for type coercion
- Accessor pattern: `get{Attribute}Attribute()` for computed properties
- Table naming: lowercase, plural, snake_case (`staff_users`, `admin_audit_log`)

### Database Schema
- UUIDs (`gen_random_uuid()`) for user-facing PKs; `BIGSERIAL`/`SERIAL` for internal tables
- Timestamps: `created_at TIMESTAMPTZ NOT NULL DEFAULT now()`
- Boolean columns: always `is_` prefix (`is_active`, `is_locked`, `is_anonymous`)
- JSONB for flexible data (`ban_status`, `metadata`); `TEXT[]` for multi-value fields
- Index naming: `idx_{table}_{column}`
- FK cascades: `ON DELETE CASCADE`

### Middleware (Gateway)
Global stack in `services/api-gateway/config/autoload/middlewares.php`:
`SecurityHeadersMiddleware → CorsMiddleware → RateLimitMiddleware → AuthMiddleware → StaffAuthMiddleware`

Rate limiting uses Redis sorted-set sliding window (120 req/60s default). Staff context stored in `Hyperf\Context\Context`.

### Caching
- Redis `setex` with TTLs (300s threads, 60s catalogs)
- Graceful fallback: always `try/catch` around Redis operations
- Disabled in `APP_ENV=local`

## API Contracts
- **OpenAPI specs:** `contracts/openapi/*.yaml` (OpenAPI 3.0.3, per-service)
- **Event schemas:** `contracts/events/*.json` (JSON Schema draft 2020-12)
- **4chan-compatible API:** Read-only egress in exact 4chan format via `FourChanApiService` (see `docs/FOURCHAN_API.md`)

## Frontend
Server-side rendered via Jinja2/Twig templates in `frontend/templates/`. Base layout uses `{% block content %}` inheritance. Staff-only elements gated by `{% if is_staff %}`. Static assets in `frontend/static/{css,js,img}/`.

## Environment Configuration
Each service has `.env.example` with `__PLACEHOLDER__` tokens. Key categories:
- `HTTP_PORT`, `MTLS_PORT` — per-service ports
- `DB_*` — shared PostgreSQL connection
- `REDIS_DB` — service-specific Redis database number (0=gateway, 2=boards, 3=media)
- `*_SERVICE_URL` — inter-service mTLS URLs
- `MTLS_ENABLED`, `MTLS_CERT_FILE`, `MTLS_KEY_FILE`, `MTLS_CA_FILE` — certificate paths

## Key Files
- `Makefile` — All dev/ops commands
- `services/api-gateway/config/routes.php` — Complete route map (~240 lines)
- `services/api-gateway/app/Service/ProxyClient.php` — Inter-service HTTP forwarding
- `services/boards-threads-posts/app/Service/BoardService.php` — Core domain logic (~1139 lines)
- `db/install.sql` — Full database schema
- `config/nginx/nginx.conf` — Production reverse proxy with rate limiting
- `docs/architecture.md` — System architecture details
- `docs/SERVICEMESH.md` — mTLS certificate management
