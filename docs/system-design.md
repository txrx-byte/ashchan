# System Design

## Request Flow: Create Post
1. Client connects to Cloudflare Edge (TLS 1.3, WAF, DDoS protection).
2. Cloudflare Tunnel forwards to origin (no public IP exposed).
3. nginx → Anubis (PoW challenge) → Varnish (passes POST uncached) → Gateway.
4. Gateway validates auth, rate limits, and payload.
5. Boards/Threads/Posts stores post and emits PostCreated event via Redis Streams.
6. Media/Uploads links media metadata (if any).
7. Moderation/Anti-spam scores and may quarantine.
8. Search/Indexing updates search documents asynchronously.
9. CacheInvalidatorProcess sends HTTP BAN to Varnish for affected board/thread URLs.
10. Gateway returns response back through the full chain.

## Request Flow: View Thread
1. Client connects to Cloudflare Edge (may serve CDN-cached static assets).
2. Cloudflare Tunnel forwards to origin nginx → Anubis → Varnish.
3. **Varnish checks in-memory cache; if HIT, returns immediately (~0.1ms).**
4. On MISS, Varnish forwards to Gateway.
5. Gateway checks Redis cache (L2); if hit, renders and returns.
6. On Redis miss, Gateway fetches thread from Boards/Threads/Posts.
7. Response is cached by Varnish (30s TTL + 60s grace) and returned.
8. Subsequent requests within TTL are served directly from Varnish.

## Moderation Pipeline
- New posts are scored by automated heuristics.
- High-risk posts go to quarantine queue.
- Human review decisions emit ModerationDecision events.

## Data Retention
- IP logs stored short-term and hashed for analytics.
- Media stored with immutable hashes and deletion markers.

## Failure Isolation
- Search outage does not block posting.
- Media outage blocks upload but not text posts.
- Moderation backlog does not block viewing.

## Scaling Patterns
- Shard posts by board ID where needed.
- Separate read replicas for high-traffic boards.
- Dedicated cache pool for catalogs and threads.
