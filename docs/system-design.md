# System Design

## Request Flow: Create Post
1. Client sends create-post to API Gateway.
2. Gateway validates auth, rate limits, and payload.
3. Boards/Threads/Posts stores post and emits PostCreated event.
4. Media/Uploads links media metadata (if any).
5. Moderation/Anti-spam scores and may quarantine.
6. Search/Indexing updates search documents asynchronously.
7. Gateway invalidates thread cache and returns response.

## Request Flow: View Thread
1. Client requests thread page via Gateway.
2. Gateway checks cache; if hit, return immediately.
3. On miss, Gateway fetches thread from Boards/Threads/Posts.
4. Response is cached with TTL and invalidation key.

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
