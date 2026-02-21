# 4chan-Compatible API

Ashchan provides a **read-only JSON API** that outputs data in the **exact [4chan API](https://github.com/4chan/4chan-API) format**, enabling compatibility with existing 4chan-compatible clients, browser extensions, and tools.

## Base URL

All 4chan-compatible endpoints are served from the **Boards/Threads/Posts** service:

```
http://localhost:9503/api/4chan/
```

In production, the API Gateway (port 9501) should proxy these routes.

---

## Endpoints

| Endpoint | URL | Description |
|----------|-----|-------------|
| **boards.json** | `GET /api/4chan/boards.json` | All boards and their settings |
| **threads.json** | `GET /api/4chan/{board}/threads.json` | Thread list with page grouping |
| **catalog.json** | `GET /api/4chan/{board}/catalog.json` | Board catalog with preview replies |
| **Index page** | `GET /api/4chan/{board}/{page}.json` | Threads on a specific index page (1-indexed) |
| **Thread** | `GET /api/4chan/{board}/thread/{no}.json` | Full thread with all posts |
| **archive.json** | `GET /api/4chan/{board}/archive.json` | Array of archived thread OP numbers |

---

## Response Format

### boards.json

Returns all boards and their configuration attributes.

```json
{
  "boards": [
    {
      "board": "a",
      "title": "Anime & Manga",
      "ws_board": 1,
      "per_page": 15,
      "pages": 10,
      "max_filesize": 4194304,
      "max_webm_filesize": 3145728,
      "max_comment_chars": 2000,
      "max_webm_duration": 120,
      "bump_limit": 300,
      "image_limit": 150,
      "cooldowns": {
        "threads": 60,
        "replies": 15,
        "images": 15
      },
      "meta_description": "&quot;/a/ - Anime &amp; Manga&quot; is a board on ashchan.",
      "is_archived": 1
    }
  ]
}
```

### threads.json (Thread List)

A summarized list of all threads grouped by page.

```json
[
  {
    "page": 1,
    "threads": [
      {
        "no": 12345,
        "last_modified": 1771703667,
        "replies": 42
      }
    ]
  }
]
```

### catalog.json

Board catalog with OP attributes and last preview replies, grouped by page.

```json
[
  {
    "page": 1,
    "threads": [
      {
        "no": 12345,
        "resto": 0,
        "now": "02/21/26(Sat)19:54:15",
        "time": 1771703655,
        "name": "Anonymous",
        "sub": "Thread Subject",
        "com": "OP comment HTML...",
        "replies": 42,
        "images": 10,
        "omitted_posts": 37,
        "omitted_images": 8,
        "last_modified": 1771703667,
        "last_replies": [
          {
            "no": 12350,
            "resto": 12345,
            "now": "02/21/26(Sat)20:01:00",
            "time": 1771704060,
            "name": "Anonymous",
            "com": "Reply comment..."
          }
        ],
        "semantic_url": "thread-subject"
      }
    ]
  }
]
```

### Index Page (`/{board}/{page}.json`)

Threads on a specific page with OP and up to 5 preview replies.

```json
{
  "threads": [
    {
      "posts": [
        {
          "no": 12345,
          "resto": 0,
          "now": "02/21/26(Sat)19:54:15",
          "time": 1771703655,
          "name": "Anonymous",
          "sub": "Thread Subject",
          "com": "OP comment...",
          "replies": 42,
          "images": 10,
          "last_modified": 1771703667,
          "unique_ips": 15,
          "omitted_posts": 37,
          "omitted_images": 8,
          "semantic_url": "thread-subject"
        },
        {
          "no": 12350,
          "resto": 12345,
          "now": "02/21/26(Sat)20:01:00",
          "time": 1771704060,
          "name": "Anonymous",
          "com": "Preview reply..."
        }
      ]
    }
  ]
}
```

### Thread (`/{board}/thread/{no}.json`)

Full thread with OP and all replies.

```json
{
  "posts": [
    {
      "no": 12345,
      "resto": 0,
      "sticky": 1,
      "closed": 1,
      "now": "02/21/26(Sat)19:54:15",
      "time": 1771703655,
      "name": "Moderator",
      "capcode": "mod",
      "sub": "Sticky Thread",
      "com": "OP comment HTML...",
      "tim": 1771703655001,
      "filename": "image",
      "ext": ".png",
      "fsize": 516657,
      "md5": "uZUeZeB14FVR+Mc2ScHvVA==",
      "w": 800,
      "h": 600,
      "tn_w": 250,
      "tn_h": 187,
      "replies": 42,
      "images": 10,
      "unique_ips": 15,
      "bumplimit": 1,
      "imagelimit": 1,
      "semantic_url": "sticky-thread"
    },
    {
      "no": 12346,
      "resto": 12345,
      "now": "02/21/26(Sat)19:55:00",
      "time": 1771703700,
      "name": "Anonymous",
      "trip": "!abcdef1234",
      "com": "Reply with <span class=\"quote\">&gt;greentext</span>"
    }
  ]
}
```

### archive.json

Simple array of archived thread OP numbers.

```json
[571958, 572866, 54195, 574342]
```

---

## Post Object Fields

All post objects use the exact 4chan field names:

| Field | Type | Appears | Description |
|-------|------|---------|-------------|
| `no` | `integer` | always | Post ID |
| `resto` | `integer` | always | Thread ID (0 for OP) |
| `now` | `string` | always | `MM/DD/YY(Day)HH:MM:SS` formatted time |
| `time` | `integer` | always | UNIX timestamp |
| `name` | `string` | always | Poster name (default: `Anonymous`) |
| `trip` | `string` | if set | Tripcode (`!tripcode`) |
| `capcode` | `string` | if set | Capcode (`mod`, `admin`, etc.) |
| `country` | `string` | if enabled | ISO 3166-1 alpha-2 country code |
| `country_name` | `string` | if enabled | Country name |
| `sub` | `string` | if set | Subject |
| `com` | `string` | if set | Comment (HTML) |
| `tim` | `integer` | if attachment | Unix timestamp + microtime |
| `filename` | `string` | if attachment | Original filename (no extension) |
| `ext` | `string` | if attachment | File extension (`.jpg`, `.png`, etc.) |
| `fsize` | `integer` | if attachment | File size in bytes |
| `md5` | `string` | if attachment | Base64 MD5 hash |
| `w` | `integer` | if attachment | Image width |
| `h` | `integer` | if attachment | Image height |
| `tn_w` | `integer` | if attachment | Thumbnail width |
| `tn_h` | `integer` | if attachment | Thumbnail height |
| `spoiler` | `integer` | if spoilered | `1` if image is spoilered |
| `sticky` | `integer` | OP only | `1` if thread is stickied |
| `closed` | `integer` | OP only | `1` if thread is closed |
| `archived` | `integer` | OP only | `1` if thread is archived |
| `archived_on` | `integer` | OP only | UNIX timestamp of archival |
| `replies` | `integer` | OP only | Total reply count |
| `images` | `integer` | OP only | Total image count |
| `unique_ips` | `integer` | OP only | Unique poster count |
| `bumplimit` | `integer` | OP only | `1` if bump limit reached |
| `imagelimit` | `integer` | OP only | `1` if image limit reached |
| `omitted_posts` | `integer` | index/catalog | Omitted reply count |
| `omitted_images` | `integer` | index/catalog | Omitted image count |
| `last_replies` | `array` | catalog only | Array of preview reply objects |
| `last_modified` | `integer` | OP/catalog | UNIX timestamp of last modification |
| `semantic_url` | `string` | OP only | SEO-friendly URL slug |

## Board Object Fields

| Field | Type | Description |
|-------|------|-------------|
| `board` | `string` | Board slug (`a`, `b`, `g`, etc.) |
| `title` | `string` | Board title |
| `ws_board` | `integer` | `1` = worksafe, `0` = NSFW |
| `per_page` | `integer` | Threads per index page |
| `pages` | `integer` | Number of index pages |
| `max_filesize` | `integer` | Max file size (bytes) |
| `max_webm_filesize` | `integer` | Max WebM file size (bytes) |
| `max_comment_chars` | `integer` | Max comment length |
| `max_webm_duration` | `integer` | Max WebM duration (seconds) |
| `bump_limit` | `integer` | Bump limit |
| `image_limit` | `integer` | Image limit |
| `cooldowns` | `object` | `{threads, replies, images}` cooldowns in seconds |
| `meta_description` | `string` | SEO description |
| `is_archived` | `integer` | `1` if archiving is enabled |
| `text_only` | `integer` | `1` if image posting is disabled |
| `require_subject` | `integer` | `1` if OPs require a subject |

---

## Differences from 4chan

This API is **read-only** — it egresses data only. Post creation uses the existing ashchan API (`/api/v1/...`).

| Aspect | 4chan | Ashchan |
|--------|------|---------|
| Domain | `a.4cdn.org` | `localhost:9503/api/4chan/` |
| Media URLs | `i.4cdn.org/{board}/{tim}{ext}` | Original `media_url` from uploads service |
| Static content | `s.4cdn.org` | N/A |
| Post creation | HTML form POST | JSON API (`/api/v1/`) |
| `filedeleted` | Supported | Posts with deleted files return no attachment fields |
| `custom_spoiler` | Board-specific spoiler images | Not implemented |
| `board_flags` | Per-board custom flags | Not implemented |
| `user_ids` | Poster ID tags | Not implemented |

---

## Client Compatibility

This API is designed to work with clients that consume the 4chan API format, including:

- Custom frontends and mobile apps
- Browser extensions (4chan X, etc.)
- Archival tools and scrapers
- Third-party API consumers

Clients should point their API base URL to `/api/4chan/` instead of `a.4cdn.org`.

---

## Implementation

| File | Description |
|------|-------------|
| `app/Service/FourChanApiService.php` | Data transformation service (ashchan → 4chan format) |
| `app/Controller/FourChanApiController.php` | Read-only HTTP controller |
| `config/routes.php` | Route registration under `/api/4chan/` |
