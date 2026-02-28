# Boards/Threads/Posts Service API Reference

**Last Updated:** 2026-02-28
**Base URL:** `/api/v1`
**4chan API Base:** `/api/4chan`

## Overview

This document describes the RESTful API endpoints provided by the Boards/Threads/Posts service. The service provides both a native REST API and a 4chan-compatible API layer.

## Authentication

Staff endpoints require authentication via headers forwarded by the API Gateway:

| Header | Description |
|--------|-------------|
| `X-Staff-Level` | Staff level: `admin` (3), `manager` (2), `mod` (1), `janitor` (0) |
| `X-Forwarded-For` | Client IP address (for flood prevention) |
| `X-Real-IP` | Alternative client IP header |

## Common Response Codes

| Code | Meaning | Description |
|------|---------|-------------|
| `200` | OK | Request succeeded |
| `201` | Created | Resource created successfully |
| `400` | Bad Request | Invalid input parameters |
| `403` | Forbidden | Insufficient permissions |
| `404` | Not Found | Resource not found |
| `409` | Conflict | Resource already exists |
| `422` | Unprocessable Entity | Business logic validation failed |
| `429` | Too Many Requests | Rate limit exceeded |
| `500` | Internal Server Error | Server error |

---

## Health Endpoints

### Health Check

**Endpoint:** `GET /health`

**Authentication:** Not required

Returns a simple health check response for load balancers.

**Response:**

```json
{
  "status": "ok"
}
```

---

## Board Endpoints

### List All Boards

**Endpoint:** `GET /api/v1/boards`

**Authentication:** Not required

Returns all active (non-archived, non-staff-only) boards.

**Response:**

```json
{
  "boards": [
    {
      "id": 1,
      "slug": "b",
      "name": "b",
      "title": "Random",
      "subtitle": "The stories and information posted here are artistic works of fiction and falsehood.",
      "category": "Misc",
      "nsfw": true,
      "max_threads": 200,
      "bump_limit": 300,
      "image_limit": 150,
      "cooldown_seconds": 60,
      "text_only": false,
      "require_subject": false,
      "staff_only": false,
      "user_ids": true,
      "country_flags": false,
      "rules": "",
      "archived": false
    }
  ]
}
```

**Caching:** Cached in Redis for 300 seconds.

---

### Get Single Board

**Endpoint:** `GET /api/v1/boards/{slug}`

**Authentication:** Not required

Returns a single board by slug.

**Response (200):**

```json
{
  "board": {
    "id": 1,
    "slug": "b",
    "name": "b",
    "title": "Random",
    "subtitle": "...",
    "category": "Misc",
    "nsfw": true,
    "max_threads": 200,
    "bump_limit": 300,
    "image_limit": 150,
    "cooldown_seconds": 60,
    "text_only": false,
    "require_subject": false,
    "staff_only": false,
    "user_ids": true,
    "country_flags": false,
    "rules": "",
    "archived": false
  }
}
```

**Response (404):**

```json
{
  "error": "Board not found"
}
```

---

### Get Blotter

**Endpoint:** `GET /api/v1/blotter`

**Authentication:** Not required

Returns recent site announcements.

**Response:**

```json
{
  "blotter": [
    {
      "id": 1,
      "content": "Site maintenance scheduled for...",
      "is_important": true,
      "created_at": 1709136000
    }
  ]
}
```

**Caching:** Cached in Redis for 120 seconds.

---

## Thread Endpoints

### Get Thread Index

**Endpoint:** `GET /api/v1/boards/{slug}/threads?page=1`

**Authentication:** Not required

Returns paginated thread list with OP and latest 5 replies per thread.

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number (1-indexed) |

**Response:**

```json
{
  "threads": [
    {
      "id": 12345,
      "sticky": false,
      "locked": false,
      "reply_count": 42,
      "image_count": 15,
      "bumped_at": "2026-02-28T12:00:00Z",
      "created_at": "2026-02-27T12:00:00Z",
      "op": {
        "id": 12345,
        "author_name": "Anonymous",
        "subject": "Thread subject",
        "content": "OP content here",
        "content_html": "<span class=\"quote\">&gt;greentext</span><br>OP content here",
        "media_url": "https://...",
        "thumb_url": "https://...",
        "media_filename": "image.jpg",
        "media_size": 123456,
        "media_dimensions": "1920x1080",
        "spoiler_image": false,
        "created_at": "2026-02-27T12:00:00Z"
      },
      "latest_replies": [
        {
          "id": 12387,
          "author_name": "Anonymous",
          "content": "Reply content",
          "content_html": "Reply content",
          "media_url": "https://...",
          "thumb_url": "https://...",
          "created_at": "2026-02-28T11:00:00Z"
        }
      ],
      "omitted_posts": 37,
      "omitted_images": 10
    }
  ],
  "page": 1,
  "total_pages": 10,
  "total": 150
}
```

**Staff Enhancement:** When `X-Staff-Level: mod+` is present, posts include `ip_hash` field.

---

### Get Full Thread

**Endpoint:** `GET /api/v1/boards/{slug}/threads/{id}`

**Authentication:** Not required

Returns a complete thread with all posts.

**Response:**

```json
{
  "thread_id": 12345,
  "board_id": 1,
  "sticky": false,
  "locked": false,
  "archived": false,
  "reply_count": 42,
  "image_count": 15,
  "op": {
    "id": 12345,
    "author_name": "Anonymous",
    "subject": "Thread subject",
    "content": "OP content",
    "content_html": "...",
    "media_url": "https://...",
    "thumb_url": "https://...",
    "created_at": "2026-02-27T12:00:00Z",
    "backlinks": [12350, 12360]
  },
  "replies": [
    {
      "id": 12346,
      "author_name": "Anonymous",
      "content": "First reply",
      "content_html": "...",
      "media_url": null,
      "thumb_url": null,
      "created_at": "2026-02-27T12:01:00Z",
      "backlinks": []
    }
  ]
}
```

**Caching:** Cached in Redis for 120 seconds (non-staff requests only).

---

### Get Catalog

**Endpoint:** `GET /api/v1/boards/{slug}/catalog`

**Authentication:** Not required

Returns all threads with OP preview data for catalog view.

**Response:**

```json
{
  "threads": [
    {
      "id": 12345,
      "sticky": false,
      "locked": false,
      "reply_count": 42,
      "image_count": 15,
      "bumped_at": 1709136000,
      "created_at": 1709049600,
      "op": {
        "subject": "Thread subject",
        "content_preview": "Preview of OP content...",
        "thumb_url": "https://..."
      }
    }
  ]
}
```

**Caching:** Cached in Redis for 60 seconds.

---

### Get Archive

**Endpoint:** `GET /api/v1/boards/{slug}/archive`

**Authentication:** Not required

Returns archived thread IDs.

**Response:**

```json
{
  "archived_threads": [
    {
      "id": 10001,
      "excerpt": "Archived thread subject",
      "excerpt_lower": "archived thread subject"
    }
  ]
}
```

---

### Create Thread

**Endpoint:** `POST /api/v1/boards/{slug}/threads`

**Authentication:** Not required (user posts anonymously)

Creates a new thread. Media must be uploaded separately via the media service.

**Request Body:**

| Field | Type | Required | Max Length | Description |
|-------|------|----------|------------|-------------|
| `name` | string | No | 100 | Display name (optional tripcode: `Name#pass`) |
| `email` | string | No | - | Email (use `sage` to not bump) |
| `sub` | string | No | 100 | Subject |
| `com` | string | Yes* | 20000 | Comment content |
| `pwd` | string | No | - | Delete password |
| `spoiler` | bool | No | - | Apply spoiler to image |
| `media_url` | string | Yes* | - | Uploaded media URL |
| `thumb_url` | string | Yes* | - | Thumbnail URL |
| `media_filename` | string | No | - | Original filename |
| `media_size` | int | No | - | File size in bytes |
| `media_dimensions` | string | No | - | Dimensions (e.g., `1920x1080`) |
| `media_hash` | string | No | - | MD5 hash of media |

*`com` required for text-only boards; `media_url` required for image boards.

**Example Request:**

```json
{
  "name": "Anonymous",
  "email": "",
  "sub": "My thread subject",
  "com": "This is the OP content with >greentext",
  "pwd": "deletepass123",
  "spoiler": false,
  "media_url": "https://media.ashchan.com/abc123.jpg",
  "thumb_url": "https://media.ashchan.com/thumb_abc123.jpg",
  "media_filename": "image.jpg",
  "media_size": 123456,
  "media_dimensions": "1920x1080",
  "media_hash": "d41d8cd98f00b204e9800998ecf8427e"
}
```

**Success Response (201):**

```json
{
  "id": 12345,
  "thread_id": 12345,
  "board_post_no": 1234567
}
```

**Error Responses:**

| Code | Response | Description |
|------|----------|-------------|
| `400` | `{ "error": "Board not found" }` | Invalid board slug |
| `400` | `{ "error": "An image is required" }` | Image board without media |
| `400` | `{ "error": "A comment is required" }` | Text-only board without comment |
| `400` | `{ "error": "Name must not exceed 100 characters" }` | Name too long |
| `400` | `{ "error": "Subject must not exceed 100 characters" }` | Subject too long |
| `400` | `{ "error": "Comment must not exceed 20000 characters" }` | Comment too long |
| `422` | `{ "error": "Thread is locked" }` | Thread locked by staff |
| `422` | `{ "error": "Thread is archived" }` | Thread archived |
| `500` | `{ "error": "An internal error occurred" }` | Server error |

---

### Reply to Thread

**Endpoint:** `POST /api/v1/boards/{slug}/threads/{id}/posts`

**Authentication:** Not required

Creates a reply to an existing thread.

**Request Body:** Same as Create Thread.

**Success Response (201):**

```json
{
  "id": 12387,
  "thread_id": 12345,
  "board_post_no": 1234609
}
```

**Error Responses:**

| Code | Response | Description |
|------|----------|-------------|
| `404` | `{ "error": "Thread not found" }` | Thread doesn't exist |
| `422` | `{ "error": "Thread is locked" }` | Thread locked by staff |
| `422` | `{ "error": "Thread is archived" }` | Thread archived |
| `400` | `{ "error": "An image is required" }` | Image required |

---

### Get New Posts

**Endpoint:** `GET /api/v1/boards/{slug}/threads/{id}/posts?after=0`

**Authentication:** Not required

Returns posts created after a given post ID (for live updates).

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `after` | int | 0 | Get posts with ID > this value |

**Response:**

```json
{
  "posts": [
    {
      "id": 12388,
      "author_name": "Anonymous",
      "content": "New post content",
      "content_html": "...",
      "media_url": "https://...",
      "thumb_url": "https://...",
      "created_at": "2026-02-28T12:05:00Z"
    }
  ]
}
```

---

## Post Actions

### Delete Own Posts

**Endpoint:** `POST /api/v1/posts/delete`

**Authentication:** Not required

Deletes posts using delete password.

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `ids` | int[] | Yes | Array of post IDs to delete |
| `password` | string | Yes | Delete password |
| `image_only` | bool | No | Delete only image, keep post |

**Example Request:**

```json
{
  "ids": [12345, 12346],
  "password": "deletepass123",
  "image_only": false
}
```

**Success Response:**

```json
{
  "deleted": 2
}
```

---

### Bulk Post Media Lookup

**Endpoint:** `POST /api/v1/posts/lookup`

**Authentication:** Required (staff: mod+)

Bulk lookup for report queue.

**Request Body:**

```json
{
  "posts": [
    {"board": "b", "no": 12345},
    {"board": "g", "no": 67890}
  ]
}
```

**Response:**

```json
{
  "results": {
    "b:12345": {
      "thumb_url": "https://...",
      "media_url": "https://...",
      "media_filename": "image.jpg",
      "media_dimensions": "1920x1080",
      "spoiler_image": false,
      "sub": "Thread subject",
      "com": "Post content"
    }
  }
}
```

**Limits:** Maximum 50 lookups per request.

---

### Staff: Posts by IP Hash

**Endpoint:** `GET /api/v1/posts/by-ip-hash/{hash}`

**Authentication:** Required (staff: mod+)

Returns posts from a specific IP hash.

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `hash` | string | 16-character hex IP hash |

**Query Parameters:**

| Parameter | Type | Default | Max | Description |
|-----------|------|---------|-----|-------------|
| `limit` | int | 100 | 500 | Number of posts to return |

**Response:**

```json
{
  "ip_hash": "abc123def456789",
  "count": 5,
  "posts": [
    {
      "id": 12345,
      "board_slug": "b",
      "thread_id": 12340,
      "content": "Post content",
      "created_at": "2026-02-28T12:00:00Z"
    }
  ]
}
```

---

### Staff: Delete Post

**Endpoint:** `DELETE /api/v1/boards/{slug}/posts/{id}`

**Authentication:** Required (staff)

Deletes a post by staff.

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `file_only` | bool | Delete only image, keep post |

**Response:**

```json
{
  "success": true
}
```

---

### Staff: Thread Options

**Endpoint:** `POST /api/v1/boards/{slug}/threads/{id}/options`

**Authentication:** Required (staff)

Toggles thread options (sticky, lock, permasage).

**Request Body:**

| Field | Type | Required | Values |
|-------|------|----------|--------|
| `option` | string | Yes | `sticky`, `lock`, `permasage` |

**Response:**

```json
{
  "success": true
}
```

---

### Staff: Toggle Spoiler

**Endpoint:** `POST /api/v1/boards/{slug}/posts/{id}/spoiler`

**Authentication:** Required (staff)

Toggles spoiler flag on a post's image.

**Response:**

```json
{
  "success": true
}
```

---

### Staff: Thread IP Lookup

**Endpoint:** `GET /api/v1/boards/{slug}/threads/{id}/ips`

**Authentication:** Required (staff: mod+)

Returns IP hash data for all posts in a thread.

**Response:**

```json
{
  "thread_id": 12345,
  "posts": [
    {
      "id": 12345,
      "ip_hash": "abc123...",
      "created_at": "2026-02-28T12:00:00Z"
    }
  ]
}
```

---

## Liveposting Endpoints

These endpoints are called by the API Gateway's WebSocket handlers via mTLS.

### Open Post

**Endpoint:** `POST /api/v1/boards/{slug}/threads/{id}/open-post`

**Authentication:** mTLS (Gateway only)

Allocates an open (editing) post for liveposting.

**Headers:**

| Header | Description |
|--------|-------------|
| `X-Forwarded-For` | Client IP |
| `X-Real-IP` | Alternative client IP |

**Request Body:**

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Display name |
| `body` | string | Initial post body |

**Success Response (201):**

```json
{
  "post_id": 12389,
  "edit_password": "randompassword123",
  "expires_at": "2026-02-28T12:30:00Z"
}
```

**Error Responses:**

| Code | Response | Description |
|------|----------|-------------|
| `404` | `{ "error": "Board not found" }` | Invalid board |
| `404` | `{ "error": "Thread not found" }` | Invalid thread |
| `400` | `{ "error": "Name too long (max 100 characters)" }` | Name exceeds limit |
| `422` | `{ "error": "..." }` | Business logic error |

---

### Close Post

**Endpoint:** `POST /api/v1/posts/{id}/close`

**Authentication:** mTLS (Gateway only)

Finalizes an open post, copying body to posts.content.

**Response:**

```json
{
  "post_id": 12389,
  "content_html": "Formatted HTML content"
}
```

---

### Update Post Body

**Endpoint:** `PUT /api/v1/posts/{id}/body`

**Authentication:** mTLS (Gateway only)

Updates the body of an open post (debounced persistence).

**Request Body:**

| Field | Type | Description |
|-------|------|-------------|
| `body` | string | New post body |

**Response:**

```json
{
  "ok": true
}
```

---

### Reclaim Post

**Endpoint:** `POST /api/v1/posts/{id}/reclaim`

**Authentication:** mTLS (Gateway only)

Reclaims an open post after disconnection.

**Request Body:**

| Field | Type | Description |
|-------|------|-------------|
| `password` | string | Edit password from open-post response |

**Response:**

```json
{
  "post_id": 12389,
  "edit_password": "randompassword123"
}
```

---

### Close Expired Posts

**Endpoint:** `POST /api/v1/posts/close-expired`

**Authentication:** mTLS (Gateway only)

Force-closes all expired open posts. Called by scheduler.

**Response:**

```json
{
  "closed": 5
}
```

---

## Admin Board Management

### List All Boards (Admin)

**Endpoint:** `GET /api/v1/admin/boards`

**Authentication:** Required (admin)

Returns all boards including archived.

**Response:**

```json
{
  "boards": [...]
}
```

---

### Get Board (Admin)

**Endpoint:** `GET /api/v1/admin/boards/{slug}`

**Authentication:** Required (admin)

Returns single board for editing.

---

### Create Board

**Endpoint:** `POST /api/v1/admin/boards`

**Authentication:** Required (admin)

Creates a new board.

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `slug` | string | Yes | 1-32 lowercase alphanumeric |
| `title` | string | Yes | Board title |
| `subtitle` | string | No | Board subtitle |
| `category` | string | No | Category name |
| `nsfw` | bool | No | NSFW flag |
| `max_threads` | int | No | Default: 200 |
| `bump_limit` | int | No | Default: 300 |
| `image_limit` | int | No | Default: 150 |
| `cooldown_seconds` | int | No | Default: 60 |
| `text_only` | bool | No | Text-only board |
| `require_subject` | bool | No | Require subject |
| `staff_only` | bool | No | Staff-only board |
| `user_ids` | bool | No | Enable poster IDs |
| `country_flags` | bool | No | Enable country flags |
| `rules` | string | No | Board rules (HTML) |

**Success Response (201):**

```json
{
  "board": {...}
}
```

**Error Responses:**

| Code | Response | Description |
|------|----------|-------------|
| `400` | `{ "error": "Board slug is required" }` | Missing slug |
| `400` | `{ "error": "Slug must be 1-32 lowercase alphanumeric characters" }` | Invalid slug format |
| `409` | `{ "error": "Board slug already exists" }` | Duplicate slug |

---

### Update Board

**Endpoint:** `POST /api/v1/admin/boards/{slug}`

**Authentication:** Required (admin)

Updates board settings.

**Request Body:** Same fields as Create Board (all optional).

---

### Delete Board

**Endpoint:** `DELETE /api/v1/admin/boards/{slug}`

**Authentication:** Required (admin)

Deletes a board and all its threads/posts (CASCADE).

**Response:**

```json
{
  "status": "deleted"
}
```

---

## 4chan-Compatible API

All endpoints are read-only and return data in exact 4chan API format.

### boards.json

**Endpoint:** `GET /api/4chan/boards.json`

Returns comprehensive board list.

**Response:** See [4chan API spec](https://github.com/4chan/4chan-API#boardsjson)

---

### threads.json

**Endpoint:** `GET /api/4chan/{board}/threads.json`

Returns thread list grouped by page.

**Response:**

```json
[
  {
    "page": 1,
    "threads": [
      {"no": 12345, "last_modified": 1709136000, "replies": 42}
    ]
  }
]
```

---

### catalog.json

**Endpoint:** `GET /api/4chan/{board}/catalog.json`

Returns full catalog with OP and last_replies.

---

### {page}.json

**Endpoint:** `GET /api/4chan/{board}/{page}.json`

Returns index page with threads and preview replies.

---

### thread/{no}.json

**Endpoint:** `GET /api/4chan/{board}/thread/{no}.json`

Returns full thread with all posts.

---

### archive.json

**Endpoint:** `GET /api/4chan/{board}/archive.json`

Returns array of archived thread OP numbers.

**Response:**

```json
[10001, 10002, 10003]
```

---

## Rate Limiting

Rate limiting is enforced by the API Gateway. Per-endpoint limits:

| Endpoint | Limit | Window |
|----------|-------|--------|
| POST /threads | 1 | 60s |
| POST /posts | 3 | 60s |
| GET /threads | 30 | 60s |
| GET /catalog | 30 | 60s |
| 4chan API | 60 | 60s |

---

## Error Handling

All errors return JSON with an `error` field:

```json
{
  "error": "Human-readable error message"
}
```

### Internal Server Errors

Unhandled exceptions are caught by the exception handler:

- Full stack trace logged to STDERR
- Client receives generic `{ "error": "An internal error occurred" }`
- No internal details exposed to clients

---

## Related Documentation

- [Architecture](ARCHITECTURE.md) - System architecture overview
- [Security Model](SECURITY.md) - Security considerations
- [Troubleshooting](TROUBLESHOOTING.md) - Common issues
