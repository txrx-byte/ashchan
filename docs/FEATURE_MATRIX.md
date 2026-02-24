# Comparative Feature Matrix

> **4chan** · **meguca** · **vichan** · **Ashchan**

Last updated: 2026-02-24

---

## Overview

| | 4chan | meguca | vichan | Ashchan |
|---|---|---|---|---|
| **License** | Proprietary | GPLv3 | Tinyboard + vichan license | Apache 2.0 |
| **Language** | Proprietary (PHP) | Go + TypeScript | PHP | PHP 8.2+ (Hyperf/Swoole) |
| **Architecture** | Monolith | Monolith | Monolith | Microservices (6 services) |
| **Database** | MySQL | PostgreSQL | MySQL/PostgreSQL | PostgreSQL 16+ |
| **Runtime** | Apache/nginx + PHP-FPM | Native Go binary | Apache/nginx + PHP-FPM | PHP-CLI via Swoole (no FPM) |
| **Open Source** | No | Yes | Yes | Yes |
| **Active Development** | Yes (closed) | Archived | Community forks | Yes |

---

## Core Imageboard Features

| Feature | 4chan | meguca | vichan | Ashchan |
|---|:---:|:---:|:---:|:---:|
| Boards | ✅ Admin-created | ✅ User-created | ✅ Admin/user-created | ✅ Admin-created |
| Threads & replies | ✅ | ✅ | ✅ | ✅ |
| Bump ordering | ✅ | ✅ | ✅ | ✅ |
| Bump limit | ✅ (per board) | ✅ | ✅ | ✅ (per board, configurable) |
| Image limit | ✅ (per board) | ✅ | ✅ | ✅ (per board, configurable) |
| Thread catalog | ✅ | ✅ | ✅ | ✅ |
| Thread archive | ✅ (select boards) | ❌ | ✅ (plugin) | ✅ |
| Sticky threads | ✅ | ✅ | ✅ | ✅ |
| Locked threads | ✅ | ✅ | ✅ | ✅ |
| Sage | ✅ | ✅ | ✅ | ✅ |
| Tripcodes | ✅ | ✅ | ✅ | ✅ |
| Capcodes | ✅ | ✅ | ❌ | ✅ |
| Poster IDs | ✅ (select boards) | ✅ | ✅ (plugin) | ✅ (per board) |
| Country flags | ✅ (select boards) | ✅ | ✅ (plugin) | ✅ (per board, GeoIP) |
| Subject field | ✅ | ✅ | ✅ | ✅ |
| Post deletion (user) | ✅ (password) | ❌ | ✅ | ✅ (password hash) |
| Text-only boards | ✅ | ❌ | ✅ | ✅ |
| NSFW board flag | ✅ | ✅ (ws_board) | ❌ | ✅ |
| Staff-only boards | ❌ | ❌ | ❌ | ✅ |
| Per-board post numbering | ✅ | ✅ | ✅ | ✅ (atomic counter) |
| Custom board rules | ✅ | ✅ | ✅ | ✅ |
| Board categories | ✅ | ✅ | ❌ | ✅ |

---

## Real-Time Features

| Feature | 4chan | meguca | vichan | Ashchan |
|---|:---:|:---:|:---:|:---:|
| Auto-update threads | ✅ (polling) | ✅ (WebSocket) | ✅ (polling/plugin) | ✅ (WebSocket) |
| **Liveposting** (char-by-char) | ❌ | ✅ | ❌ | ✅ (ported from meguca) |
| Open/editing posts | ❌ | ✅ | ❌ | ✅ |
| Post reclamation on reconnect | ❌ | ✅ | ❌ | ✅ |
| WebSocket native support | ❌ | ✅ (Gorilla) | ❌ | ✅ (Swoole native) |
| **NekotV** (synced video player) | ❌ | ✅ | ❌ | ✅ (ported from meguca) |
| Message buffering / batching | ❌ | ✅ (100ms flush) | ❌ | ✅ (100ms flush) |
| Backpressure / per-conn rate limit | ❌ | Partial | ❌ | ✅ |
| Graceful degradation to polling | N/A | ❌ | N/A | ✅ |

---

## Media

| Feature | 4chan | meguca | vichan | Ashchan |
|---|:---:|:---:|:---:|:---:|
| Image upload (JPEG, PNG, GIF) | ✅ | ✅ | ✅ | ✅ |
| WebM/MP4 video | ✅ | ✅ (ffmpeg) | ✅ (plugin) | ✅ |
| Thumbnails | ✅ | ✅ | ✅ | ✅ |
| Spoiler images | ✅ | ✅ | ✅ | ✅ |
| Duplicate detection (hash) | ✅ (MD5) | ✅ | ✅ (MD5) | ✅ (SHA-256 + pHash) |
| NSFW auto-detection | ❌ | ❌ | ❌ | ✅ (nsfw_flagged) |
| EXIF stripping | ✅ | ✅ | ✅ | ✅ |
| Object storage backend | Custom | Local filesystem | Local filesystem | ✅ MinIO/S3 |
| Media deduplication | ✅ | ✅ | ✅ | ✅ (content-addressable) |
| Image blacklisting | ✅ | ❌ | ❌ | ✅ (MD5 + pHash) |
| CDN integration | ✅ (own CDN) | ❌ | ❌ | ✅ (Cloudflare CDN) |

---

## Moderation & Anti-Spam

| Feature | 4chan | meguca | vichan | Ashchan |
|---|:---:|:---:|:---:|:---:|
| Staff roles/hierarchy | ✅ (Admin/Mod/Janitor) | ✅ (Board owner/Mod) | ✅ (Admin/Mod) | ✅ (Admin/Manager/Mod/Janitor) |
| Report queue | ✅ | ✅ | ✅ | ✅ (weighted, categorized) |
| Report categories | ✅ | ❌ | ❌ | ✅ (per-board, weighted) |
| Ban system | ✅ | ✅ | ✅ | ✅ (local + global) |
| Ban templates | ✅ | ❌ | ❌ | ✅ |
| Ban appeals | ✅ | ❌ | ✅ | ✅ |
| IP range bans (CIDR) | ✅ | ✅ | Partial | ✅ |
| Shadow banning | ❌ | ❌ | ❌ | ✅ |
| Post filters (word/regex) | ✅ | ✅ | ✅ | ✅ |
| Autopurge rules | ✅ | ❌ | ❌ | ✅ |
| Ban requests (janitor → mod) | ✅ | ❌ | ❌ | ✅ |
| CAPTCHA | ✅ (reCAPTCHA) | ✅ (custom) | ✅ (various) | ✅ (escalating) |
| Rate limiting | ✅ | ✅ | ✅ (basic) | ✅ (sliding window, multi-layer) |
| StopForumSpam integration | ❌ | ❌ | ❌ | ✅ |
| Spur IP intelligence | ❌ | ❌ | ❌ | ✅ (VPN/proxy detection) |
| Risk scoring (heuristic) | Proprietary | ❌ | ❌ | ✅ |
| Content fingerprinting | ❌ | ❌ | ❌ | ✅ (near-duplicate detection) |
| Quarantine queue | ❌ | ❌ | ❌ | ✅ (high-risk → human review) |
| Honeyboard traps | ❌ | ❌ | ❌ | ✅ |
| Staff audit log | ✅ | Partial | ✅ | ✅ (immutable, comprehensive) |
| DMCA notice tracking | ✅ (internal) | ❌ | ❌ | ✅ (full workflow) |
| Blotter / announcements | ✅ | ❌ | ❌ | ✅ |
| Mod tools in thread view | ✅ | ✅ | ✅ | ✅ (inline, staff-gated) |
| Janitor stats tracking | ✅ | ❌ | ❌ | ✅ |
| Runtime feature toggles | ❌ | ❌ | ❌ | ✅ (site_settings, audit-logged) |

---

## Staff Interface

| Feature | 4chan | meguca | vichan | Ashchan |
|---|:---:|:---:|:---:|:---:|
| Admin dashboard | ✅ | ✅ (basic) | ✅ | ✅ |
| Inline moderation (in-thread) | ✅ | ✅ | ✅ | ✅ |
| Staff login with session management | ✅ | ✅ | ✅ | ✅ (concurrent session limits) |
| Account lockout on failed login | ✅ | ❌ | ❌ | ✅ |
| CSRF protection | ✅ | ✅ | ✅ | ✅ (token-based) |
| IP whitelist for staff | ❌ | ❌ | ❌ | ✅ (optional per-user) |
| 2FA support | ❌ | ❌ | ❌ | ✅ (optional, per-user) |
| Security settings per staff user | ❌ | ❌ | ❌ | ✅ |
| Site messages / global announcements | ✅ | ❌ | ✅ | ✅ (scheduled, per-board) |
| Staff notes | ✅ | ❌ | ❌ | ✅ |

---

## Architecture & Performance

| Feature | 4chan | meguca | vichan | Ashchan |
|---|:---:|:---:|:---:|:---:|
| Event-driven / async | ❌ (PHP-FPM) | ✅ (Go goroutines) | ❌ (PHP-FPM) | ✅ (Swoole coroutines) |
| Persistent connections | ❌ | ✅ | ❌ | ✅ (Swoole keep-alive) |
| Microservices | ❌ | ❌ | ❌ | ✅ (6 services) |
| Domain events / event bus | ❌ | Partial (channels) | ❌ | ✅ (Redis Streams, CloudEvents) |
| Service mesh (mTLS) | ❌ | ❌ | ❌ | ✅ (X.509, TLS 1.3) |
| Multi-layer caching | ✅ (Varnish + CDN) | ✅ (in-memory) | ❌ | ✅ (Cloudflare + Varnish + Redis) |
| Cache invalidation (event-driven) | Proprietary | ❌ | ❌ | ✅ (Redis Streams → Varnish BAN) |
| Static binary build | ❌ | ✅ (Go binary) | ❌ | ✅ (static-php-cli) |
| Horizontal scalability | Proprietary | Limited | ❌ | ✅ (designed for) |
| Circuit breakers | Proprietary | ❌ | ❌ | ✅ (per-service) |
| Failure isolation | Proprietary | ❌ | ❌ | ✅ (service boundaries) |
| Connection pooling (DB) | ✅ | ✅ | ❌ | ✅ (per-service pools) |
| Concurrent connection capacity | Unknown | ~10k | ~1k (FPM workers) | 100k+ (Swoole) |

---

## API & Integration

| Feature | 4chan | meguca | vichan | Ashchan |
|---|:---:|:---:|:---:|:---:|
| JSON API | ✅ | ✅ (custom) | ✅ (custom) | ✅ (internal + 4chan-compat) |
| **4chan-compatible API** | ✅ (canonical) | ❌ | Partial | ✅ (exact format, read-only) |
| OpenAPI specifications | ❌ | ❌ | ❌ | ✅ (per-service) |
| Event schemas (JSON Schema) | ❌ | ❌ | ❌ | ✅ (CloudEvents) |
| Third-party client support | ✅ (native) | Custom clients | Partial | ✅ (via 4chan-compat API) |
| Webhook / event integration | ❌ | ❌ | ❌ | ✅ (Redis Streams) |
| Admin API (programmatic) | ❌ | Partial | ❌ | ✅ (RESTful) |

---

## Security

| Feature | 4chan | meguca | vichan | Ashchan |
|---|:---:|:---:|:---:|:---:|
| TLS termination | ✅ (Cloudflare) | ✅ (reverse proxy) | ✅ (reverse proxy) | ✅ (Cloudflare + mTLS mesh) |
| Zero public IP exposure | ❌ | ❌ | ❌ | ✅ (Cloudflare Tunnel) |
| Proof-of-work (bot mitigation) | ❌ | ❌ | ❌ | ✅ (Anubis PoW) |
| WAF | ✅ (Cloudflare) | ❌ | ❌ | ✅ (Cloudflare WAF) |
| DDoS protection | ✅ (Cloudflare) | ❌ | ❌ | ✅ (Cloudflare + rate limiting) |
| Content Security Policy | ✅ | Partial | ❌ | ✅ |
| Security headers (HSTS, X-Frame, etc.) | ✅ | Partial | Partial | ✅ (middleware stack) |
| mTLS service-to-service | N/A (monolith) | N/A | N/A | ✅ (ECDSA P-256, TLS 1.3) |
| Input validation / sanitization | ✅ | ✅ | ✅ | ✅ (gateway + service) |
| IP hashing (privacy) | ❌ (stores raw) | ❌ (stores raw) | ❌ (stores raw) | ✅ (SHA-256 hash by default) |
| PII encryption at rest | ❌ | ❌ | ❌ | ✅ (XChaCha20-Poly1305) |
| Certificate rotation tooling | N/A | N/A | N/A | ✅ (automated scripts) |
| Firewall hardening guide | Proprietary | ❌ | ❌ | ✅ (Linux + FreeBSD) |

---

## Privacy & Compliance

| Feature | 4chan | meguca | vichan | Ashchan |
|---|:---:|:---:|:---:|:---:|
| GDPR compliance | Partial | ❌ | ❌ | ✅ (built-in) |
| CCPA compliance | Partial | ❌ | ❌ | ✅ (built-in) |
| Data Subject Requests (export/delete) | Manual | ❌ | ❌ | ✅ (automated workflow) |
| Consent tracking (versioned) | ❌ | ❌ | ❌ | ✅ (policy version + timestamp) |
| Automated IP retention / purge | ❌ | ❌ | ❌ | ✅ (30-day posts, 90-day reports) |
| PII decryption audit trail | ❌ | ❌ | ❌ | ✅ (pii_access_log) |
| Data inventory documentation | ❌ | ❌ | ❌ | ✅ |
| Minimal data collection | ❌ | Partial | ❌ | ✅ (by design) |
| Right to erasure | Manual | ❌ | ❌ | ✅ (soft delete → purge) |

---

## Deployment & Operations

| Feature | 4chan | meguca | vichan | Ashchan |
|---|:---:|:---:|:---:|:---:|
| Docker support | N/A | ✅ | ✅ | ❌ (native PHP-CLI by design) |
| Systemd integration | Unknown | ❌ | ❌ | ✅ |
| Makefile automation | N/A | ✅ | ❌ | ✅ (comprehensive) |
| Health check endpoints | Unknown | ❌ | ❌ | ✅ (/health per service) |
| Database migrations | Unknown | ✅ | ✅ | ✅ (install.sql + seed.sql) |
| Static analysis (PHPStan) | Unknown | N/A (Go) | ❌ | ✅ (Level 10 maximum) |
| Structured logging (JSON) | Unknown | ❌ | ❌ | ✅ |
| Distributed tracing | Unknown | ❌ | ❌ | ✅ (correlation IDs) |
| Certificate status monitoring | N/A | N/A | N/A | ✅ (make mtls-status) |
| Static binary distribution | N/A | ✅ (Go) | ❌ | ✅ (static-php-cli) |

---

## Frontend

| Feature | 4chan | meguca | vichan | Ashchan |
|---|:---:|:---:|:---:|:---:|
| Server-side rendering | ✅ | ✅ (Go templates) | ✅ (PHP templates) | ✅ (Twig templates) |
| Themes / style switcher | ✅ | ✅ | ✅ | ✅ |
| Mobile responsive | ✅ | ✅ | Partial | ✅ |
| JavaScript framework | Vanilla JS | TypeScript (bundled) | jQuery | Vanilla JS (no build tools) |
| Extension system | ✅ | ❌ | ✅ (plugins) | ✅ (extension.js events) |
| Inline image expansion | ✅ | ✅ | ✅ | ✅ |
| Quick reply | ✅ | ✅ (liveposting) | ✅ | ✅ |
| Post preview | ✅ | ✅ (real-time) | ✅ | ✅ |
| Keyboard shortcuts | ✅ | ✅ | ❌ | ✅ |
| User feedback form | ✅ | ❌ | ❌ | ✅ |

---

## Legend

| Symbol | Meaning |
|--------|---------|
| ✅ | Fully supported / implemented |
| ❌ | Not supported / not available |
| Partial | Partially implemented or limited |
| N/A | Not applicable to this architecture |

---

## Notes

- **4chan** is closed-source; entries are based on publicly observable behavior and the [4chan API documentation](https://github.com/4chan/4chan-API). Internal implementation details are unknown.
- **meguca** refers to the [bakape/meguca](https://github.com/bakape/meguca) codebase (archived). The fork in this repository adds TikTok UI elements.
- **vichan** refers to the [vichan-devel/vichan](https://github.com/vichan-devel/vichan) codebase and its ecosystem of plugins/forks (e.g., infinity, lainchan).
- **Ashchan** entries reflect the current codebase as of 2026-02-24. Features marked ✅ are implemented in code or schema; some may still be under integration testing.
