# Workspace Context - abrookstgz

## Overview

This workspace contains **three imageboard software projects**:

1. **ashchan** - A high-performance, privacy-first microservices imageboard (primary active project)
2. **OpenYotsuba** - 4chan's production system (reference for feature porting)
3. **vichan** - Archived in `vichan.zip` (legacy PHP imageboard, scaling reference)

---

## Projects

### 1. ashchan (`./ashchan/`) ⭐ PRIMARY

A modern, microservices-based imageboard built on **Hyperf** (PHP/Swoole) designed for horizontal scalability and strong abuse resistance.

**Tech Stack:**
- PHP 8.x with Hyperf framework (Swoole/OpenSwoole runtime)
- PostgreSQL (primary database with connection pooling)
- Redis (caching, queues, pub/sub, rate limiting)
- MinIO/S3 (object storage for media)
- Kubernetes (production deployment)
- Podman Compose (local development)

**Architecture:**
```
┌─────────────────┐
│   API Gateway   │ (Port 9501) - Routing, auth, rate limiting
└────────┬────────┘
         │
    ┌────┴────────────────────────────────────────┐
    │                                             │
┌───▼─────────┐ ┌───────────────┐ ┌─────────────┐
│Auth/Accounts│ │Boards/Threads │ │ Media/Upload│
│  (Port 9502)│ │ (Port 9503)   │ │ (Port 9504) │
└─────────────┘ └───────────────┘ └─────────────┘
┌───────────────┐ ┌─────────────────────────────┐
│Search/Indexing│ │Moderation/Anti-Spam         │
│ (Port 9505)   │ │ (Port 9506) - Staff UI @ /staff
└───────────────┘ └─────────────────────────────┘
```

**Services:**
| Service | Port | Purpose |
|---------|------|---------|
| `api-gateway` | 9501 | Request routing, auth, rate limiting, WebSocket |
| `auth-accounts` | 9502 | Anonymous identity, registered accounts, consents |
| `boards-threads-posts` | 9503 | Core domain: boards, threads, posts |
| `media-uploads` | 9504 | Signed uploads, hashing, deduplication, thumbnails |
| `search-indexing` | 9505 | Search backend (consumes domain events) |
| `moderation-anti-spam` | 9506 | Risk scoring, reports, bans, staff interface |

**Quick Start (Local Development):**
```bash
cd ashchan

# Copy .env files to all services
make install
# Or manually:
for svc in api-gateway auth-accounts boards-threads-posts media-uploads search-indexing moderation-anti-spam; do
  cp services/$svc/.env.example services/$svc/.env
done

# Start all services (PostgreSQL, Redis, MinIO, + 6 services)
podman-compose up -d

# Check health
curl http://localhost:9501/health

# Access staff interface
open http://localhost:9501/staff

# Run migrations
cd services/moderation-anti-spam
php bin/hyperf.php db:migrate
php bin/hyperf.php db:seed
```

**Kubernetes Deployment:**
```bash
# Dev overlay
kubectl apply -k k8s/overlays/dev

# Check pods
kubectl get pods -n ashchan
```

**Makefile Commands:**
```bash
make install    # Copy .env.example to .env for all services
make up         # Start podman-compose
make down       # Stop all services
make logs       # Tail logs from all services
make migrate    # Run database migrations
make test       # Run all service tests
make lint       # Lint all PHP code
```

**Key Documentation:**
| File | Description |
|------|-------------|
| `docs/architecture.md` | System architecture, service boundaries |
| `docs/system-design.md` | Request flows, caching, failure isolation |
| `docs/security.md` | mTLS, secrets, encryption, audit logging |
| `docs/compliance.md` | COPPA, CCPA, GDPR readiness |
| `docs/anti-spam.md` | Rate limiting, risk scoring, quarantine |
| `contracts/openapi/README.md` | API contracts per service |
| `contracts/events/README.md` | Event schemas (domain events) |
| `db/README.md` | Database migrations |
| `k8s/README.md` | Kubernetes manifests with Kustomize |

**Completed Features:**
- ✅ Full microservices architecture scaffolded
- ✅ Database migrations for all services
- ✅ OpenAPI contracts defined
- ✅ Event schemas (post-created, thread-created, media-ingested, moderation-decision)
- ✅ Podman Compose for local dev
- ✅ Kubernetes manifests (base + overlays)
- ✅ **Moderation system ported from OpenYotsuba** (reports, bans, staff UI)
- ✅ Staff web interface at `/staff` (dashboard, report queue, ban management)

**Next Steps:**
1. Implement domain logic in services
2. Event publishing/consumption between services
3. Integration tests
4. Staff authentication middleware (currently testing mode)
5. Frontend UI enhancements (real-time updates, image preview)

---

### 2. OpenYotsuba (`./OpenYotsuba/`) 📚 REFERENCE

4chan's production system (from April 2025 source leak), used as reference for porting features to ashchan.

**Tech Stack:**
- PHP 8.4
- MySQL/MariaDB
- Server-rendered HTML templates
- jQuery frontend

**Key Features (Ported to Ashchan):**
- ✅ Reports system with weighted categories
- ✅ 9 canonical ban templates (CP, illegal, spam, advertising, etc.)
- ✅ Janitor → Mod approval workflow
- ✅ Report queue prioritization logic
- ✅ Abuse detection via cleared reports
- ✅ Staff web interface (dashboard, report queue, template management)

**Port Documentation:**
| File | Description |
|------|-------------|
| `PORT_SUMMARY.md` | High-level port overview |
| `PORT_COMPLETE.md` | Executive summary |
| `COMPLETE_PORT_SUMMARY.md` | Full port details (59 files created) |
| `OPENYOTSUBA_DATA_PORT.md` | Exact data reference (report categories, ban templates) |

**Port Status:** ✅ Complete (Backend API + Staff Web Interface)

**Ported Data (Exact from 4chan):**
- Report Category ID 31: "This post violates applicable law." (weight: 1000)
- 9 Ban Templates:
  1. Child Pornography (Explicit Image) - zonly permanent
  2. Child Pornography (Non-Explicit Image) - zonly permanent
  3. Child Pornography (Links) - zonly permanent
  4. Illegal Content - zonly permanent
  5. NSFW on Blue Board - 1 day global
  6. False Reports - warning
  7. Ban Evasion - permanent global
  8. Spam - 1 day global + delete all
  9. Advertising - 1 day global + delete all

---

### 3. vichan (Archived in `vichan.zip`) 📦 LEGACY

A modernized fork of Tinyboard, a lightweight PHP imageboard. Referenced for scaling strategies.

**Tech Stack:**
- PHP 8.3+ (mbstring, gd, pdo, iconv)
- MySQL/MariaDB
- Redis/APCu/Memcached
- Twig templates
- Flysystem (filesystem abstraction)

**Key Features:**
- Static page generation (`smart_build.php`)
- 4chan-compatible JSON API
- Moderation panel (`mod.php`)
- Podman & Kubernetes support

**Scaling Reference:**
See `SCALING_PLAN.md` for strategy to scale to 4chan-level traffic:
- S3/MinIO for media storage
- Database sharding by board
- Queue-based async processing
- Varnish/micro-caching for read optimization
- Elasticsearch for search

**Bottlenecks Identified:**
- `inc/functions.php::buildIndex()` - Rebuilds entire HTML on every post
- `inc/bans.php` - Complex IP logic in PHP
- Blocking thumbnail generation during POST

---

## Key Documentation Files

| File | Description |
|------|-------------|
| `README-cloudshell.txt` | Google Cloud Shell welcome info |
| `GEMINI.md` | Quick reference for AI assistants |
| `SCALING_PLAN.md` | Strategy for scaling vichan to 4chan-level traffic |
| `MODERNIZATION_LOG.md` | Progress tracking for vichan modernization |
| `PORT_SUMMARY.md` | OpenYotsuba → Ashchan port summary |
| `PORT_COMPLETE.md` | Port executive summary |
| `COMPLETE_PORT_SUMMARY.md` | Full port documentation |
| `OPENYOTSUBA_DATA_PORT.md` | Exact data reference from 4chan |

---

## Development Conventions

### PHP Code Style
- PSR-12 compliance
- Type hints required (`declare(strict_types=1);`)
- PHPStan static analysis
- Conventional commits: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`

### Database
- PostgreSQL with migrations (ashchan)
- One table per migration file
- `up()` and `down()` methods required
- Seeders for default data

### API Design
- OpenAPI contracts in `contracts/openapi/`
- Versioned endpoints (`/api/v1/...`)
- JSON responses
- Domain events for cross-service communication

### Security
- mTLS between services (ashchan)
- Environment variables for secrets (`.env`)
- IP encryption at rest (planned)
- Content-Security-Policy headers
- Rate limiting at gateway

---

## Common Tasks

### ashchan
```bash
cd ashchan

# Start local development
make install && make up

# Run migrations
cd services/moderation-anti-spam
php bin/hyperf.php db:migrate
php bin/hyperf.php db:seed

# Run tests
make test

# Lint code
make lint

# View logs
make logs

# Kubernetes deploy
kubectl apply -k k8s/overlays/dev
```

### OpenYotsuba (Reference Only)
```bash
cd OpenYotsuba
# Read-only reference for porting features
# See board/setup.php for canonical data
```

---

## Architecture Comparison

| Aspect | vichan | ashchan | OpenYotsuba |
|--------|--------|---------|-------------|
| Architecture | Monolithic PHP | Microservices (Hyperf) | Monolithic PHP |
| Database | MySQL/MariaDB | PostgreSQL | MySQL |
| Caching | Redis/APCu/Memcached | Redis | Limited |
| Deployment | Podman, Helm | Kubernetes, Podman | Traditional LAMP |
| Frontend | Server-rendered + jQuery→ES6 | REST API + separate frontend | Server-rendered + jQuery |
| Auth | Session-based | Token-based (planned) | Cookie-based |
| Scaling | Static file generation | Event-driven, async | Static file generation |

---

## Current Priorities

### ashchan
1. Implement domain logic in services
2. Event publishing/consumption between services
3. Integration tests
4. Staff authentication middleware
5. Frontend UI enhancements (WebSocket updates, image preview)
6. Connect to auth service for real authentication
7. Connect to boards service for live board/post data

### Documentation
1. Keep API contracts updated
2. Document event schemas
3. Update deployment runbooks

---

## References

- **Hyperf Docs:** https://hyperf.wiki/
- **4chan API:** https://github.com/4chan/4chan-API
- **vichan Wiki:** https://github.com/vichan-devel/vichan/wiki
- **vichan API:** https://github.com/vichan-devel/vichan-API/
- **PostgreSQL:** https://www.postgresql.org/docs/
- **Kubernetes:** https://kubernetes.io/docs/home/

---

## Workspace Notes

- **Environment:** Google Cloud Shell (ephemeral VM, persistent 5GB home directory)
- **Output Language:** English (per `.qwen/output-language.md`)
- **Primary Project:** ashchan (active development)
- **Reference Projects:** OpenYotsuba (feature porting), vichan (scaling reference)
