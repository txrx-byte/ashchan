# ═══════════════════════════════════════════════════════════════
# Ashchan — Varnish VCL Configuration
# ═══════════════════════════════════════════════════════════════
#
# Architecture:  nginx (443) → Anubis (8080) → Varnish (6081) → API Gateway (9501)
#
# Varnish sits behind Anubis (proof-of-work) so only legitimate
# requests reach the cache layer. This avoids cache pollution by
# bots and scrapers, and lets Varnish focus purely on performance.
#
# Cacheable responses:
#   - Board index pages   /{slug}/              TTL 30s
#   - Thread pages        /{slug}/thread/{id}   TTL 30s
#   - Catalog pages       /{slug}/catalog       TTL 30s
#   - Archive pages       /{slug}/archive       TTL 60s
#   - Home page           /                     TTL 30s
#   - 4chan-compat API     /api/v1/4chan/*        TTL 10s
#   - Public API reads    /api/v1/boards/*  GET  TTL 15s
#
# NOT cached:
#   - POST/PUT/DELETE requests
#   - Staff/admin pages   /staff/*
#   - Auth endpoints      /api/v1/auth/*
#   - WebSocket upgrades  /ws
#   - Upload endpoints    /api/v1/media/upload*
#   - Requests with session cookies
#
# Cache invalidation:
#   - HTTP BAN method from the gateway's CacheInvalidatorProcess
#   - Pattern-based bans via X-Ban-Pattern header
#   - PURGE for individual URLs
#   - Bans are evaluated lazily (Varnish ban lurker)
#
# See: docs/VARNISH_CACHE.md
# ═══════════════════════════════════════════════════════════════

vcl 4.1;

import std;

# ── Backend: API Gateway ─────────────────────────────────────
backend default {
    .host = "127.0.0.1";
    .port = "9501";

    # Health-check probe
    .probe = {
        .url       = "/health";
        .interval  = 5s;
        .timeout   = 2s;
        .window    = 5;
        .threshold = 3;
    }

    # Connection tuning
    .connect_timeout        = 3s;
    .first_byte_timeout     = 10s;
    .between_bytes_timeout  = 5s;
    .max_connections        = 256;
}

# ── ACL: hosts allowed to send BAN/PURGE requests ───────────
acl purge_acl {
    "localhost";
    "127.0.0.1";
    "::1";
    # Add internal network ranges as needed:
    # "10.0.0.0"/8;
    # "172.16.0.0"/12;
    # "192.168.0.0"/16;
}


# ═════════════════════════════════════════════════════════════
# vcl_recv — Incoming request processing
# ═════════════════════════════════════════════════════════════
sub vcl_recv {

    # ── BAN requests (pattern-based cache invalidation) ──────
    if (req.method == "BAN") {
        if (!client.ip ~ purge_acl) {
            return (synth(403, "Forbidden"));
        }

        # Pattern ban via X-Ban-Pattern header
        # Example: X-Ban-Pattern: ^/b/thread/
        if (req.http.X-Ban-Pattern) {
            ban("req.url ~ " + req.http.X-Ban-Pattern);
            return (synth(200, "Banned pattern: " + req.http.X-Ban-Pattern));
        }

        # Full board ban via X-Ban-Board header
        # Example: X-Ban-Board: b
        if (req.http.X-Ban-Board) {
            ban("req.url ~ ^/" + req.http.X-Ban-Board + "/");
            return (synth(200, "Banned board: " + req.http.X-Ban-Board));
        }

        # Default: ban the specific URL
        ban("req.url == " + req.url);
        return (synth(200, "Banned: " + req.url));
    }

    # ── PURGE requests (single URL invalidation) ─────────────
    if (req.method == "PURGE") {
        if (!client.ip ~ purge_acl) {
            return (synth(403, "Forbidden"));
        }
        return (purge);
    }

    # ── Only cache GET and HEAD ──────────────────────────────
    if (req.method != "GET" && req.method != "HEAD") {
        return (pass);
    }

    # ── Never cache staff/admin pages ────────────────────────
    if (req.url ~ "^/staff/") {
        return (pass);
    }

    # ── Never cache auth endpoints ───────────────────────────
    if (req.url ~ "^/api/v1/(auth|login|register)") {
        return (pass);
    }

    # ── Never cache uploads ──────────────────────────────────
    if (req.url ~ "^/api/v1/media/upload") {
        return (pass);
    }

    # ── Never cache WebSocket upgrades ───────────────────────
    if (req.http.Upgrade ~ "(?i)websocket") {
        return (pipe);
    }

    # ── Never cache POST-like API endpoints ──────────────────
    if (req.url ~ "^/api/v1/" && req.method == "POST") {
        return (pass);
    }

    # ── Strip Anubis challenge cookie (not needed by backend) ─
    # Anubis sets its own cookie for PoW tracking; Varnish should
    # not vary on it. We keep only session-related cookies.
    if (req.http.Cookie) {
        # Remove Anubis cookies
        set req.http.Cookie = regsuball(req.http.Cookie, "(^|;\s*)ashchan-anubis[^;]*", "");
        # Remove __Host- and __Secure- prefixed challenge cookies
        set req.http.Cookie = regsuball(req.http.Cookie, "(^|;\s*)(__Host-|__Secure-)anubis[^;]*", "");

        # Clean up leading/trailing semicolons and whitespace
        set req.http.Cookie = regsuball(req.http.Cookie, "^;\s*", "");
        set req.http.Cookie = regsuball(req.http.Cookie, ";\s*$", "");

        # If no cookies left, remove the header entirely (enables caching)
        if (req.http.Cookie == "") {
            unset req.http.Cookie;
        }
    }

    # ── If request still has cookies, don't cache ────────────
    # (staff session cookies, etc.)
    if (req.http.Cookie) {
        return (pass);
    }

    # ── Cacheable paths — let Varnish handle them ────────────
    return (hash);
}


# ═════════════════════════════════════════════════════════════
# vcl_hash — Cache key construction
# ═════════════════════════════════════════════════════════════
sub vcl_hash {
    hash_data(req.url);

    if (req.http.Host) {
        hash_data(req.http.Host);
    } else {
        hash_data(server.ip);
    }

    return (lookup);
}


# ═════════════════════════════════════════════════════════════
# vcl_backend_response — Process backend response before caching
# ═════════════════════════════════════════════════════════════
sub vcl_backend_response {

    # ── Don't cache non-200 responses ────────────────────────
    if (beresp.status != 200) {
        set beresp.uncacheable = true;
        set beresp.ttl = 0s;
        return (deliver);
    }

    # ── Don't cache responses with Set-Cookie ────────────────
    if (beresp.http.Set-Cookie) {
        set beresp.uncacheable = true;
        set beresp.ttl = 0s;
        return (deliver);
    }

    # ── Home page ────────────────────────────────────────────
    if (bereq.url == "/" || bereq.url == "") {
        set beresp.ttl = 30s;
        set beresp.grace = 60s;
        return (deliver);
    }

    # ── Board index pages: /{slug}/ ──────────────────────────
    if (bereq.url ~ "^/[a-zA-Z0-9]+/$") {
        set beresp.ttl = 30s;
        set beresp.grace = 60s;
        return (deliver);
    }

    # ── Thread pages: /{slug}/thread/{id} ────────────────────
    if (bereq.url ~ "^/[a-zA-Z0-9]+/thread/[0-9]+") {
        set beresp.ttl = 30s;
        set beresp.grace = 60s;
        return (deliver);
    }

    # ── Catalog pages: /{slug}/catalog ───────────────────────
    if (bereq.url ~ "^/[a-zA-Z0-9]+/catalog$") {
        set beresp.ttl = 30s;
        set beresp.grace = 60s;
        return (deliver);
    }

    # ── Archive pages: /{slug}/archive ───────────────────────
    if (bereq.url ~ "^/[a-zA-Z0-9]+/archive$") {
        set beresp.ttl = 60s;
        set beresp.grace = 120s;
        return (deliver);
    }

    # ── 4chan-compatible API (read-only) ─────────────────────
    if (bereq.url ~ "^/api/v1/4chan/") {
        set beresp.ttl = 10s;
        set beresp.grace = 30s;
        return (deliver);
    }

    # ── Public API read endpoints ────────────────────────────
    if (bereq.url ~ "^/api/v1/boards/" && bereq.method == "GET") {
        set beresp.ttl = 15s;
        set beresp.grace = 30s;
        return (deliver);
    }

    # ── Static assets (fallback — nginx normally serves these) ─
    if (bereq.url ~ "^/static/") {
        set beresp.ttl = 3600s;
        set beresp.grace = 86400s;
        return (deliver);
    }

    # ── Media assets (fallback — nginx normally serves these) ─
    if (bereq.url ~ "^/media/") {
        set beresp.ttl = 1800s;
        set beresp.grace = 3600s;
        return (deliver);
    }

    # ── Default: short TTL for unmatched 200s ────────────────
    set beresp.ttl = 10s;
    set beresp.grace = 30s;

    return (deliver);
}


# ═════════════════════════════════════════════════════════════
# vcl_deliver — Final response to client
# ═════════════════════════════════════════════════════════════
sub vcl_deliver {

    # ── Debug headers (disable in production if desired) ─────
    if (obj.hits > 0) {
        set resp.http.X-Cache = "HIT";
        set resp.http.X-Cache-Hits = obj.hits;
    } else {
        set resp.http.X-Cache = "MISS";
    }

    # Remove Varnish internals from response
    unset resp.http.X-Varnish;
    unset resp.http.Via;

    return (deliver);
}


# ═════════════════════════════════════════════════════════════
# vcl_synth — Synthetic responses (errors, BAN confirmations)
# ═════════════════════════════════════════════════════════════
sub vcl_synth {
    # BAN/PURGE confirmation responses
    if (resp.status == 200) {
        set resp.http.Content-Type = "text/plain; charset=utf-8";
        synthetic(resp.reason);
        return (deliver);
    }

    # Error pages
    set resp.http.Content-Type = "text/html; charset=utf-8";
    set resp.http.Retry-After = "5";

    synthetic({"<!DOCTYPE html>
<html>
<head><title>"} + resp.status + " " + resp.reason + {"</title></head>
<body>
<h1>"} + resp.status + " " + resp.reason + {"</h1>
<p>The request could not be completed.</p>
</body>
</html>"});

    return (deliver);
}
