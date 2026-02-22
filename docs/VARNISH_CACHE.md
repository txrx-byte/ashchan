# Varnish Cache Layer

## Overview

Ashchan uses [Varnish Cache](https://varnish-cache.org/) as an HTTP accelerator between Anubis and the API Gateway. Varnish caches read-heavy responses (board pages, threads, catalogs, 4chan API) in memory, dramatically reducing load on the PHP backend while serving pages in microseconds.

## Architecture Position

```
Client → Cloudflare (TLS 1.3, WAF) → Tunnel → nginx (80) → Anubis (8080) → Varnish (6081) → Gateway (9501)
                                                             PoW challenge     HTTP cache        PHP/Swoole
```

The origin server has **no public IP** — all traffic arrives via Cloudflare Tunnel.

Varnish sits **behind Anubis** intentionally:

1. **No cache pollution** — only requests that pass Anubis's proof-of-work challenge reach Varnish. Bots, scrapers, and automated abuse are blocked before they can warm or thrash the cache.
2. **Correct cache keys** — Anubis strips/normalizes headers before forwarding, so Varnish sees clean requests without bot fingerprinting noise.
3. **Reduced attack surface** — Varnish's admin interface is localhost-only and doesn't face the public internet.

## Ports

| Component | Port | Purpose |
|-----------|------|---------|
| Varnish HTTP | 6081 | Receives requests from Anubis |
| Varnish Admin | 6082 | CLI management (`varnishadm`) |
| API Gateway | 9501 | Backend origin for Varnish |

## What Gets Cached

| URL Pattern | TTL | Grace | Description |
|-------------|-----|-------|-------------|
| `/` | 30s | 60s | Home page |
| `/{slug}/` | 30s | 60s | Board index pages |
| `/{slug}/thread/{id}` | 30s | 60s | Thread view pages |
| `/{slug}/catalog` | 30s | 60s | Catalog pages |
| `/{slug}/archive` | 60s | 120s | Archive pages |
| `/api/v1/4chan/*` | 10s | 30s | 4chan-compatible API |
| `/api/v1/boards/* GET` | 15s | 30s | Public board/thread API |
| `/static/*` | 1h | 24h | Static assets (fallback) |
| `/media/*` | 30m | 1h | Media files (fallback) |

**Grace period**: Varnish serves stale content for this long while fetching a fresh copy from the backend. This prevents thundering-herd / cache stampede on expiry.

## What Is Never Cached

| Pattern | Reason |
|---------|--------|
| `POST/PUT/DELETE` | Mutating requests |
| `/staff/*` | Staff/admin pages (session-authenticated) |
| `/api/v1/auth/*` | Authentication endpoints |
| `/api/v1/media/upload*` | File upload endpoints |
| `/ws` | WebSocket connections (piped through) |
| Requests with cookies | Session-bearing requests (staff login) |
| Non-200 responses | Errors, redirects |
| Responses with `Set-Cookie` | Session establishment |

## Cache Invalidation

Varnish supports three invalidation mechanisms, all restricted to `localhost` via ACL:

### 1. BAN (Pattern-Based)

Send an HTTP `BAN` request with a pattern header to invalidate all matching URLs:

```bash
# Ban all URLs for board /b/
curl -X BAN -H "X-Ban-Board: b" http://localhost:6081/

# Ban all thread pages matching a pattern
curl -X BAN -H "X-Ban-Pattern: ^/b/thread/" http://localhost:6081/

# Ban a specific URL
curl -X BAN http://localhost:6081/b/thread/12345
```

Bans are evaluated **lazily** by Varnish's ban lurker — objects are tested against the ban list when next requested, or proactively by the lurker thread.

### 2. PURGE (Single URL)

Immediately remove a specific cached object:

```bash
curl -X PURGE http://localhost:6081/b/thread/12345
```

### 3. Gateway Integration (Automatic)

The gateway's `CacheInvalidatorProcess` (a Swoole background process consuming Redis Streams) automatically sends HTTP BAN requests to Varnish when domain events fire:

| Event | Varnish Action |
|-------|---------------|
| `thread.created` | BAN `^/{board}/` (board index + catalog) |
| `post.created` | BAN `^/{board}/thread/{id}` + `^/{board}/` |
| `moderation.decision` | BAN thread + board patterns |

This provides **sub-second cache invalidation** driven by the same Redis Streams event bus used for search indexing and moderation.

## Configuration Files

| File | Purpose |
|------|---------|
| `config/varnish/default.vcl` | VCL configuration (caching rules, TTLs, ACLs) |
| `config/varnish/varnish.params` | Daemon parameters (memory, threads, ports) |

## Installation

### Alpine Linux

```bash
apk add varnish
```

### Debian/Ubuntu

```bash
apt-get install varnish
```

### From Varnish Repository (Latest)

```bash
curl -s https://packagecloud.io/install/repositories/varnishcache/varnish75/script.deb.sh | bash
apt-get install varnish
```

## Setup

### 1. Install Configuration

```bash
# Copy VCL
sudo cp config/varnish/default.vcl /etc/varnish/default.vcl

# Copy daemon params
sudo cp config/varnish/varnish.params /etc/default/varnish

# Generate admin secret
sudo dd if=/dev/urandom of=/etc/varnish/secret count=1 bs=128 2>/dev/null
sudo chmod 600 /etc/varnish/secret
```

### 2. Update Anubis Target

Anubis must forward to Varnish instead of directly to the gateway. This is already configured in `config/anubis/env`:

```
TARGET=http://localhost:6081
```

### 3. Start Varnish

```bash
# Systemd
sudo systemctl enable --now varnish

# Manual (development)
varnishd \
  -a :6081 \
  -T 127.0.0.1:6082 \
  -f /etc/varnish/default.vcl \
  -s malloc,256M \
  -p ban_lurker_age=60 \
  -p ban_lurker_sleep=0.1
```

### 4. Verify

```bash
# Check Varnish is running
varnishadm status

# Test a request (should show X-Cache: MISS on first, HIT on second)
curl -sI http://localhost:6081/ | grep X-Cache
curl -sI http://localhost:6081/ | grep X-Cache

# Check backend health
varnishadm backend.list
```

## Systemd Unit

For production, use the system-provided unit or create one:

```ini
[Unit]
Description=Varnish HTTP accelerator (ashchan)
After=network.target

[Service]
Type=forking
EnvironmentFile=/etc/default/varnish
ExecStart=/usr/sbin/varnishd \
  -a ${VARNISH_LISTEN_ADDRESS}:${VARNISH_LISTEN_PORT} \
  -T ${VARNISH_ADMIN_LISTEN_ADDRESS}:${VARNISH_ADMIN_LISTEN_PORT} \
  -f ${VARNISH_VCL_CONF} \
  -S ${VARNISH_SECRET_FILE} \
  -s ${VARNISH_STORAGE} \
  -p thread_pools=${VARNISH_THREAD_POOLS} \
  -p thread_pool_min=${VARNISH_MIN_THREADS} \
  -p thread_pool_max=${VARNISH_MAX_THREADS} \
  -p default_ttl=${VARNISH_DEFAULT_TTL} \
  -p default_grace=${VARNISH_DEFAULT_GRACE} \
  ${VARNISH_EXTRA_PARAMS}
ExecReload=/usr/sbin/varnishadm vcl.load reload /etc/varnish/default.vcl
ExecReload=/usr/sbin/varnishadm vcl.use reload
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

## Monitoring

### Real-Time Stats

```bash
# Live hit/miss dashboard
varnishstat

# Filter specific counters
varnishstat -f MAIN.cache_hit -f MAIN.cache_miss -f MAIN.n_object

# Hit ratio
varnishstat -1 -f MAIN.cache_hit -f MAIN.cache_miss | awk '{print $2}' | paste - - | awk '{printf "Hit ratio: %.1f%%\n", $1/($1+$2)*100}'
```

### Request Log

```bash
# Live request log with cache status
varnishlog -g request -q "ReqURL ~ '^/[a-z]+/'" -i ReqURL -i RespStatus -i VCL_call

# Show only cache misses
varnishncsa -F '%{X-Cache}o %s %{Varnish:hitmiss}x %U' -q "VCL_call eq 'MISS'"
```

### Ban List

```bash
# View active bans
varnishadm ban.list

# Clear expired bans (lurker handles this automatically)
```

## Tuning

### Memory Sizing

Rule of thumb: allocate enough memory to hold your working set (frequently accessed pages).

| Traffic Level | Recommended Storage |
|---------------|-------------------|
| Development | `malloc,256M` |
| Small site (< 50 boards) | `malloc,1G` |
| Medium site (50-200 boards) | `malloc,2G` |
| High traffic | `malloc,4G` |

### Thread Tuning

```bash
# Check current thread usage
varnishstat -f MAIN.threads -f MAIN.threads_created -f MAIN.thread_queue_len
```

If `thread_queue_len` is consistently > 0, increase `VARNISH_MAX_THREADS`.

### Grace Period Strategy

Grace periods are critical for imageboard workloads:

- **Short TTL + generous grace** prevents thundering herd on popular threads
- When a cached object expires, Varnish serves stale content to concurrent requests while **one** backend fetch runs
- This is especially important for board index pages that may receive hundreds of simultaneous requests

### Backend Timeout Tuning

If the gateway is slow under load, increase timeouts in VCL:

```vcl
backend default {
    .first_byte_timeout = 30s;   # Wait longer for complex pages
    .between_bytes_timeout = 10s;
}
```

## Interaction with Existing Caching

Ashchan has a **three-tier caching strategy**:

| Layer | Technology | TTL | Location |
|-------|-----------|-----|----------|
| **L1: Varnish** | HTTP object cache | 10-60s | Between Anubis and Gateway |
| **L2: Gateway Redis** | Application cache | 60s | Gateway process (Redis DB 0) |
| **L3: Service Redis** | Domain cache | 120-600s | Backend services (Redis DB 2) |

A request for `/b/thread/12345` traverses:

1. **Varnish** — if cached and fresh, returns immediately (~0.1ms)
2. **Gateway Redis** — common data cache (boards list, blotter) — 60s TTL
3. **Service Redis** — thread data in boards-threads-posts — 300s TTL
4. **PostgreSQL** — source of truth (only on full cache miss)

Cache invalidation flows in the **opposite direction**: domain events in Redis Streams trigger the `CacheInvalidatorProcess` which issues HTTP BANs to Varnish and deletes Redis keys.

## Troubleshooting

### Cache Not Hitting

```bash
# Check if Varnish is stripping cookies correctly
varnishlog -g request -q "ReqURL eq '/b/'" -i ReqHeader

# Verify no Set-Cookie from backend
varnishlog -g request -q "ReqURL eq '/b/'" -i BerespHeader
```

Common causes:
- Backend sending `Set-Cookie` on cacheable responses
- Anubis cookie not being stripped (check VCL cookie stripping rules)
- `Vary` header causing excessive cache fragmentation

### Stale Content After Post

```bash
# Verify BAN requests are reaching Varnish
varnishlog -g request -q "ReqMethod eq 'BAN'"

# Check ban list
varnishadm ban.list

# Check CacheInvalidatorProcess logs
tail -f /tmp/ashchan/gateway.log | grep CacheInvalidator
```

### High Memory Usage

```bash
# Check object count and storage usage
varnishstat -f SMA.s0.g_bytes -f SMA.s0.g_space -f MAIN.n_object

# If near capacity, increase VARNISH_STORAGE or reduce TTLs
```

### Backend Unhealthy

```bash
# Check backend probe status
varnishadm backend.list

# View probe logs
varnishlog -g raw -i Backend_health
```

Ensure the gateway's `/health` endpoint returns 200 within the probe timeout (2s).
