# SERVICE_RESPONSIBILITIES.md

# Service Responsibilities

**Service:** boards-threads-posts  
**Last Updated:** 2026-02-28

This document describes the responsibilities of each service class in the Boards/Threads/Posts service.

## Service Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                     Service Layer                                │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌──────────────────┐  ┌──────────────────┐  ┌───────────────┐ │
│  │  BoardService    │  │ FourChanApi      │  │  Content      │ │
│  │                  │  │ Service          │  │  Formatter    │ │
│  │  - Board CRUD    │  │                  │  │               │ │
│  │  - Thread CRUD   │  │  - 4chan API     │  │  - Markup     │ │
│  │  - Post CRUD     │  │    transform     │  │  - Greentext  │ │
│  │  - Liveposting   │  │  - Format compat │  │  - Quotes     │ │
│  │  - Moderation    │  │                  │  │  - Tripcodes  │ │
│  └──────────────────┘  └──────────────────┘  └───────────────┘ │
│                                                                  │
│  ┌──────────────────┐  ┌──────────────────┐  ┌───────────────┐ │
│  │  PiiEncryption   │  │  IpRetention     │  │  SiteConfig   │ │
│  │  Service         │  │  Service         │  │  Service      │ │
│  │                  │  │                  │  │               │ │
│  │  - XChaCha20     │  │  - Auto-purge    │  │  - Settings   │ │
│  │  - Encrypt PII   │  │  - 30-day IP     │  │  - Redis cache│ │
│  │  - Decrypt PII   │  │  - Audit log     │  │  - Type conv  │ │
│  └──────────────────┘  └──────────────────┘  └───────────────┘ │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## BoardService

**File:** `app/Service/BoardService.php`

### Primary Responsibilities

Core business logic for all board, thread, and post operations.

### Methods

#### Board Operations

| Method | Responsibility | Cache |
|--------|---------------|-------|
| `listBoards()` | List active boards | 300s Redis |
| `getBoard($slug)` | Get board by slug | 300s Redis |
| `getBlotter()` | Get site announcements | 120s Redis |
| `listAllBoards()` | List all boards (admin) | None |
| `createBoard($data)` | Create new board | Invalidate |
| `updateBoard($board, $data)` | Update board settings | Invalidate |
| `deleteBoard($board)` | Delete board (cascade) | Invalidate |

#### Thread Operations

| Method | Responsibility | Cache |
|--------|---------------|-------|
| `getThreadIndex($board, $page)` | Paginated thread list | None |
| `getThread($id)` | Full thread with posts | 120s Redis |
| `getCatalog($board)` | Board catalog | 60s Redis |
| `getArchive($board)` | Archived threads | None |
| `createThread($board, $data)` | Create thread + OP | Invalidate |
| `toggleThreadOption($id, $opt)` | Toggle sticky/lock | Invalidate |

#### Post Operations

| Method | Responsibility | Cache |
|--------|---------------|-------|
| `createPost($thread, $data)` | Create reply post | Invalidate |
| `deletePost($id, $pwd)` | User delete (password) | Invalidate |
| `getPostsAfter($threadId, $afterId)` | New posts since ID | None |
| `staffDeletePost($id)` | Staff delete (no password) | Invalidate |
| `toggleSpoiler($id)` | Toggle image spoiler | Invalidate |

#### Liveposting Operations

| Method | Responsibility | Cache |
|--------|---------------|-------|
| `createOpenPost($thread, $data)` | Allocate editing post | None |
| `closeOpenPost($id)` | Finalize post | Invalidate |
| `setOpenBody($id, $body)` | Update body (debounced) | None |
| `reclaimPost($id, $pwd)` | Reclaim after disconnect | None |
| `closeExpiredPosts()` | Close timed-out posts | Invalidate |

#### Moderation Operations

| Method | Responsibility |
|--------|---------------|
| `getThreadIps($threadId)` | Get IP hashes for thread |
| `getDecryptedIp($postId)` | Get decrypted IP (admin) |
| `getPostsByIpHash($hash, $limit)` | Find posts by IP hash |

#### Helper Methods

| Method | Responsibility |
|--------|---------------|
| `formatPostOutput()` | Format post for API |
| `generatePosterId()` | 8-char deterministic ID |
| `generateGlobalIpHash()` | Cross-thread IP hash |
| `resolveCountry()` | GeoIP country lookup |
| `allocateBoardPostNo()` | Per-board post number |
| `pruneThreads()` | Archive old threads |

### Dependencies

- `ContentFormatter` - Markup parsing
- `Redis` - Caching
- `PiiEncryptionServiceInterface` - IP encryption
- `EventPublisherInterface` - CloudEvents
- `SiteConfigServiceInterface` - Configuration

---

## FourChanApiService

**File:** `app/Service/FourChanApiService.php`

### Primary Responsibilities

Transform internal data structures into 4chan API-compatible JSON format.

### Methods

#### API Endpoints

| Method | 4chan Endpoint | Description |
|--------|---------------|-------------|
| `getBoards()` | `/boards.json` | Board list |
| `getThreadList($board)` | `/{board}/threads.json` | Thread stubs |
| `getCatalog($board)` | `/{board}/catalog.json` | Full catalog |
| `getIndexPage($board, $page)` | `/{board}/{page}.json` | Index page |
| `getThread($board, $no)` | `/{board}/thread/{no}.json` | Full thread |
| `getArchive($board)` | `/{board}/archive.json` | Archive list |

#### Transformers

| Method | Responsibility |
|--------|---------------|
| `formatPost4chan()` | Post → 4chan JSON |
| `formatBoard()` | Board → 4chan JSON |

#### Helpers

| Method | Responsibility |
|--------|---------------|
| `format4chanTime()` | MM/DD/YY(Day)HH:MM:SS |
| `generateTim()` | Timestamp + microtime |
| `parseDimensions()` | "WxH" → dimensions |
| `generateSemanticUrl()` | SEO-friendly slug |
| `getUniqueIpCount()` | Unique poster count |
| `removeNulls()` | Omit null fields |
| `countryName()` | ISO code → name |

### Configuration

| Setting | Default | Description |
|---------|---------|-------------|
| `fourchan_per_page` | 15 | Threads per page |
| `fourchan_max_pages` | 10 | Maximum pages |
| `fourchan_preview_replies` | 5 | Preview replies |
| `fourchan_catalog_replies` | 5 | Catalog last replies |

---

## ContentFormatter

**File:** `app/Service/ContentFormatter.php`

### Primary Responsibilities

Convert raw post text into formatted HTML with markup support.

### Methods

| Method | Responsibility |
|--------|---------------|
| `format($raw)` | Parse markup → HTML |
| `extractQuotedIds($raw)` | Extract >>references |
| `parseNameTrip($name)` | Parse name#tripcode |

### Supported Markup

| Markup | Syntax | Output |
|--------|--------|--------|
| Greentext | `>text` | `<span class="quote">` |
| Quote link | `>>12345` | `<a href="#p12345">` |
| Cross-board | `>>>/b/` | `<a href="/b/">` |
| Bold | `**text**` | `<b>text</b>` |
| Italic | `*text*` | `<i>text</i>` |
| Underline | `__text__` | `<u>text</u>` |
| Strikethrough | `~~text~~` | `<s>text</s>` |
| Spoiler | `[spoiler]t[/spoiler]` | `<s>t</s>` |
| Code | `[code]c[/code]` | `<pre class="prettyprint">` |
| URL | `https://...` | `<a href="..." rel="noopener">` |

### Tripcode Algorithm

```
Name#password → Name !tripcode

1. Split at # character
2. Take first 2 chars of password after #
3. Sanitize salt to .-z range
4. Map special chars to A-F
5. crypt() with salt, take last 10 chars
```

---

## PiiEncryptionService

**File:** `app/Service/PiiEncryptionService.php`

### Primary Responsibilities

Encrypt/decrypt personally identifiable information at rest.

### Encryption Details

| Property | Value |
|----------|-------|
| Algorithm | XChaCha20-Poly1305 (IETF) |
| Key Derivation | BLAKE2b |
| Nonce Size | 24 bytes |
| Tag Size | 16 bytes |
| Format | `enc:` + base64(nonce || ciphertext || tag) |

### Methods

| Method | Responsibility |
|--------|---------------|
| `isEnabled()` | Check if key configured |
| `encrypt($plaintext)` | Encrypt PII value |
| `decrypt($ciphertext)` | Decrypt PII value |
| `encryptIfNeeded($value)` | Encrypt if not already |
| `wipe(&$value)` | Secure memory clear |
| `generateKey()` | Generate new key |

### Key Hierarchy

```
PII_ENCRYPTION_KEY (env var)
        │
        ▼ (BLAKE2b)
   KEK (32 bytes)
        │
        │ (In current implementation, KEK = DEK)
        ▼
   DEK (Data Encryption Key)
        │
        ▼ (XChaCha20-Poly1305)
   Encrypted PII
```

---

## IpRetentionService

**File:** `app/Service/IpRetentionService.php`

### Primary Responsibilities

Automated PII data retention enforcement.

### Retention Schedule

| Data Type | Retention | Action |
|-----------|-----------|--------|
| Post IP addresses | 30 days | Nullify |
| Post email addresses | 30 days | Nullify |
| Flood log entries | 24 hours | Delete |

### Methods

| Method | Responsibility |
|--------|---------------|
| `runAll()` | Execute all cleanup jobs |
| `purgePostIps()` | Nullify old IPs |
| `purgePostEmails()` | Nullify old emails |
| `purgeFloodLog()` | Delete old flood logs |
| `logRetentionAction()` | Audit trail logging |

### Execution

Scheduled via cron or Hyperf crontab:

```bash
# Daily at 3 AM
0 3 * * * php /path/to/bin/hyperf.php pii:cleanup
```

---

## SiteConfigService

**File:** `app/Service/SiteConfigService.php`

### Primary Responsibilities

Database-backed site configuration with Redis caching.

### Caching Strategy

| Level | Storage | TTL |
|-------|---------|-----|
| L1 | In-memory (per-request) | Request lifetime |
| L2 | Redis | 60 seconds |
| L3 | Database (`site_settings`) | Persistent |

### Methods

| Method | Responsibility |
|--------|---------------|
| `get($key, $default)` | Get string value |
| `getInt($key, $default)` | Get integer value |
| `getFloat($key, $default)` | Get float value |
| `getBool($key, $default)` | Get boolean value |
| `getList($key, $default)` | Get comma-separated list |
| `loadAll()` | Load all settings |

### Boolean Recognition

True values (case-insensitive): `true`, `1`, `yes`, `on`

---

## Interface Contracts

### PiiEncryptionServiceInterface

**File:** `app/Service/PiiEncryptionServiceInterface.php`

Contract for PII encryption allowing test doubles.

### SiteConfigServiceInterface

**File:** `app/Service/SiteConfigServiceInterface.php`

Contract for configuration access allowing alternative implementations.

---

## Cross-Service Communication

### CloudEvents Published

| Event | Publisher | Payload |
|-------|-----------|---------|
| `thread.created` | BoardService | board_id, thread_id, op_post_id |
| `post.created` | BoardService | board_id, thread_id, post_id, content |
| `livepost.opened` | BoardService | board_id, thread_id, post_id, ip_hash |
| `livepost.closed` | BoardService | board_id, thread_id, post_id, final_body |
| `livepost.expired` | BoardService | board_id, thread_id, post_id, reason |

---

## Security Boundaries

| Service | Security Responsibility |
|---------|------------------------|
| BoardService | IP encryption, password hashing, memory wiping |
| PiiEncryptionService | AEAD encryption, secure key handling |
| IpRetentionService | Automated PII deletion, audit logging |
| PostgresConnector | SQL injection prevention via parameter validation |
| AppExceptionHandler | Error message sanitization |
