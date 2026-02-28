# Ashchan Agent Team — Elite Engineering Squad

**Created:** 2026-02-28  
**Mission:** Build the most advanced, privacy-first, federated imageboard platform that makes 4chan look like GeoCities

---

## Organization Structure

```
┌─────────────────────────────────────────────────────────────────┐
│                    ASHCHAN AGENT COMMAND                        │
│                         (You)                                   │
└─────────────────────────────────────────────────────────────────┘
                              │
        ┌─────────────────────┼─────────────────────┐
        │                     │                     │
┌───────▼────────┐   ┌────────▼────────┐   ┌───────▼────────┐
│  CORE PLATFORM │   │  FEDERATION     │   │  OPERATIONS    │
│    SQUAD       │   │    SQUAD        │   │    SQUAD       │
└────────────────┘   └─────────────────┘   └────────────────┘
```

---

## Agent Roster

### 🏛️ CORE PLATFORM SQUAD

#### 1. `phpstan-doc-architect` ✅ (ACTIVE)
**Specialization:** PHPStan 10 strict compliance, comprehensive documentation  
**Current Assignment:** auth-accounts service documentation complete  
**Capabilities:**
- PHPStan level 10 configuration and error resolution
- PHPDoc generation with proper type hints
- Architecture documentation (ARCHITECTURE.md, API.md, SECURITY.md)
- Type hinting guides and troubleshooting docs

**When to invoke:**
- After writing new service code
- When refactoring existing modules
- Before major releases for documentation updates

---

#### 2. `hyperf-swoole-specialist`
**Specialization:** Hyperf 3.x framework, Swoole coroutine optimization  
**Capabilities:**
- Swoole server configuration (worker_num, max_coroutine, task workers)
- Coroutine-safe code patterns and async task queues
- Connection pooling (DB, Redis, HTTP client)
- Swoole event loop optimization
- Background process design (CacheInvalidatorProcess, EventPublisher)
- WebSocket server implementation for liveposting

**When to invoke:**
- Performance tuning for high-concurrency endpoints
- Debugging coroutine context issues
- Designing async event-driven architectures
- WebSocket real-time features

---

#### 3. `domain-events-engineer`
**Specialization:** Event-driven architecture, Redis Streams, CQRS  
**Capabilities:**
- Domain event schema design (contracts/events/)
- Redis Streams pub/sub implementation
- Event sourcing patterns for post/thread state
- Cache invalidation event flows
- Dead-letter queue handling and retry logic
- Event projection for read models (search indexing)

**When to invoke:**
- Adding new domain events (post.created, thread.deleted, etc.)
- Designing async workflows (moderation scoring, search indexing)
- Implementing event-driven cache invalidation
- Building read-model projections

---

#### 4. `api-contract-architect`
**Specialization:** OpenAPI contracts, API versioning, 4chan compatibility  
**Capabilities:**
- OpenAPI 3.0 specification (contracts/openapi/)
- 4chan API compatibility layer (read-only mirror format)
- API versioning strategies (v1, v2, etc.)
- Request/response validation middleware
- Rate limiting per endpoint
- Error response standardization

**When to invoke:**
- Adding new API endpoints
- Maintaining 4chan API compatibility
- Designing public API contracts
- API documentation generation

---

### 🌐 FEDERATION SQUAD

#### 5. `activitypub-protocol-engineer`
**Specialization:** W3C ActivityPub, Fediverse interoperability  
**Capabilities:**
- ActivityPub actor/actor mapping (instance, board, thread actors)
- S2S federation protocol implementation
- HTTP Signatures for secure federation
- WebFinger for actor discovery
- Inbox/outbox activity handling
- Federation with Mastodon, Lemmy, Pleroma (compatibility layer)

**When to invoke:**
- Implementing board/thread federation
- Adding Fediverse discovery features
- Cross-posting to Mastodon/Lemmy
- Federation security hardening

---

#### 6. `matrix-federation-specialist`
**Specialization:** Matrix protocol, decentralized room synchronization  
**Capabilities:**
- Matrix-inspired event DAG for post ordering
- Causal ordering with Lamport timestamps
- State resolution algorithms for mod actions
- Backfill protocols for thread history
- Liveposting over federation (keystroke batching)
- Federation room/board mapping

**When to invoke:**
- Implementing real-time federation sync
- Designing conflict resolution for concurrent posts
- Building backfill mechanisms for new subscribers
- Liveposting federation optimization

---

#### 7. `federated-moderation-engineer`
**Specialization:** Cross-instance moderation, shared blocklists  
**Capabilities:**
- Federated report/Flag activity handling
- Instance allowlist/blocklist management
- Shared media hash blocklists (CSAM/DMCA)
- Instance reputation scoring
- Cross-instance ban propagation (opt-in)
- Spam score sharing (StopForumSpam integration)

**When to invoke:**
- Building federation-wide moderation tools
- Implementing shared blocklist infrastructure
- Designing cross-instance reporting flows
- Creating reputation-based federation

---

### 🔐 SECURITY SQUAD

#### 8. `mtls-security-engineer`
**Specialization:** mTLS service mesh, certificate lifecycle  
**Capabilities:**
- mTLS certificate generation and rotation
- Service identity management (X.509 CN/SAN)
- TLS 1.3 hardening and cipher suite configuration
- Certificate revocation (CRL/OCSP)
- Service-to-service authorization policies
- mTLS handshake monitoring and alerting

**When to invoke:**
- Setting up new services in the mesh
- Certificate rotation automation
- mTLS troubleshooting and debugging
- Security audit preparation

---

#### 9. `openbao-secrets-engineer` ✅ (JUST DEPLOYED)
**Specialization:** OpenBao/Vault secrets management, dynamic credentials  
**Capabilities:**
- OpenBao installation and configuration (dev/standalone/HA)
- Dynamic PostgreSQL/Redis credential generation
- Transit encryption for PII (key management without exposure)
- Secret rotation automation
- Audit logging for compliance (SOC 2, GDPR)
- Backup and disaster recovery procedures

**When to invoke:**
- Onboarding new services to secrets management
- Implementing automatic secret rotation
- GDPR/CCPA compliance auditing
- Emergency credential rotation

---

#### 10. `selinux-policy-architect`
**Specialization:** SELinux MAC policies, kernel-level confinement  
**Capabilities:**
- SELinux policy module creation for PHP/Swoole
- Process confinement (auth_accounts_t, gateway_t, etc.)
- Network port labeling and restrictions
- File context management (httpd_sys_content_t, httpd_log_t)
- Audit log analysis (ausearch, audit2why)
- Container SELinux (container_t, container_file_t)

**When to invoke:**
- Production hardening for compliance (PCI-DSS, SOC 2)
- Containing blast radius of potential compromises
- Kernel-level audit trail implementation
- Container security enhancement

---

#### 11. `anubis-pow-engineer`
**Specialization:** Proof-of-Work challenges, bot mitigation  
**Capabilities:**
- Anubis PoW challenge configuration
- Difficulty adjustment algorithms
- Browser integrity checks and JavaScript challenges
- Rate limiting integration with PoW
- Bot detection heuristics
- Cache pollution prevention

**When to invoke:**
- Tuning PoW difficulty for UX/security balance
- Responding to new bot attacks
- Integrating PoW with rate limiting
- Optimizing Anubis performance

---

### 📊 DATA SQUAD

#### 12. `postgresql-performance-engineer`
**Specialization:** PostgreSQL query optimization, schema design  
**Capabilities:**
- Query plan analysis and index optimization
- Connection pool tuning (pgbouncer, Hyperf pool)
- Partitioning strategies (posts by board, threads by date)
- Read replica configuration for high-traffic boards
- Full-text search optimization (tsvector/tsquery)
- Vacuum and autovacuum tuning

**When to invoke:**
- Slow query investigation
- Schema design for new features
- Scaling read-heavy workloads
- Database migration planning

---

#### 13. `redis-cache-strategist`
**Specialization:** Redis caching strategies, data structures  
**Capabilities:**
- Multi-layer cache design (L1 Varnish, L2 Redis, L3 service)
- Cache invalidation patterns (BAN, PURGE, TTL)
- Redis Streams for event bus
- Sorted set rate limiting (sliding window)
- Bitmap/posting list for user tracking (anonymous IDs)
- Lua scripting for atomic operations

**When to invoke:**
- Cache stampede prevention
- Rate limiting algorithm design
- Event bus scaling
- Real-time analytics implementation

---

#### 14. `varnish-cache-engineer`
**Specialization:** Varnish HTTP caching, VCL programming  
**Capabilities:**
- VCL configuration for board/thread caching
- Cache invalidation via BAN/PURGE
- Grace period and saint mode configuration
- Backend health checks and failover
- ESI (Edge Side Includes) for dynamic fragments
- Varnish Prometheus metrics integration

**When to invoke:**
- Cache hit ratio optimization
- Cache invalidation bug fixes
- VCL performance tuning
- Stampede protection implementation

---

#### 15. `media-dedup-engineer`
**Specialization:** Media deduplication, content-addressed storage  
**Capabilities:**
- SHA-256 content hashing for deduplication
- Perceptual hashing (pHash) for near-duplicate detection
- MinIO/S3 bucket lifecycle management
- Media proxy and thumbnail generation
- EXIF stripping and privacy sanitization
- Banned media hash propagation (CSAM/DMCA)

**When to invoke:**
- Media storage optimization
- Implementing deduplication at upload
- Building media moderation tools
- Federation media caching

---

### 🔧 DEVOPS SQUAD

#### 16. `systemd-deployment-engineer`
**Specialization:** Systemd service management, production deployments  
**Capabilities:**
- Systemd unit file creation (service, timer, path)
- Service hardening (NoNewPrivileges, ProtectSystem, etc.)
- Journal logging integration
- Service dependency management
- Automatic restart and health checks
- Resource limits (CPU, memory, file descriptors)

**When to invoke:**
- Production service deployment
- Service hardening for security
- Automated task scheduling (cert rotation, backups)
- Multi-service dependency orchestration

---

#### 17. `cloudflare-tunnel-specialist`
**Specialization:** Cloudflare Tunnel, zero-exposure ingress  
**Capabilities:**
- cloudflared configuration and tunnel management
- Zero public IP architecture
- Cloudflare WAF rule customization
- DDoS protection tuning
- CDN edge caching for static/media
- Cloudflare Workers integration (edge logic)

**When to invoke:**
- Initial tunnel setup
- DDoS response and mitigation
- Edge caching optimization
- Cloudflare WAF rule tuning

---

#### 18. `observability-engineer`
**Specialization:** Monitoring, logging, distributed tracing  
**Capabilities:**
- Structured JSON logging with correlation IDs
- Prometheus metrics export (Swoole, Redis, PostgreSQL)
- Grafana dashboard creation
- Distributed tracing (OpenTelemetry, Jaeger)
- Alerting rules (PagerDuty, Slack webhooks)
- Log aggregation (Loki, Elasticsearch)

**When to invoke:**
- Setting up monitoring for new services
- Debugging distributed system issues
- Creating operational dashboards
- Incident response tooling

---

#### 19. `ci-cd-automation-engineer`
**Specialization:** GitHub Actions, automated testing, deployment pipelines  
**Capabilities:**
- GitHub Actions workflow design
- PHP Composer dependency scanning
- Automated PHPStan, PHPUnit, cs-fix
- Static binary builds (static-php-cli)
- Deployment automation (Ansible, shell scripts)
- Release automation and changelog generation

**When to invoke:**
- Adding new CI checks
- Automating release processes
- Building deployment pipelines
- Security scanning integration

---

### 🎯 GROWTH SQUAD

#### 20. `4chan-migration-specialist`
**Specialization:** 4chan data migration, archive preservation  
**Capabilities:**
- 4chan thread/archive scraping (respectful, rate-limited)
- Thread format conversion (4chan → Ashchan)
- Media migration with hash verification
- User redirect mapping (if 4chan ever shuts down)
- Archive completeness verification
- Legal/DMCA compliance for migrated content

**When to invoke:**
- Building 4chan archive mirrors
- User migration tooling
- Thread format compatibility testing
- Legal review for content migration

---

#### 21. `ux-liveposting-engineer`
**Specialization:** Real-time UX, WebSocket optimization  
**Capabilities:**
- Swoole WebSocket server tuning
- Liveposting keystroke streaming
- Character-by-character fan-out optimization
- Backpressure handling for slow clients
- Mobile-friendly touch interfaces
- Offline-first PWA design

**When to invoke:**
- Liveposting feature development
- WebSocket scaling issues
- Mobile UX optimization
- Real-time collaboration features

---

#### 22. `privacy-compliance-counsel`
**Specialization:** GDPR, CCPA, COPPA compliance  
**Capabilities:**
- Data minimization strategies
- Consent tracking implementation
- Right to erasure (data deletion) automation
- Data export (GDPR portability) tooling
- Age verification flows (COPPA)
- Privacy impact assessments

**When to invoke:**
- New feature privacy review
- Compliance audit preparation
- Data retention policy implementation
- User rights automation

---

## Agent Invocation Patterns

### Single Agent (Focused Task)
```bash
# Example: Run PHPStan documentation agent
qwen task --agent phpstan-doc-architect --prompt "Document the boards-threads-posts service"
```

### Multi-Agent Collaboration (Complex Feature)
```bash
# Example: Build federation feature
# 1. ActivityPub protocol engineer designs actors
# 2. Matrix specialist implements event DAG
# 3. Domain events engineer creates event schemas
# 4. API contract architect writes OpenAPI specs
# 5. PHPStan doc architect documents everything
```

### Squad Review (Security Audit)
```bash
# Example: Pre-release security review
# - mtls-security-engineer: Review mTLS config
# - openbao-secrets-engineer: Audit secrets management
# - selinux-policy-architect: Verify MAC policies
# - privacy-compliance-counsel: GDPR/CCPA check
```

---

## Team Expansion Plan

### Phase 1: Core Platform (Q1 2026)
- [x] phpstan-doc-architect
- [ ] hyperf-swoole-specialist
- [ ] domain-events-engineer
- [ ] api-contract-architect

### Phase 2: Federation (Q2 2026)
- [ ] activitypub-protocol-engineer
- [ ] matrix-federation-specialist
- [ ] federated-moderation-engineer

### Phase 3: Security Hardening (Q3 2026)
- [x] openbao-secrets-engineer
- [ ] mtls-security-engineer
- [ ] selinux-policy-architect
- [ ] anubis-pow-engineer

### Phase 4: Data & Performance (Q4 2026)
- [ ] postgresql-performance-engineer
- [ ] redis-cache-strategist
- [ ] varnish-cache-engineer
- [ ] media-dedup-engineer

### Phase 5: DevOps & Growth (Q1 2027)
- [ ] systemd-deployment-engineer
- [ ] cloudflare-tunnel-specialist
- [ ] observability-engineer
- [ ] ci-cd-automation-engineer
- [ ] 4chan-migration-specialist
- [ ] ux-liveposting-engineer
- [ ] privacy-compliance-counsel

---

## Communication Protocols

### Agent Briefing Format
When invoking an agent, provide:
1. **Context:** What part of the architecture they're working on
2. **Goal:** Specific, measurable outcome
3. **Constraints:** Technical limitations, compliance requirements
4. **Artifacts:** Files to read, existing patterns to follow

### Example Briefing
```
Agent: hyperf-swoole-specialist
Context: Boards service thread creation endpoint is slow under load
Goal: Reduce p99 latency from 500ms to <100ms for POST /thread/create
Constraints: Must maintain ACID guarantees, can't sacrifice data integrity
Artifacts: Read services/boards-threads-posts/app/Controller/ThreadController.php
```

---

## Success Metrics

| Metric | Target | Current |
|--------|--------|---------|
| PHPStan Level | 10 (max) | ✅ 10 |
| Test Coverage | >80% | 🔄 In progress |
| API Latency (p99) | <100ms | 📊 Baseline needed |
| Cache Hit Ratio | >95% | 📊 Baseline needed |
| Federation Lag | <5s | ❌ Not implemented |
| Secrets Rotation | Automated | ✅ OpenBao deployed |
| Audit Compliance | SOC 2 Type II | 🔄 In progress |

---

## Agent Activation Priority

**Immediate (This Week):**
1. hyperf-swoole-specialist — Performance baseline
2. domain-events-engineer — Event bus completion
3. api-contract-architect — OpenAPI completion

**Next Week:**
4. activitypub-protocol-engineer — Federation design
5. postgresql-performance-engineer — Query optimization
6. redis-cache-strategist — Cache strategy

**This Month:**
7. mtls-security-engineer — mTLS audit
8. varnish-cache-engineer — Cache tuning
9. observability-engineer — Monitoring setup

---

**Remember:** These agents are force multipliers. Use them proactively, not reactively. The goal is to build the platform that makes 4chan an offer they can't refuse — technically superior, privacy-respecting, and federated enough to survive any single point of failure.

Let's build something legendary. 🚀
