# Nginx Hardening & Deployment Guide

Production guide for deploying nginx as the public-facing reverse proxy in front
of Anubis and the Ashchan service mesh. Covers TLS, rate limiting, anti-spam,
performance tuning, and operational guidance.

---

## Architecture

```
┌──────────────┐     ┌────────────────┐     ┌──────────────┐     ┌──────────────┐
│   Internet   │────▶│  nginx (80/443)│────▶│ Anubis (8080)│────▶│ API Gateway  │
│              │     │  TLS + limits  │     │  PoW + bots  │     │  (9501)      │
└──────────────┘     └────────────────┘     └──────────────┘     └──────────────┘
                            │
                            │  Static assets bypass Anubis
                            └──────────────────────────────────▶ Gateway (9501)
```

**Request flow:**

1. Client connects to **nginx** on ports 80/443.
2. nginx terminates TLS, applies rate limits, blocks bad bots, enforces security
   headers, and proxies to **Anubis** on `127.0.0.1:8080`.
3. Anubis applies its proof-of-work challenge and bot policy, then forwards valid
   requests to the **API Gateway** on `127.0.0.1:9501`.
4. Static assets (`/static/`, `/media/`, `/health`) bypass Anubis and go directly
   to the gateway for maximum performance.

**Port exposure with nginx:**

| Port | Service | Exposure |
|------|---------|----------|
| 80   | nginx HTTP (redirect → 443) | **Public** |
| 443  | nginx HTTPS (entry point) | **Public** |
| 8080 | Anubis | Loopback only |
| 9501 | API Gateway | Loopback only |
| 22   | SSH | Admin only |

> With nginx in front, ports 80 and 443 replace 8080 as the public-facing ports.
> Update firewall rules accordingly (see [Firewall Integration](#firewall-integration)).

---

## Quick Start

```bash
# 1. Generate config from the installer
./ashchan nginx:setup

# 2. Or copy the reference config manually
sudo cp config/nginx/nginx.conf /etc/nginx/nginx.conf

# 3. Edit domain & certificate paths
sudo nano /etc/nginx/nginx.conf
#    → Change server_name
#    → Change ssl_certificate / ssl_certificate_key

# 4. Test and reload
sudo nginx -t
sudo systemctl reload nginx

# 5. Update Anubis to trust X-Forwarded-For from nginx
#    (the installer does this automatically)
```

---

## Installation

### Debian / Ubuntu

```bash
sudo apt-get update
sudo apt-get install -y nginx
# Optional: headers-more module for stripping Server header
sudo apt-get install -y libnginx-mod-http-headers-more-filter
```

### RHEL / Fedora / Rocky

```bash
sudo dnf install -y nginx nginx-mod-http-headers-more
```

### Alpine Linux

```bash
sudo apk add nginx nginx-mod-http-headers-more
```

### FreeBSD

```bash
sudo pkg install nginx
```

---

## TLS Certificate Setup

### Let's Encrypt (recommended)

```bash
# Install certbot
sudo apt-get install -y certbot python3-certbot-nginx  # Debian/Ubuntu
sudo dnf install -y certbot python3-certbot-nginx       # RHEL/Fedora
sudo apk add certbot certbot-nginx                      # Alpine

# Obtain certificate (nginx must be running with port 80 open)
sudo certbot --nginx -d ashchan.example.com

# Auto-renewal (certbot installs a systemd timer automatically)
sudo certbot renew --dry-run
```

### Self-signed (development only)

```bash
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/nginx/ssl/ashchan.key \
    -out /etc/nginx/ssl/ashchan.crt \
    -subj "/CN=localhost"
```

### DH Parameters (optional, recommended)

```bash
sudo openssl dhparam -out /etc/nginx/dhparam.pem 2048
# Then uncomment the ssl_dhparam line in nginx.conf
```

---

## Configuration Reference

The reference config lives at `config/nginx/nginx.conf`. Key sections:

### Rate Limiting Zones

| Zone | Rate | Purpose |
|------|------|---------|
| `general` | 30 req/s per IP, burst 20 | Normal page loads and API calls |
| `posting` | 2 req/s per IP, burst 5 | Thread/post creation (anti-spam) |
| `auth` | 5 req/min per IP, burst 3 | Login/register (brute-force protection) |
| `static` | 60 req/s per IP, burst 30 | CSS, JS, images |

Tune these based on your traffic patterns. For boards with heavy posting, you may
increase the `posting` zone rate to `5r/s`.

### Connection Limits

```nginx
limit_conn perip 50;   # Max 50 concurrent connections per IP
```

Reduces the impact of slow-read and connection-flood attacks.

### Timeout Hardening

```nginx
client_header_timeout   10s;   # Max time to receive request headers
client_body_timeout     10s;   # Max time to receive request body
send_timeout            10s;   # Max time between write operations
keepalive_timeout       30s;   # Keep-alive connection lifetime
```

These values are tuned to mitigate Slowloris and slow-read attacks while
remaining comfortable for normal users (including mobile on slow networks).

### Bot Blocking

The config blocks known bad bots and AI scrapers at the nginx level (before they
even reach Anubis):

```nginx
map $http_user_agent $bad_bot {
    ~*(?:AhrefsBot|MJ12bot|SemrushBot|DotBot|BLEXBot)           1;
    ~*(?:GPTBot|CCBot|ChatGPT-User|Google-Extended|FacebookBot)  1;
    ~*(?:ClaudeBot|anthropic-ai|cohere-ai|Perplexity)            1;
    # ... more patterns
    ''  1;   # Empty user-agent = suspicious
}
```

This is a first line of defense. Anubis provides the second layer with PoW
challenges, and the gateway provides the third with application-level anti-spam.

### Security Headers

| Header | Value | Purpose |
|--------|-------|---------|
| `Strict-Transport-Security` | `max-age=63072000; includeSubDomains; preload` | HSTS (2 years) |
| `X-Frame-Options` | `SAMEORIGIN` | Clickjacking prevention |
| `X-Content-Type-Options` | `nosniff` | MIME sniffing prevention |
| `X-XSS-Protection` | `1; mode=block` | XSS filter (legacy browsers) |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Referrer leakage |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` | Feature restriction |
| `Content-Security-Policy` | `default-src 'self'; ...` | XSS / injection mitigation |

### Static Asset Bypass

Static assets (`/static/`, `/media/`) are served directly from the gateway,
bypassing Anubis. This avoids unnecessary PoW challenges for images, CSS, and JS:

```
/static/**  → gateway_direct (9501) — cached 7 days
/media/**   → gateway_direct (9501) — cached 1 day
/health     → gateway_direct (9501) — no logging
Everything else → anubis (8080)      — rate-limited
```

### WebSocket Support

The `/ws` location is configured for WebSocket upgrade with long timeouts:

```nginx
location /ws {
    proxy_http_version 1.1;
    proxy_set_header Upgrade    $http_upgrade;
    proxy_set_header Connection $connection_upgrade;
    proxy_read_timeout  3600s;
    proxy_send_timeout  3600s;
}
```

---

## Anubis Configuration Changes

When nginx is placed in front of Anubis, update the Anubis environment to trust
the `X-Forwarded-For` header from nginx instead of using the raw socket address:

```bash
# config/anubis/env — change these two lines:
USE_REMOTE_ADDRESS=false
CUSTOM_REAL_IP_HEADER=X-Forwarded-For
```

The installer (`./ashchan nginx:setup`) does this automatically.

---

## Cloudflare Integration

If Cloudflare sits in front of nginx (the recommended production topology), three
additional configuration steps are required. Each solves a distinct security
problem.

### Architecture with Cloudflare

```
┌──────────┐     ┌────────────┐     ┌────────────────┐     ┌────────────────┐     ┌──────────────┐
│  Client   │───▶│ Cloudflare │───▶│  nginx (443)    │───▶│  Anubis (8080) │───▶│ API Gateway  │
│           │    │  Edge/WAF  │    │  TLS + verify   │    │  PoW + bots    │    │  (9501)      │
└──────────┘     └────────────┘    └────────────────┘     └────────────────┘     └──────────────┘
                      │                    │
                      │ CF-Connecting-IP   │ X-Real-IP / X-Forwarded-For
                      │ (real client IP)   │ (restored real IP)
                      │                    │
```

### Why Authenticated Origin Pulls

**Problem:** If anyone discovers your origin server's real IP address (through DNS
history, email headers, information leaks, etc.), they can send requests directly
to nginx, completely bypassing Cloudflare's WAF, DDoS protection, bot management,
and rate limiting.

**Solution:** Cloudflare signs every proxied request with a TLS client certificate
issued from their Origin Pull CA. By configuring nginx with
`ssl_verify_client on`, the server rejects any HTTPS connection that does NOT
present a valid Cloudflare client certificate. Direct-to-origin requests fail
with a `400 Bad Request`.

```nginx
# In the server { } block:
ssl_client_certificate /etc/nginx/certs/cloudflare-origin.pem;
ssl_verify_client on;
```

### Why Real IP Restoration

**Problem:** When Cloudflare proxies a request to your origin, `$remote_addr` in
nginx is a Cloudflare edge IP (e.g., `104.16.x.x`), not the real visitor's IP.
This breaks:

- **Rate limiting** — all visitors behind the same CF edge share a limit
- **Fail2ban** — banning a CF edge bans thousands of innocent users
- **Logging/audit** — admin tools cannot identify individual visitors
- **Legal compliance** — DMCA/abuse requests reference real client IPs
- **Admin bans** — moderators need the real IP to ban abusive posters

**Solution:** Cloudflare sends the true client IP in the `CF-Connecting-IP`
header. The `ngx_http_realip_module` replaces `$remote_addr` with this value,
but only when the connection comes from a trusted Cloudflare IP range:

```nginx
# Trust all Cloudflare edge IPs (IPv4 + IPv6)
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
# ... (all ranges — see config/nginx/nginx.conf)

real_ip_header    CF-Connecting-IP;
real_ip_recursive on;
```

After this, `$remote_addr` throughout nginx (and therefore `$proxy_add_x_forwarded_for`,
`X-Real-IP`, rate-limit zones, and logs) all contain the real client IP.

### IP Reversibility for Administrators

The real IP flows through the full chain and is available to admin tools:

| Layer | Header / Variable | Contains |
|-------|-------------------|----------|
| nginx | `$remote_addr` (after realip) | Real client IP |
| nginx | `X-Real-IP` | Real client IP |
| nginx | `X-Forwarded-For` | Real client IP (via `$proxy_add_x_forwarded_for`) |
| nginx | `CF-Connecting-IP` | Real client IP (forwarded from Cloudflare) |
| nginx | `CF-IPCountry` | Client country code (forwarded from Cloudflare) |
| Anubis | `X-Forwarded-For` | Real client IP |
| Gateway | `X-Real-IP` / `X-Forwarded-For` | Real client IP |
| Application | Request headers | Real client IP — used for bans, audit, moderation |

The `CF-Connecting-IP` and `CF-IPCountry` headers are forwarded through every
proxy location block, so the administrator interface can:

1. **Display** the true IP and country of any poster
2. **Ban** by real IP (not a Cloudflare edge IP)
3. **Audit** actions with legally meaningful IP addresses
4. **Correlate** Cloudflare logs with application logs using the same IP
5. **Reverse lookup** — the original `$remote_addr` before realip restoration is
   preserved in the `$realip_remote_addr` variable and can be logged if needed
   for Cloudflare edge identification

### Quick Setup

```bash
# One command does everything:
./ashchan nginx:cloudflare

# This will:
#  1. Download the Cloudflare Origin Pull CA
#  2. Save it to /etc/nginx/certs/cloudflare-origin.pem
#  3. Enable ssl_verify_client in the nginx config
#  4. Refresh the set_real_ip_from ranges from Cloudflare's live API
#  5. Test and optionally reload nginx
```

### Keeping IP Ranges Current

Cloudflare occasionally adds new edge IP ranges. If ranges are missing, real IP
restoration fails for visitors routed through those edges (they appear as CF IPs).

```bash
# Update ranges from Cloudflare's live API:
./ashchan nginx:cloudflare

# Or manually:
curl -s https://www.cloudflare.com/ips-v4
curl -s https://www.cloudflare.com/ips-v6
```

Consider running this monthly via cron:

```bash
# /etc/cron.monthly/cloudflare-ips
#!/bin/bash
/path/to/ashchan nginx:cloudflare 2>&1 | logger -t ashchan-cloudflare
```

### Verifying the Setup

```bash
# 1. Direct-to-origin should be rejected (400)
curl -kI https://your-origin-ip/
# Expected: 400 Bad Request (no client cert)

# 2. Via Cloudflare should work normally
curl -I https://your-domain.com/
# Expected: 200 OK

# 3. Check that real IPs appear in logs (not 104.16.x.x / 172.64.x.x)
tail -f /var/log/nginx/access.log
# Should show real visitor IPs, not Cloudflare edge IPs

# 4. Verify in the admin interface that poster IPs are real
```

---

## Firewall Integration

With nginx as the entry point, update your firewall rules:

### If using the Ashchan firewall installer

```bash
# Regenerate firewall rules with nginx ports
./ashchan firewall:setup
```

The firewall generator automatically detects nginx and opens ports 80/443 instead
of 8080.

### Manual firewall update

Replace the Anubis port (8080) with nginx ports (80, 443) in your rules:

**nftables:**
```nft
# Remove:  tcp dport 8080 ... accept
# Add:
tcp dport { 80, 443 } ct state new \
    add @ratelimit_http { ip saddr limit rate 60/minute burst 120 packets } \
    accept
```

**iptables:**
```bash
# Remove:  -p tcp --dport 8080 ... -j ACCEPT
# Add:
iptables -A INPUT -p tcp -m multiport --dports 80,443 -m conntrack --ctstate NEW \
    -m hashlimit --hashlimit-upto 60/min --hashlimit-burst 120 \
    --hashlimit-mode srcip --hashlimit-name http_limit -j ACCEPT
```

**pf (FreeBSD):**
```
# Remove: pass in on $ext_if proto tcp to port 8080 ...
# Add:
pass in on $ext_if proto tcp to port { 80, 443 } keep state \
    (max-src-conn 100, max-src-conn-rate 60/60, overload <bruteforce> flush)
```

Also add 8080 to the **blocked** internal ports since Anubis should no longer
be directly reachable from the WAN.

---

## Performance Tuning

### Connection Pooling

Nginx maintains persistent connections to both Anubis and the gateway:

```nginx
upstream anubis {
    server 127.0.0.1:8080;
    keepalive 32;              # 32 persistent connections
    keepalive_requests 1000;   # Reuse up to 1000 times
    keepalive_timeout  60s;
}
```

This dramatically reduces latency by avoiding TCP handshake overhead per request.

### Gzip Compression

Enabled by default for text, CSS, JS, JSON, SVG, and XML:

```nginx
gzip_comp_level  4;     # Good balance of CPU vs ratio
gzip_min_length  256;   # Don't compress tiny responses
```

For Brotli (better compression), install `ngx_brotli`:

```bash
# Debian/Ubuntu
sudo apt-get install libnginx-mod-http-brotli-filter libnginx-mod-http-brotli-static
```

Then add to `nginx.conf`:

```nginx
brotli on;
brotli_comp_level 4;
brotli_types text/plain text/css application/json application/javascript image/svg+xml;
```

### Worker Tuning

```nginx
worker_processes     auto;          # One worker per CPU core
worker_rlimit_nofile 65535;         # File descriptor limit per worker
worker_connections   8192;          # Connections per worker
```

For a server with 4 cores: `4 × 8192 = 32,768` maximum simultaneous connections.

### Proxy Buffering

```nginx
proxy_buffering    on;
proxy_buffer_size  4k;      # First part of response (headers)
proxy_buffers      8 16k;   # Body buffers
```

Buffering allows nginx to read the full response from Anubis/gateway quickly and
free the upstream connection, even if the client is slow.

---

## Horizontal Scaling

### Multiple Anubis instances

```nginx
upstream anubis {
    server 127.0.0.1:8080;
    server 127.0.0.1:8081;
    server 127.0.0.1:8082;
    keepalive 32;
}
```

### Multiple gateway instances

```nginx
upstream gateway_direct {
    server 127.0.0.1:9501;
    server 127.0.0.1:9511;  # Second instance
    keepalive 16;
}
```

### IP hash for sticky sessions

If using session cookies that are not shared across instances:

```nginx
upstream anubis {
    ip_hash;
    server 127.0.0.1:8080;
    server 127.0.0.1:8081;
}
```

---

## Monitoring

### Stub status endpoint (internal only)

Add to a separate `server` block listening on loopback:

```nginx
server {
    listen 127.0.0.1:8888;
    location /nginx_status {
        stub_status;
        allow 127.0.0.1;
        deny all;
    }
}
```

### Prometheus exporter

Use [nginx-prometheus-exporter](https://github.com/nginxinc/nginx-prometheus-exporter):

```bash
nginx-prometheus-exporter -nginx.scrape-uri=http://127.0.0.1:8888/nginx_status
```

### Log analysis

```bash
# Top IPs by request count
awk '{print $1}' /var/log/nginx/access.log | sort | uniq -c | sort -rn | head -20

# 429 (rate-limited) responses
grep '" 429 ' /var/log/nginx/access.log | wc -l

# Slow requests (> 2 seconds)
awk -F'rt=' '{if($2+0 > 2.0) print}' /var/log/nginx/access.log
```

---

## Hardening Checklist

```
[ ] TLS certificate installed and auto-renewing
[ ] DH parameters generated (openssl dhparam)
[ ] server_tokens off (hide nginx version)
[ ] Security headers present (test with securityheaders.com)
[ ] Rate limiting zones configured
[ ] Connection limits set
[ ] Bot user-agent blocking active
[ ] Timeout values hardened (Slowloris mitigation)
[ ] Static assets bypass Anubis (performance)
[ ] Anubis configured to trust X-Forwarded-For
[ ] Firewall updated: ports 80/443 open, 8080 blocked externally
[ ] Log rotation configured (/etc/logrotate.d/nginx)
[ ] SSL Labs test score A+ (ssllabs.com/ssltest)
[ ] fail2ban jail for nginx 4xx errors (optional)
[ ] Cloudflare: Authenticated Origin Pulls enabled
[ ] Cloudflare: ssl_verify_client on (blocks direct-to-origin)
[ ] Cloudflare: set_real_ip_from ranges current
[ ] Cloudflare: CF-Connecting-IP forwarded through all location blocks
[ ] Cloudflare: Admin interface shows real IPs (not CF edge IPs)
```

---

## Troubleshooting

### 502 Bad Gateway

Anubis or the gateway is not running:

```bash
./ashchan status
curl -s http://127.0.0.1:8080/health   # Anubis
curl -s http://127.0.0.1:9501/health   # Gateway
```

### 429 Too Many Requests

Rate limit triggered. Check which zone:

```bash
grep '429' /var/log/nginx/error.log | tail -20
```

Adjust the offending zone's `rate` or `burst` in `nginx.conf`.

### Client sees Anubis challenge on every request

The `X-Forwarded-For` header is not being passed, or Anubis is using the socket
address (nginx's loopback) instead:

```bash
# Verify Anubis env
grep -E 'USE_REMOTE|CUSTOM_REAL' config/anubis/env

# Should show:
# USE_REMOTE_ADDRESS=false
# CUSTOM_REAL_IP_HEADER=X-Forwarded-For
```

### WebSocket connections drop

Check the timeout values in the `/ws` location. Default is 3600s (1 hour).
For longer sessions, increase `proxy_read_timeout`.

---

## Fail2ban Integration

Add an nginx-specific jail to catch scanners and brute-forcers at the nginx layer:

### /etc/fail2ban/jail.local (add)

```ini
[nginx-http-auth]
enabled  = true
port     = http,https
filter   = nginx-http-auth
logpath  = /var/log/nginx/error.log
maxretry = 3
bantime  = 3600

[nginx-botsearch]
enabled  = true
port     = http,https
filter   = nginx-botsearch
logpath  = /var/log/nginx/access.log
maxretry = 10
findtime = 60
bantime  = 3600

[nginx-badbots]
enabled  = true
port     = http,https
filter   = apache-badbots
logpath  = /var/log/nginx/access.log
maxretry = 1
bantime  = 86400
```

---

## See Also

- [docs/FIREWALL_HARDENING.md](FIREWALL_HARDENING.md) — Kernel firewall + fail2ban + sysctl
- [docs/security.md](security.md) — mTLS, encryption, audit logging
- [docs/anti-spam.md](anti-spam.md) — Application-level anti-spam (Stop Forum Spam)
- [docs/architecture.md](architecture.md) — System architecture overview
- [config/nginx/nginx.conf](../config/nginx/nginx.conf) — Reference nginx config
- [config/anubis/env](../config/anubis/env) — Anubis environment
