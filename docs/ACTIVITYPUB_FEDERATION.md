# ActivityPub Federation — Decentralized Imageboard Protocol

> **Status:** Design / Vision Document  
> **Date:** 2026-02-24  
> **Inspiration:** Matrix protocol (decentralized rooms), ActivityPub (W3C), FChannel, Lemmy  
> **Target Stack:** Hyperf 3.1 / Swoole (PHP-CLI)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Why Federate an Imageboard](#2-why-federate-an-imageboard)
3. [Conceptual Model — Matrix Meets ActivityPub](#3-conceptual-model--matrix-meets-activitypub)
4. [Identity & Actor Model](#4-identity--actor-model)
5. [Object Mapping — Imageboard → ActivityPub](#5-object-mapping--imageboard--activitypub)
6. [Federation Protocol](#6-federation-protocol)
7. [Thread Replication & Consistency](#7-thread-replication--consistency)
8. [Media Federation](#8-media-federation)
9. [Moderation in a Federated World](#9-moderation-in-a-federated-world)
10. [Liveposting Over Federation](#10-liveposting-over-federation)
11. [Discovery & Relay Architecture](#11-discovery--relay-architecture)
12. [Security Model](#12-security-model)
13. [Privacy Considerations](#13-privacy-considerations)
14. [Architecture — New Federation Service](#14-architecture--new-federation-service)
15. [Database Schema](#15-database-schema)
16. [Wire Protocol Examples](#16-wire-protocol-examples)
17. [Compatibility Matrix](#17-compatibility-matrix)
18. [Migration & Rollout Strategy](#18-migration--rollout-strategy)
19. [Open Questions](#19-open-questions)

---

## 1. Executive Summary

This document envisions **Ashchan as a federated imageboard** — a network of independent instances that share boards, threads, and posts across organizational boundaries using the **W3C ActivityPub** protocol, extended with imageboard-specific semantics inspired by the **Matrix protocol's** approach to decentralized rooms.

### The Core Idea

Just as Matrix allows any homeserver to participate in a conversation, any Ashchan-compatible instance should be able to:

- **Subscribe** to remote boards and receive threads/posts in real time
- **Contribute** posts to remote threads that federate back to all participants
- **Mirror** popular threads across instances for resilience and local speed
- **Moderate independently** — each instance decides what it shows, regardless of origin
- **Remain fully functional** when disconnected from the federation

Federation is **opt-in, per-board, operator-controlled**. An instance can run entirely standalone (as today) or selectively federate specific boards.

### Design Principles

| Principle | Rationale |
|-----------|-----------|
| **Sovereignty first** | Each instance is authoritative for its own content and moderation |
| **Privacy by default** | No PII crosses instance boundaries; IP addresses never federate |
| **Eventual consistency** | Threads converge across instances; temporary divergence is acceptable |
| **Graceful degradation** | Federation outages do not impact local functionality |
| **Standard protocols** | ActivityPub (W3C) + HTTP Signatures (IETF) for maximum interop |
| **Anonymous-native** | Federation works without user accounts — anonymous posting is first-class |

---

## 2. Why Federate an Imageboard

### Problems Solved

| Problem | Federated Solution |
|---------|--------------------|
| Single point of failure / takedown | Content replicated across sovereign instances |
| Geographic latency | Users read from their nearest instance |
| Operator burnout | Moderation load distributed across instance operators |
| Community fragmentation | Shared boards without shared servers |
| Censorship resistance | No single entity controls all copies |
| Scaling bottleneck | Read load distributed across the federation |

### Real-World Analogy

Think of it as **Usenet reimagined for imageboards**:
- Each instance is a "news server" that carries the boards it chooses
- Posts propagate across the network like NNTP articles
- Each operator sets their own retention, moderation, and access policies
- But unlike Usenet: real-time WebSocket updates, media deduplication, and modern crypto

### What Matrix Gets Right (And What We Borrow)

| Matrix Concept | Ashchan Federation Equivalent |
|----------------|-------------------------------|
| **Room** | **Board** (federated) or **Thread** (federated) |
| **Homeserver** | **Instance** (an Ashchan deployment) |
| **Room state** | **Board config, mod actions, sticky/lock state** |
| **Event DAG** | **Post ordering DAG** (with timestamps + Lamport clocks) |
| **Server ACLs** | **Instance allowlist/blocklist** |
| **Room aliases** | **Board aliases** (`/a/@instance.tld`) |
| **Backfill** | **Thread backfill on subscribe** |
| **State resolution** | **Conflict resolution for concurrent mod actions** |

---

## 3. Conceptual Model — Matrix Meets ActivityPub

### The Hybrid Approach

Pure ActivityPub (as used by Mastodon/Lemmy) is **actor-centric** — content flows between user accounts. Imageboards are **content-centric** — content flows through boards and threads, often anonymously.

We adopt a hybrid:

1. **Actors are instances and boards** (not individual users) — this preserves anonymity
2. **Objects follow ActivityPub vocabulary** — `Note`, `Article`, `Image`, `Collection`
3. **Replication follows Matrix semantics** — event DAGs, state resolution, backfill
4. **Transport uses ActivityPub S2S** — inbox/outbox, HTTP Signatures, JSON-LD

```
┌───────────────────────────────────────────────────────────────┐
│                    FEDERATION LAYER                           │
│                                                               │
│  ┌──────────┐   ActivityPub S2S    ┌──────────┐              │
│  │ Instance │◄═══════════════════►│ Instance │              │
│  │  alpha   │   HTTP Signatures    │  beta    │              │
│  └────┬─────┘                      └────┬─────┘              │
│       │                                  │                    │
│  ┌────▼─────┐                      ┌────▼─────┐              │
│  │ Board /a/│    Thread Sync       │ Board /a/│              │
│  │ (origin) │◄────────────────────►│ (mirror) │              │
│  └────┬─────┘                      └────┬─────┘              │
│       │                                  │                    │
│  ┌────▼─────┐                      ┌────▼─────┐              │
│  │Thread 123│   Post Replication   │Thread 123│              │
│  │ 50 posts │◄────────────────────►│ 50 posts │              │
│  └──────────┘                      └──────────┘              │
│                                                               │
└───────────────────────────────────────────────────────────────┘
```

### Federation Topology

```
                    ┌──────────────────┐
                    │   Relay Server   │  (optional, discovery + fanout)
                    │  relay.chan.net   │
                    └──┬───────────┬───┘
                       │           │
            subscribe  │           │  subscribe
                       │           │
           ┌───────────▼──┐    ┌───▼───────────┐
           │  Instance A  │    │  Instance B   │
           │  alpha.chan   │    │  beta.chan     │
           │              │    │               │
           │  /a/ (origin)│    │  /a/ (mirror) │
           │  /b/ (origin)│    │  /c/ (origin) │
           │  /c/ (mirror)│    │  /b/ (mirror) │
           └───────┬──────┘    └──────┬────────┘
                   │                  │
                   │    direct S2S    │
                   └──────────────────┘

           ┌──────────────┐
           │  Instance C  │   (standalone, no federation)
           │  gamma.chan   │
           │  /a/ /b/ /z/ │   ← all local, no federation
           └──────────────┘
```

---

## 4. Identity & Actor Model

### Instance Actor

Every Ashchan instance has a **server actor** — the primary identity for federation.

```json
{
  "@context": [
    "https://www.w3.org/ns/activitystreams",
    "https://w3id.org/security/v1",
    "https://ashchan.org/ns/federation/v1"
  ],
  "id": "https://alpha.chan/federation/actor",
  "type": "Application",
  "name": "Alpha Chan",
  "preferredUsername": "alpha.chan",
  "summary": "An independent imageboard instance",
  "inbox": "https://alpha.chan/federation/inbox",
  "outbox": "https://alpha.chan/federation/outbox",
  "followers": "https://alpha.chan/federation/followers",
  "following": "https://alpha.chan/federation/following",
  "publicKey": {
    "id": "https://alpha.chan/federation/actor#main-key",
    "owner": "https://alpha.chan/federation/actor",
    "publicKeyPem": "-----BEGIN PUBLIC KEY-----\n..."
  },
  "endpoints": {
    "sharedInbox": "https://alpha.chan/federation/shared-inbox"
  },
  "ashchan:version": "1.0.0",
  "ashchan:protocols": ["activitypub", "ashchan-sync/1"],
  "ashchan:capabilities": ["liveposting", "nekotv", "media-dedup"]
}
```

### Board Actor

Each federated board is also an actor, enabling fine-grained subscription and moderation.

```json
{
  "@context": [
    "https://www.w3.org/ns/activitystreams",
    "https://ashchan.org/ns/federation/v1"
  ],
  "id": "https://alpha.chan/federation/board/a",
  "type": "Group",
  "name": "/a/ - Anime & Manga",
  "preferredUsername": "a",
  "attributedTo": "https://alpha.chan/federation/actor",
  "inbox": "https://alpha.chan/federation/board/a/inbox",
  "outbox": "https://alpha.chan/federation/board/a/outbox",
  "followers": "https://alpha.chan/federation/board/a/followers",
  "ashchan:boardConfig": {
    "slug": "a",
    "bumpLimit": 300,
    "imageLimit": 150,
    "maxThreads": 200,
    "nsfw": false,
    "cooldownSeconds": 60,
    "federationPolicy": "open"
  }
}
```

### Anonymous Identity Preservation

Unlike Mastodon/Lemmy, **individual posters are NOT actors**. Posts are attributed to the **board actor** with optional anonymous metadata:

```json
{
  "attributedTo": "https://alpha.chan/federation/board/a",
  "ashchan:posterIdentity": {
    "type": "anonymous",
    "posterHash": "aB3kQ9x2",
    "tripcode": "!Ep8pUI8Vw2",
    "countryFlag": "US"
  }
}
```

**Critical rule:** IP addresses, session tokens, and any PII **never** cross instance boundaries. Only the poster hash (if the board uses poster IDs) and optional tripcode traverse the federation.

---

## 5. Object Mapping — Imageboard → ActivityPub

### Core Type Mappings

| Imageboard Concept | ActivityPub Type | ActivityPub Activity | Notes |
|--------------------|------------------|---------------------|-------|
| Board | `Group` actor | — | Actor with inbox/outbox |
| Thread (OP) | `Note` + `Collection` | `Create` | OP is a Note; thread is an OrderedCollection of replies |
| Reply (post) | `Note` | `Create` | `inReplyTo` → OP's `id` |
| Image/media | `Document` | `Create` (attached) | `Image`, `Video`, or generic `Document` |
| Sage | custom property | — | `ashchan:sage: true` |
| Sticky | custom property | `Update` | `ashchan:sticky: true` on thread |
| Lock | custom property | `Update` | `ashchan:locked: true` on thread |
| Archive | custom property | `Update` | `ashchan:archived: true` on thread |
| Bump | implicit | — | Determined by reply without sage |
| Deletion (user) | — | `Delete` | Tombstone with reason |
| Moderation delete | — | `Delete` | Tombstone, mod action metadata |
| Ban (local scope) | — | NOT federated | Bans are instance-local |
| Report | — | `Flag` | Federated report to origin instance |

### Thread as OrderedCollection

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://alpha.chan/federation/board/a/thread/12345",
  "type": "OrderedCollection",
  "totalItems": 43,
  "attributedTo": "https://alpha.chan/federation/board/a",
  "first": "https://alpha.chan/federation/board/a/thread/12345?page=1",
  "ashchan:threadMeta": {
    "no": 12345,
    "sticky": false,
    "locked": false,
    "archived": false,
    "bumpedAt": "2026-02-24T12:00:00Z",
    "replyCount": 42,
    "imageCount": 15
  }
}
```

### Post (Reply) as Note

```json
{
  "@context": [
    "https://www.w3.org/ns/activitystreams",
    "https://ashchan.org/ns/federation/v1"
  ],
  "id": "https://alpha.chan/federation/board/a/post/12388",
  "type": "Note",
  "attributedTo": "https://alpha.chan/federation/board/a",
  "inReplyTo": "https://alpha.chan/federation/board/a/post/12345",
  "context": "https://alpha.chan/federation/board/a/thread/12345",
  "published": "2026-02-24T12:05:30Z",
  "content": "<span class=\"greentext\">&gt;implying federation works</span><br>It does now.",
  "source": {
    "content": ">implying federation works\nIt does now.",
    "mediaType": "text/plain"
  },
  "attachment": [
    {
      "type": "Image",
      "mediaType": "image/jpeg",
      "url": "https://alpha.chan/media/2026/02/abc123.jpg",
      "ashchan:thumbnail": "https://alpha.chan/media/2026/02/abc123s.jpg",
      "width": 1920,
      "height": 1080,
      "ashchan:fileSize": 245760,
      "ashchan:hash": "sha256:e3b0c44298fc1c149afbf4c8996fb924...",
      "ashchan:spoiler": false
    }
  ],
  "ashchan:postMeta": {
    "no": 12388,
    "boardPostNo": 98765,
    "name": "Anonymous",
    "tripcode": null,
    "capcode": null,
    "sage": false,
    "posterHash": "aB3kQ9x2",
    "countryCode": "US"
  }
}
```

---

## 6. Federation Protocol

### 6.1 Board Subscription (Follow)

An instance subscribes to a remote board by sending a `Follow` activity to the board actor:

```
Instance B                           Instance A (origin)
    │                                      │
    │──── Follow(board/a) ────────────────►│
    │                                      │ verify HTTP Sig
    │                                      │ check allowlist
    │◄─── Accept(Follow) ────────────────│
    │                                      │
    │◄─── Announce(board config) ─────────│
    │◄─── Announce(active threads) ───────│  backfill
    │                                      │
    │◄─── Create(post) ──────────────────│  ongoing
    │◄─── Update(thread sticky) ─────────│  ongoing
    │◄─── Delete(post) ──────────────────│  ongoing
    │                                      │
```

### 6.2 Cross-Instance Posting

When a user on Instance B posts in a thread that originated on Instance A:

```
User → Instance B                    Instance A (origin)
  │                                      │
  │── POST /a/thread/123 ──►│            │
  │                          │            │
  │               B validates locally     │
  │               B assigns local post #  │
  │               B stores locally        │
  │                          │            │
  │               B ──── Create(post) ───►│
  │                          │            │ A validates
  │                          │            │ A assigns canonical post #
  │                          │            │ A stores
  │                          │◄── Accept ─│
  │                          │            │
  │                          │            │── Create(post) ──► Instance C
  │                          │            │── Create(post) ──► Instance D
  │◄── 201 Created ─────────│            │   (fanout to all subscribers)
  │                                      │
```

### 6.3 Ordering & Consistency Model

Inspired by Matrix's event DAG, we use a **causal ordering** model to handle concurrent posts from multiple instances:

```
              Post 45 (Instance A)
               /              \
    Post 46 (A)            Post 46' (B)    ← concurrent posts
               \              /
              Post 47 (A)                  ← A resolves: 46 before 46'
```

Each post carries:

```json
{
  "ashchan:causal": {
    "lamport": 47,
    "parents": ["https://alpha.chan/.../post/46", "https://beta.chan/.../post/46-b"],
    "originInstance": "https://alpha.chan",
    "originTimestamp": "2026-02-24T12:05:30.123Z"
  }
}
```

**Conflict resolution** (for threads originated on this instance):

1. Origin instance is authoritative for post ordering within its threads
2. Remote posts are assigned a canonical position by the origin
3. If origin is unreachable, each instance orders by `(timestamp, instance_id)` — deterministic

**State resolution** (for mod actions like sticky/lock):

1. Origin instance's mod actions always win for its own threads
2. Mirror instances can apply **local overrides** (e.g., local-only lock) that don't federate
3. Conflicting state resolves by: `(action_timestamp, origin_priority, instance_id)` — same as Matrix's state resolution algorithm simplified for imageboard semantics

---

## 7. Thread Replication & Consistency

### Replication Modes

| Mode | Description | Use Case |
|------|-------------|----------|
| **Live** | All posts forwarded in real time via ActivityPub | Active boards, low latency |
| **Batch** | Posts buffered and sent periodically (1-60s) | High-volume boards, bandwidth savings |
| **On-demand** | Thread fetched only when a local user requests it | Archival, low-traffic mirrors |
| **Snapshot** | Periodic full thread dump (like NNTP batch) | Backup, disaster recovery |

### Backfill Protocol

When an instance subscribes to a board, it needs the existing thread state:

```
GET /federation/board/a/outbox?type=thread&since=2026-02-01
Accept: application/activity+json

→ OrderedCollectionPage of recent threads
→ Each thread links to its OrderedCollection of posts
→ Client paginates to fetch full history
```

Backfill is **bandwidth-aware**: instances advertise their preferred batch sizes and the origin respects them. Large backfills use HTTP/2 server push or chunked transfer.

### Consistency Guarantees

| Guarantee | Level | Mechanism |
|-----------|-------|-----------|
| Post ordering | Eventual | Lamport timestamps + origin authority |
| Post content | Strong (origin) | Origin is canonical; mirrors converge |
| Thread state (sticky/lock) | Eventual | State resolution with origin priority |
| Media availability | Best-effort | Federated fetch + local cache |
| Deletion | Eventual | Tombstones propagate; instances decide retention |

---

## 8. Media Federation

### Content-Addressed Media

Ashchan already uses SHA-256 content hashing for media. Federation extends this:

```
┌──────────────┐                    ┌──────────────┐
│  Instance A  │                    │  Instance B  │
│              │                    │              │
│  img.jpg     │   post references  │  img.jpg     │
│  sha256:abc  │◄──────────────────│  sha256:abc  │
│  (origin)    │                    │  (cached)    │
│              │                    │              │
│  MinIO/S3    │   fetch on miss    │  MinIO/S3    │
└──────────────┘────────────────────└──────────────┘
```

### Media Resolution Strategy

1. **Post arrives** with `attachment.ashchan:hash = sha256:abc123...`
2. **Local check**: Does this hash exist in local `media_objects`?
3. **Cache hit**: Serve from local storage — zero federation traffic
4. **Cache miss**: Fetch from `attachment.url` (origin instance)
5. **Store locally**: Cache in local MinIO/S3 with same hash
6. **Rewrite URLs**: Serve from local CDN/media proxy to users

### Bandwidth Optimization

- **pHash deduplication**: Near-identical images (different EXIF, crops) detected via perceptual hash before fetching
- **Lazy fetch**: Thumbnails fetched immediately, full images fetched only on user click
- **Signed URLs**: Origin instance can issue time-limited signed URLs for media access
- **Banned media propagation**: If an instance bans a hash (CSAM, DMCA), the ban propagates as a `Flag` with `ashchan:mediaAction: "ban"`

---

## 9. Moderation in a Federated World

### Sovereignty Model

Every instance is **sovereign** — it decides what to show, regardless of the origin's moderation decisions.

```
┌─────────────────────────────────────────────────────────┐
│  MODERATION LAYERS                                      │
│                                                          │
│  ┌─────────────────────────────────────────────────────┐ │
│  │ Layer 1: ORIGIN MODERATION                          │ │
│  │  • Post deleted at source → Tombstone sent to all   │ │
│  │  • Thread locked → Update sent to all               │ │
│  │  • User banned → NOT federated (local scope)        │ │
│  └─────────────────────────────────────────────────────┘ │
│                                                          │
│  ┌─────────────────────────────────────────────────────┐ │
│  │ Layer 2: INSTANCE-LOCAL MODERATION                  │ │
│  │  • Local mods can delete/hide federated posts       │ │
│  │  • Local mods can lock federated threads            │ │
│  │  • Local instance can defederate specific instances │ │
│  │  • These actions DO NOT propagate                   │ │
│  └─────────────────────────────────────────────────────┘ │
│                                                          │
│  ┌─────────────────────────────────────────────────────┐ │
│  │ Layer 3: FEDERATION-WIDE SIGNALS                    │ │
│  │  • Flag activity → reports sent to origin           │ │
│  │  • Shared blocklists (opt-in)                       │ │
│  │  • Media hash bans (opt-in, for CSAM/DMCA)          │ │
│  │  • Instance reputation scores                       │ │
│  └─────────────────────────────────────────────────────┘ │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

### Federated Reports

When a user on Instance B reports a post that originated on Instance A:

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "type": "Flag",
  "actor": "https://beta.chan/federation/actor",
  "object": "https://alpha.chan/federation/board/a/post/12345",
  "content": "Rule violation: spam",
  "ashchan:reportMeta": {
    "category": 3,
    "weight": 1.5,
    "originBoard": "a",
    "originPost": 12345
  }
}
```

### Instance Allowlist / Blocklist

Operators control federation at the instance level:

| Policy | Behavior |
|--------|----------|
| **Open** | Accept follows and posts from any known instance |
| **Allowlist** | Only accept from explicitly approved instances |
| **Blocklist** | Accept from all except blocked instances |
| **Closed** | No federation (standalone mode, current default) |

```json
{
  "ashchan:federationPolicy": {
    "mode": "allowlist",
    "allowedInstances": [
      "https://beta.chan",
      "https://gamma.chan"
    ],
    "blockedInstances": [],
    "autoAcceptFollows": false,
    "requireManualApproval": true,
    "mediaPolicy": "fetch-and-cache",
    "nsfwPolicy": "respect-origin"
  }
}
```

### Shared Moderation Infrastructure

- **StopForumSpam scores**: Instances can optionally share spam confidence scores (never raw IPs) via a `Flag` with `ashchan:spamScore`
- **Media hash blocklists**: CSAM hash lists propagate across the federation instantly via `Announce` activities
- **Instance reputation**: A simple scoring system based on report-to-post ratio, uptime, and mod responsiveness

---

## 10. Liveposting Over Federation

### The Challenge

Liveposting (character-by-character streaming) creates high-frequency, low-payload events. Naively federating every keystroke would overwhelm inter-instance links.

### The Solution: Buffered Federation with Local Fan-Out

```
User types on Instance B          Instance A (origin)          Instance C (mirror)
        │                                │                            │
  keystroke ──► local Swoole             │                            │
  keystroke ──► WebSocket fan-out        │                            │
  keystroke ──► (local users see         │                            │
               it instantly)             │                            │
        │                                │                            │
  [every 500ms, or on close]             │                            │
        │                                │                            │
  batch ── ActivityPub Update ──────────►│                            │
        │   (body snapshot)              │── Update ─────────────────►│
        │                                │                            │
        │                          A fans out to                C fans out to
        │                          local WebSocket              local WebSocket
        │                          clients (char-by-char        clients (chunked
        │                          if body diff is small)       replay)
```

### Federation Livepost Protocol

| Event | Local (WebSocket) | Federated (ActivityPub) |
|-------|-------------------|------------------------|
| Post opened | Immediate broadcast | `Create` with `ashchan:editing: true` |
| Keystrokes | Per-keystroke via WS | Batched body snapshots every 500ms |
| Post closed | Immediate broadcast | `Update` with final body, `ashchan:editing: false` |
| Splice (backspace) | Per-operation via WS | Included in next batch snapshot |

### Bandwidth Estimates

| Board Activity | Keystroke Events/sec | Federated Events/sec | Bandwidth |
|----------------|---------------------|---------------------|-----------|
| Quiet board (5 active posters) | ~25 | ~10 (batched) | ~5 KB/s |
| Active board (50 active posters) | ~250 | ~100 (batched) | ~50 KB/s |
| Raid/spike (500 active posters) | ~2,500 | ~500 (batched, throttled) | ~250 KB/s |

---

## 11. Discovery & Relay Architecture

### Instance Discovery

Instances discover each other through multiple mechanisms:

```
┌────────────────────────────────────────────────────┐
│  DISCOVERY METHODS                                  │
│                                                      │
│  1. Manual Configuration                            │
│     operator adds known instance URLs               │
│                                                      │
│  2. WebFinger                                       │
│     GET /.well-known/webfinger?resource=acct:a@alpha.chan │
│     → returns board actor + federation endpoints    │
│                                                      │
│  3. NodeInfo                                        │
│     GET /.well-known/nodeinfo                       │
│     → software name, version, capabilities, stats   │
│                                                      │
│  4. Relay Servers                                   │
│     Dedicated relay instance that forwards           │
│     Announce activities between subscribers          │
│                                                      │
│  5. DNS-SD (future)                                 │
│     _ashchan._tcp.alpha.chan SRV record              │
└────────────────────────────────────────────────────┘
```

### Relay Server Architecture

Relays are lightweight instances that exist solely to fan out activities:

```
                    ┌──────────────────┐
                    │   Relay Server   │
                    │                  │
                    │  No local boards │
                    │  No local posts  │
                    │  No media storage│
                    │                  │
                    │  Just receives   │
                    │  Announce and    │
                    │  forwards to all │
                    │  subscribers     │
                    └──┬──────────┬────┘
                       │          │
              ┌────────▼┐    ┌───▼────────┐
              │Instance A│    │Instance B  │
              └──────────┘    └────────────┘
```

### NodeInfo Response

```json
{
  "version": "2.1",
  "software": {
    "name": "ashchan",
    "version": "1.0.0"
  },
  "protocols": ["activitypub"],
  "usage": {
    "users": { "total": 0, "activeMonth": 0 },
    "localPosts": 152340
  },
  "openRegistrations": false,
  "metadata": {
    "boardCount": 12,
    "federatedBoardCount": 5,
    "peerCount": 8,
    "features": ["liveposting", "nekotv", "4chan-api"],
    "federationPolicy": "allowlist"
  }
}
```

---

## 12. Security Model

### HTTP Signatures (RFC 9421)

All S2S requests are signed using HTTP Message Signatures:

```http
POST /federation/board/a/inbox HTTP/1.1
Host: alpha.chan
Date: Mon, 24 Feb 2026 12:00:00 GMT
Digest: SHA-256=X48E9qOokqqrvdts8nOJRJN3OWDUoyWxBf7kbu9DBPE=
Signature: keyId="https://beta.chan/federation/actor#main-key",
           algorithm="rsa-sha256",
           headers="(request-target) host date digest",
           signature="..."
Content-Type: application/activity+json

{ ... activity payload ... }
```

### Key Management

| Key Type | Purpose | Rotation |
|----------|---------|----------|
| RSA-4096 (instance actor) | HTTP Signatures, identity | Annual, manual |
| Ed25519 (per-board) | Board-level signing | On admin action |
| mTLS certs (internal) | Service-to-service | Existing rotation scripts |

### Threat Model

| Threat | Mitigation |
|--------|------------|
| Spoofed instance | HTTP Signature verification + WebFinger key fetch |
| Replay attacks | `Date` header validation (±5 min), nonce for critical actions |
| Content injection | Strict JSON-LD validation, HTML sanitization on ingest |
| Amplification (DDoS via federation) | Per-instance rate limits, shared inbox dedup |
| Media-based attacks | Fetch-and-scan before serving; apply local media pipeline |
| Defederation evasion (new domain) | Manual approval mode, instance age requirements |
| Poisoned thread history (backfill) | Verify signatures on historical events, chain validation |

---

## 13. Privacy Considerations

### Data That NEVER Federates

| Data | Reason |
|------|--------|
| IP addresses (raw or hashed) | Privacy-first architecture; IPs are instance-local |
| Session tokens / cookies | Authentication is instance-local |
| Staff identities | Staff accounts are instance-local |
| User accounts (if registered) | Registration is instance-local |
| Ban records (user-identifying) | Bans are instance-local enforcement |
| Moderation queue details | Internal workflow |
| Private reports (reporter identity) | Reporter anonymity |
| Encryption keys (PII) | XChaCha20 keys are instance-local |
| Consent records | GDPR/CCPA compliance is per-instance |

### Data That MAY Federate (Opt-In)

| Data | Condition | Format |
|------|-----------|--------|
| Poster hash (ID) | Board has `user_ids = true` | 8-char hash (re-derived per instance) |
| Country flag | Board has `country_flags = true` | 2-char ISO code only |
| Tripcode | User chose to use one | Tripcode string only |
| Media hash bans | Operator enables shared blocklist | SHA-256 hash only (no image content) |
| Spam confidence | Operator enables SFS sharing | Numerical score only |

### GDPR/CCPA Under Federation

- Each instance is an **independent data controller**
- Federation agreements (instance peering) constitute **data processing agreements**
- Right to erasure: `Delete` activity propagates tombstones; receiving instances must honor them within their retention policy
- Data portability: 4chan-compatible API provides structured export
- **Poster identity is never personally identifiable** — anonymous posting means no data subject in most cases

---

## 14. Architecture — New Federation Service

Federation integrates as a 7th microservice in the existing Ashchan mesh:

```
╔══════════════════════════════════════════════════════════════╗
║                                                              ║
║  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐     ║
║  │ Gateway  │  │  Auth    │  │  Boards  │  │  Media   │     ║
║  │  9501    │  │  9502    │  │  9503    │  │  9504    │     ║
║  └────┬─────┘  └──────────┘  └────┬─────┘  └────┬─────┘     ║
║       │                           │              │           ║
║       │ mTLS                      │ mTLS         │ mTLS      ║
║       │                           │              │           ║
║  ┌────▼──────────────────────────▼──────────────▼─────┐     ║
║  │              FEDERATION SERVICE                     │     ║
║  │              Port 9507 / mTLS 8443                  │     ║
║  │                                                     │     ║
║  │  ┌─────────────┐  ┌──────────────┐  ┌───────────┐  │     ║
║  │  │ AP Inbox/   │  │ Replication  │  │ Instance  │  │     ║
║  │  │ Outbox      │  │ Engine       │  │ Registry  │  │     ║
║  │  └─────────────┘  └──────────────┘  └───────────┘  │     ║
║  │                                                     │     ║
║  │  ┌─────────────┐  ┌──────────────┐  ┌───────────┐  │     ║
║  │  │ HTTP Sig    │  │ Media Sync   │  │ Mod Relay │  │     ║
║  │  │ Verifier    │  │ (hash-based) │  │ (Flag/Ban)│  │     ║
║  │  └─────────────┘  └──────────────┘  └───────────┘  │     ║
║  │                                                     │     ║
║  └─────────────────────────┬───────────────────────────┘     ║
║                             │                                ║
║  ┌──────────┐  ┌──────────┐│ ┌──────────┐                    ║
║  │  Search  │  │   Mod    ││ │PostgreSQL│                    ║
║  │  9505    │  │  9506    ││ │  Redis   │                    ║
║  └──────────┘  └──────────┘│ │  MinIO   │                    ║
║                             │ └──────────┘                    ║
║                             │                                ║
║           outbound ─────────▼──────── inbound                ║
║                     ActivityPub S2S                          ║
║                    (remote instances)                        ║
║                                                              ║
╚══════════════════════════════════════════════════════════════╝
```

### Service Structure

```
services/federation/
├── app/
│   ├── Controller/
│   │   ├── InboxController.php        # AP inbox (receive activities)
│   │   ├── OutboxController.php       # AP outbox (serve activities)
│   │   ├── ActorController.php        # Serve instance & board actors
│   │   ├── WebFingerController.php    # /.well-known/webfinger
│   │   ├── NodeInfoController.php     # /.well-known/nodeinfo
│   │   └── AdminController.php        # Federation admin panel
│   ├── Service/
│   │   ├── ActivityPubService.php     # Core AP logic
│   │   ├── HttpSignatureService.php   # Sign & verify HTTP signatures
│   │   ├── ReplicationService.php     # Thread sync & backfill
│   │   ├── MediaSyncService.php       # Federated media resolution
│   │   ├── InstanceRegistryService.php # Known instances & trust
│   │   ├── ModerationRelayService.php # Flag/ban propagation
│   │   ├── LivepostBatchService.php   # Livepost batching for federation
│   │   └── FederationPolicyService.php # Allowlist/blocklist enforcement
│   ├── Model/
│   │   ├── FederatedInstance.php
│   │   ├── FederatedBoard.php
│   │   ├── FederatedActivity.php
│   │   ├── ActivityQueue.php
│   │   └── InstanceKey.php
│   └── Process/
│       ├── OutboxWorkerProcess.php    # Async delivery with retry
│       └── BackfillProcess.php        # Background thread backfill
├── config/
│   ├── autoload/
│   │   ├── server.php
│   │   ├── databases.php
│   │   ├── redis.php                  # REDIS_DB=6 (federation)
│   │   └── federation.php            # Federation-specific config
│   └── routes.php
├── bin/hyperf.php
└── composer.json
```

### Event Integration

The federation service consumes existing domain events and produces federation-specific events:

```
┌──────────────────────────────────────────────────────┐
│  Redis Streams (DB 6)                                │
│                                                       │
│  ashchan:events ──────┐                               │
│   • thread.created    │                               │
│   • post.created      ├──► Federation Service         │
│   • media.ingested    │      │                        │
│   • moderation.decision│     │   Maps to ActivityPub  │
│                       │      │   activities and       │
│  ashchan:federation ──┘      │   delivers to peers    │
│   • federation.delivered     │                        │
│   • federation.received      │                        │
│   • federation.failed        │                        │
│   • instance.peered          │                        │
│   • instance.blocked         │                        │
└──────────────────────────────────────────────────────┘
```

---

## 15. Database Schema

### New Tables

```sql
-- ═══════════════════════════════════════════
-- FEDERATION: Instance Registry
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS federated_instances (
    id              BIGSERIAL PRIMARY KEY,
    domain          VARCHAR(255) NOT NULL UNIQUE,
    actor_url       TEXT NOT NULL,
    inbox_url       TEXT NOT NULL,
    outbox_url      TEXT,
    shared_inbox_url TEXT,
    public_key_pem  TEXT NOT NULL,
    software_name   VARCHAR(64),
    software_version VARCHAR(32),
    status          VARCHAR(20) NOT NULL DEFAULT 'pending',
    trust_level     SMALLINT NOT NULL DEFAULT 0,
    last_seen_at    TIMESTAMPTZ,
    last_error      TEXT,
    error_count     INTEGER DEFAULT 0,
    federation_policy JSONB DEFAULT '{}',
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT valid_status CHECK (status IN (
        'pending', 'active', 'suspended', 'blocked', 'unreachable'
    ))
);

CREATE INDEX idx_fed_instance_domain ON federated_instances(domain);
CREATE INDEX idx_fed_instance_status ON federated_instances(status);

-- ═══════════════════════════════════════════
-- FEDERATION: Board Subscriptions
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS federated_board_subscriptions (
    id              BIGSERIAL PRIMARY KEY,
    instance_id     BIGINT NOT NULL REFERENCES federated_instances(id),
    board_slug      VARCHAR(32) NOT NULL,
    direction       VARCHAR(10) NOT NULL,  -- 'inbound' or 'outbound'
    remote_actor_url TEXT NOT NULL,
    replication_mode VARCHAR(20) DEFAULT 'live',
    status          VARCHAR(20) NOT NULL DEFAULT 'pending',
    last_sync_at    TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT valid_direction CHECK (direction IN ('inbound', 'outbound')),
    CONSTRAINT valid_repl_mode CHECK (replication_mode IN (
        'live', 'batch', 'on-demand', 'snapshot'
    )),
    UNIQUE(instance_id, board_slug, direction)
);

CREATE INDEX idx_fed_board_sub_instance ON federated_board_subscriptions(instance_id);
CREATE INDEX idx_fed_board_sub_slug ON federated_board_subscriptions(board_slug);

-- ═══════════════════════════════════════════
-- FEDERATION: Activity Log (inbox/outbox)
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS federated_activities (
    id              BIGSERIAL PRIMARY KEY,
    activity_id     TEXT NOT NULL UNIQUE,    -- AP activity @id
    activity_type   VARCHAR(32) NOT NULL,    -- Create, Update, Delete, Flag, etc.
    actor_url       TEXT NOT NULL,
    object_url      TEXT,
    direction       VARCHAR(10) NOT NULL,    -- 'inbound' or 'outbound'
    instance_id     BIGINT REFERENCES federated_instances(id),
    payload         JSONB NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts        INTEGER DEFAULT 0,
    last_attempt_at TIMESTAMPTZ,
    delivered_at    TIMESTAMPTZ,
    error           TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT valid_act_direction CHECK (direction IN ('inbound', 'outbound'))
);

CREATE INDEX idx_fed_activity_type ON federated_activities(activity_type);
CREATE INDEX idx_fed_activity_status ON federated_activities(status);
CREATE INDEX idx_fed_activity_instance ON federated_activities(instance_id);
CREATE INDEX idx_fed_activity_created ON federated_activities(created_at);

-- ═══════════════════════════════════════════
-- FEDERATION: Instance Keys
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS instance_keys (
    id              BIGSERIAL PRIMARY KEY,
    key_id          TEXT NOT NULL UNIQUE,    -- e.g., actor_url#main-key
    instance_id     BIGINT REFERENCES federated_instances(id),
    public_key_pem  TEXT NOT NULL,
    algorithm       VARCHAR(32) DEFAULT 'rsa-sha256',
    fetched_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at      TIMESTAMPTZ
);

CREATE INDEX idx_instance_key_id ON instance_keys(key_id);

-- ═══════════════════════════════════════════
-- FEDERATION: Remote post mapping
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS federated_post_map (
    id              BIGSERIAL PRIMARY KEY,
    local_post_id   BIGINT REFERENCES posts(id) ON DELETE CASCADE,
    remote_activity_id TEXT NOT NULL,
    remote_post_url TEXT NOT NULL,
    instance_id     BIGINT REFERENCES federated_instances(id),
    lamport_clock   BIGINT DEFAULT 0,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(remote_activity_id)
);

CREATE INDEX idx_fed_post_local ON federated_post_map(local_post_id);
CREATE INDEX idx_fed_post_remote ON federated_post_map(remote_post_url);

-- ═══════════════════════════════════════════
-- FEDERATION: Media hash cache
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS federated_media_cache (
    id              BIGSERIAL PRIMARY KEY,
    sha256_hash     VARCHAR(64) NOT NULL,
    origin_instance_id BIGINT REFERENCES federated_instances(id),
    origin_url      TEXT NOT NULL,
    local_storage_key TEXT,              -- null = not yet fetched
    fetched_at      TIMESTAMPTZ,
    file_size       INTEGER,
    mime_type       VARCHAR(64),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(sha256_hash, origin_instance_id)
);

CREATE INDEX idx_fed_media_hash ON federated_media_cache(sha256_hash);

-- ═══════════════════════════════════════════
-- FEDERATION: Shared blocklists
-- ═══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS federated_blocklist (
    id              BIGSERIAL PRIMARY KEY,
    block_type      VARCHAR(20) NOT NULL,   -- 'media_hash', 'instance', 'pattern'
    value           TEXT NOT NULL,
    reason          TEXT,
    source_instance_id BIGINT REFERENCES federated_instances(id),
    is_active       BOOLEAN DEFAULT true,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_fed_blocklist_type ON federated_blocklist(block_type);
CREATE INDEX idx_fed_blocklist_value ON federated_blocklist(value);
```

---

## 16. Wire Protocol Examples

### Board Follow Request

```http
POST /federation/board/a/inbox HTTP/1.1
Host: alpha.chan
Content-Type: application/activity+json
Signature: keyId="https://beta.chan/federation/actor#main-key", ...

{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://beta.chan/federation/activity/follow-001",
  "type": "Follow",
  "actor": "https://beta.chan/federation/actor",
  "object": "https://alpha.chan/federation/board/a",
  "ashchan:replicationMode": "live",
  "ashchan:capabilities": ["liveposting", "media-dedup"]
}
```

### Accept Follow + Backfill Trigger

```http
POST /federation/inbox HTTP/1.1
Host: beta.chan
Content-Type: application/activity+json

{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://alpha.chan/federation/activity/accept-001",
  "type": "Accept",
  "actor": "https://alpha.chan/federation/board/a",
  "object": "https://beta.chan/federation/activity/follow-001",
  "ashchan:backfillEndpoint": "https://alpha.chan/federation/board/a/outbox?since=2026-02-01"
}
```

### Federated Post (Create)

```http
POST /federation/board/a/inbox HTTP/1.1
Host: beta.chan
Content-Type: application/activity+json

{
  "@context": [
    "https://www.w3.org/ns/activitystreams",
    "https://ashchan.org/ns/federation/v1"
  ],
  "id": "https://alpha.chan/federation/activity/create-post-12388",
  "type": "Create",
  "actor": "https://alpha.chan/federation/board/a",
  "published": "2026-02-24T12:05:30Z",
  "to": ["https://www.w3.org/ns/activitystreams#Public"],
  "cc": ["https://alpha.chan/federation/board/a/followers"],
  "object": {
    "id": "https://alpha.chan/federation/board/a/post/12388",
    "type": "Note",
    "attributedTo": "https://alpha.chan/federation/board/a",
    "inReplyTo": "https://alpha.chan/federation/board/a/post/12345",
    "context": "https://alpha.chan/federation/board/a/thread/12345",
    "published": "2026-02-24T12:05:30Z",
    "content": "This post was federated!",
    "source": {
      "content": "This post was federated!",
      "mediaType": "text/plain"
    },
    "ashchan:postMeta": {
      "no": 12388,
      "name": "Anonymous",
      "sage": false
    },
    "ashchan:causal": {
      "lamport": 47,
      "parents": ["https://alpha.chan/federation/board/a/post/12387"]
    }
  }
}
```

### Livepost Batch Update

```http
POST /federation/board/a/inbox HTTP/1.1
Host: beta.chan
Content-Type: application/activity+json

{
  "@context": [
    "https://www.w3.org/ns/activitystreams",
    "https://ashchan.org/ns/federation/v1"
  ],
  "id": "https://alpha.chan/federation/activity/update-livepost-99",
  "type": "Update",
  "actor": "https://alpha.chan/federation/board/a",
  "object": {
    "id": "https://alpha.chan/federation/board/a/post/12390",
    "type": "Note",
    "content": "I am typing this live and you can se",
    "ashchan:livepost": {
      "editing": true,
      "bodyLength": 36,
      "lineCount": 1,
      "batchSeq": 4
    }
  }
}
```

### Federated Delete (Tombstone)

```http
POST /federation/board/a/inbox HTTP/1.1
Host: beta.chan
Content-Type: application/activity+json

{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://alpha.chan/federation/activity/delete-12388",
  "type": "Delete",
  "actor": "https://alpha.chan/federation/board/a",
  "object": {
    "id": "https://alpha.chan/federation/board/a/post/12388",
    "type": "Tombstone",
    "formerType": "Note",
    "deleted": "2026-02-24T13:00:00Z"
  },
  "ashchan:deletionMeta": {
    "reason": "rule_violation",
    "moderatorAction": true
  }
}
```

---

## 17. Compatibility Matrix

### Interoperability with Existing Fediverse Software

| Software | Read Ashchan | Post to Ashchan | Notes |
|----------|:---:|:---:|-------|
| **Ashchan** (other instance) | ✅ Full | ✅ Full | Native support |
| **Mastodon** | ✅ Partial | ❌ | Can see board posts as toots (via public outbox) |
| **Lemmy** | ✅ Partial | 🔶 Limited | Community ↔ Board mapping possible |
| **Pleroma/Akkoma** | ✅ Partial | ❌ | Can follow board actors |
| **FChannel** | 🔶 Limited | 🔶 Limited | Similar imageboard federation model |
| **Pixelfed** | ✅ Images only | ❌ | Can see media posts |
| **PeerTube** | ❌ | ❌ | Incompatible content model |
| **Misskey/Calckey** | ✅ Partial | ❌ | Can follow board actors |

### Why Limited Interop Is Acceptable

Imageboard federation has unique requirements (anonymous posting, thread-based threading, board-level actors) that don't map cleanly to microblogging federation. The primary goal is **Ashchan-to-Ashchan** federation with Fediverse compatibility as a best-effort bonus.

---

## 18. Migration & Rollout Strategy

### Phase 0: Foundation (Prerequisites)

- [ ] Add `federation_enabled` to `site_settings`
- [ ] Generate instance keypair (RSA-4096)
- [ ] Implement WebFinger (`/.well-known/webfinger`)
- [ ] Implement NodeInfo (`/.well-known/nodeinfo`)
- [ ] Add `federated` boolean column to `boards` table

### Phase 1: Read-Only Federation

- [ ] Scaffold `services/federation/` service
- [ ] Implement Actor endpoints (instance + board actors)
- [ ] Implement outbox serving (read-only, public)
- [ ] Accept Follow requests from remote instances
- [ ] Deliver `Create` activities for new posts to followers
- [ ] Deliver `Delete` activities for deleted posts
- [ ] HTTP Signature signing & verification
- [ ] Instance registry + admin UI

### Phase 2: Write Federation

- [ ] Accept `Create` activities in inbox (remote posts)
- [ ] Validate and store remote posts locally
- [ ] Post number mapping (remote → local canonical number)
- [ ] Federated thread backfill on subscribe
- [ ] Cross-instance reply rendering (`>>12345@alpha.chan`)
- [ ] Lamport clock ordering
- [ ] Outbox delivery worker with retry & dead-letter

### Phase 3: Moderation & Trust

- [ ] `Flag` activity support (federated reports)
- [ ] Instance allowlist/blocklist management
- [ ] Shared media hash blocklist (opt-in)
- [ ] Instance trust scoring
- [ ] Federation admin dashboard
- [ ] Rate limiting per remote instance
- [ ] Defederation workflow

### Phase 4: Media & Performance

- [ ] Content-addressed media sync
- [ ] Lazy media fetch (thumbnail first, full on demand)
- [ ] pHash dedup across federation
- [ ] Batch replication mode
- [ ] Bandwidth monitoring & throttling
- [ ] CDN-aware media URLs

### Phase 5: Liveposting Federation

- [ ] Livepost batch protocol (500ms snapshots)
- [ ] Federated open/close post lifecycle
- [ ] Local fan-out of federated livepost chunks
- [ ] Backpressure on high-volume federation links
- [ ] NekotV federation (synced video across instances)

### Phase 6: Advanced Features

- [ ] Relay server support
- [ ] DNS-SD instance discovery
- [ ] Cross-instance search (federated search queries)
- [ ] Federation health dashboard & metrics
- [ ] Fediverse compatibility layer (Mastodon/Lemmy read)

---

## 19. Open Questions

| # | Question | Options | Leaning |
|---|----------|---------|---------|
| 1 | Should threads or boards be the unit of federation? | Boards (simpler), Threads (more granular) | **Boards** — simpler, mirrors the Matrix room model |
| 2 | How to handle post numbers across instances? | Global numbering, per-instance numbering, dual numbering | **Dual** — canonical (origin) + local display number |
| 3 | Should tripcodes be federation-portable? | Yes (same salt), No (re-derive per instance) | **No** — different salt per instance for security |
| 4 | Maximum federation depth (instance → relay → instance)? | 1 hop, 2 hops, unlimited | **2 hops** — prevents infinite relay chains |
| 5 | Should sage travel across the federation? | Yes, No (local semantics only) | **Yes** — it affects thread ordering, which must converge |
| 6 | How to handle boards with the same slug on different instances? | Namespace (`/a/@alpha.chan`), merge, operator choice | **Namespace** — boards are scoped to their origin |
| 7 | Should we support Mastodon-compatible profile pages for boards? | Yes (HTML for browsers), No (AP only) | **Yes** — low effort, increases discoverability |
| 8 | What happens when origin instance dies permanently? | Mirror becomes new origin, read-only archive, vote | **Operator claims** — mirrors can self-promote to origin |
| 9 | JSON-LD compaction or expansion? | Compact (smaller), Expand (spec-compliant) | **Compact** — bandwidth matters for high-volume boards |
| 10 | Custom AP namespace (`ashchan:`) or reuse existing? | Custom, Reuse Lemmy's, Both | **Custom** — imageboard semantics are unique |

---

## References

- [W3C ActivityPub Specification](https://www.w3.org/TR/activitypub/)
- [W3C Activity Streams 2.0](https://www.w3.org/TR/activitystreams-core/)
- [Matrix Specification (Room DAG)](https://spec.matrix.org/latest/server-server-api/)
- [HTTP Message Signatures (RFC 9421)](https://www.rfc-editor.org/rfc/rfc9421)
- [NodeInfo Protocol](https://nodeinfo.diaspora.software/protocol)
- [WebFinger (RFC 7033)](https://www.rfc-editor.org/rfc/rfc7033)
- [FChannel — Federated Imageboard](https://github.com/FChannel0/FChannel-Server)
- [Lemmy — Federated Link Aggregator](https://github.com/LemmyNet/lemmy)
- [Ashchan Architecture](architecture.md)
- [Ashchan mTLS ServiceMesh](SERVICEMESH.md)
- [Ashchan Liveposting](LIVEPOSTING.md)
- [Ashchan Domain Events](../contracts/events/README.md)
