# MODEL_RELATIONSHIPS.md

# Model Relationships

**Service:** boards-threads-posts  
**Last Updated:** 2026-02-28

This document describes the database model relationships in the Boards/Threads/Posts service.

## Entity Relationship Diagram

```
┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
│     Board       │       │     Thread      │       │      Post       │
├─────────────────┤       ├─────────────────┤       ├─────────────────┤
│ id (PK)         │◄──────│ board_id (FK)   │       │ id (PK)         │
│ slug            │       │ id (PK/FK)      │◄──────│ thread_id (FK)  │
│ title           │       │ sticky          │       │ is_op           │
│ subtitle        │       │ locked          │       │ author_name     │
│ category        │       │ archived        │       │ content         │
│ nsfw            │       │ reply_count     │       │ media_url       │
│ max_threads     │       │ image_count     │       │ ...             │
│ bump_limit      │       │ bumped_at       │       └─────────────────┘
│ image_limit     │       │ created_at      │                 │
│ ...             │       │ updated_at      │                 │
└─────────────────┘       └─────────────────┘                 │
        │                       │                             │
        │ 1:N                   │ 1:N                         │
        ▼                       ▼                             │
┌─────────────────┐       ┌─────────────────┐                 │
│    Blotter      │       │   OpenPostBody  │◄────────────────┘
├─────────────────┤       ├─────────────────┤
│ id (PK)         │       │ post_id (PK/FK) │
│ content         │       │ body            │
│ is_important    │       │ updated_at      │
│ created_at      │       └─────────────────┘
└─────────────────┘
```

## Model Details

### Board

**Table:** `boards`

Represents an imageboard (e.g., /b/, /g/, /v/).

| Property | Type | Description |
|----------|------|-------------|
| `id` | int | Auto-incrementing primary key |
| `slug` | string | URL-friendly identifier |
| `title` | string | Display title |
| `subtitle` | string | Board tagline |
| `category` | string | Category for grouping |
| `nsfw` | bool | Not-safe-for-work flag |
| `max_threads` | int | Maximum active threads |
| `bump_limit` | int | Max replies before no bump |
| `image_limit` | int | Max images per thread |
| `cooldown_seconds` | int | Posting cooldown |
| `text_only` | bool | Text-only board flag |
| `require_subject` | bool | Subject required flag |
| `archived` | bool | Read-only archive flag |
| `staff_only` | bool | Staff-only access flag |
| `user_ids` | bool | Show poster IDs flag |
| `country_flags` | bool | Show country flags flag |

**Relationships:**

| Relation | Type | Model | Description |
|----------|------|-------|-------------|
| `threads()` | HasMany | Thread | All threads on this board |

---

### Thread

**Table:** `threads`

Represents a discussion thread within a board.

| Property | Type | Description |
|----------|------|-------------|
| `id` | int | Primary key (same as OP post ID) |
| `board_id` | int | Foreign key to boards |
| `sticky` | bool | Pinned to top flag |
| `locked` | bool | Closed to replies flag |
| `archived` | bool | Read-only archive flag |
| `reply_count` | int | Denormalized reply count |
| `image_count` | int | Denormalized image count |
| `bumped_at` | timestamp | Last bump timestamp |

**Relationships:**

| Relation | Type | Model | Description |
|----------|------|-------|-------------|
| `board()` | BelongsTo | Board | Parent board |
| `posts()` | HasMany | Post | All posts in thread |
| `op()` | HasOne | Post | OP (first) post |

---

### Post

**Table:** `posts`

Represents an individual post (OP or reply).

| Property | Type | Description |
|----------|------|-------------|
| `id` | int | Auto-incrementing primary key |
| `thread_id` | int | Foreign key to threads |
| `is_op` | bool | Is OP (first post) flag |
| `author_name` | string | Display name |
| `tripcode` | string | Generated tripcode |
| `capcode` | string | Staff capcode |
| `email` | string | Email field (often "sage") |
| `subject` | string | Post subject |
| `content` | string | Raw content (markup) |
| `content_html` | string | Parsed HTML |
| `ip_address` | string | Encrypted IP address |
| `country_code` | string | ISO country code |
| `poster_id` | string | Deterministic IP hash |
| `media_url` | string | Full-size media URL |
| `thumb_url` | string | Thumbnail URL |
| `media_filename` | string | Original filename |
| `media_size` | int | File size in bytes |
| `media_dimensions` | string | "WxH" dimensions |
| `media_hash` | string | Base64 MD5 hash |
| `spoiler_image` | bool | Image spoilered flag |
| `delete_password_hash` | string | Bcrypt deletion password |
| `deleted` | bool | Soft delete flag |
| `is_editing` | bool | Liveposting active flag |
| `edit_password_hash` | string | Reclaim password hash |
| `edit_expires_at` | timestamp | Liveposting expiry |

**Relationships:**

| Relation | Type | Model | Description |
|----------|------|-------|-------------|
| `thread()` | BelongsTo | Thread | Parent thread |

**Accessors:**

| Accessor | Type | Description |
|----------|------|-------------|
| `media_size_human` | string | Formatted file size |
| `content_preview` | string | 160-char preview |

---

### OpenPostBody

**Table:** `open_post_bodies`

Stores rapidly-changing post body during liveposting.

| Property | Type | Description |
|----------|------|-------------|
| `post_id` | int | PK/FK to posts |
| `body` | string | Raw body text |
| `updated_at` | timestamp | Last update time |

**Relationships:**

| Relation | Type | Model | Description |
|----------|------|-------|-------------|
| `post()` | BelongsTo | Post | Parent post |

**Lifecycle:**
1. Created when user starts typing (liveposting)
2. Updated on each debounced sync (~1 second)
3. Copied to `posts.content` when post is closed
4. Deleted after post is finalized

---

### Blotter

**Table:** `blotter`

Site announcements displayed on front page.

| Property | Type | Description |
|----------|------|-------------|
| `id` | int | Auto-incrementing primary key |
| `content` | string | Announcement text |
| `is_important` | bool | Important flag |
| `created_at` | timestamp | Posted timestamp |

**Relationships:** None (standalone table)

---

## Foreign Key Constraints

| Child Table | Child Column | Parent Table | Parent Column | On Delete |
|-------------|--------------|--------------|---------------|-----------|
| `threads` | `board_id` | `boards` | `id` | CASCADE |
| `posts` | `thread_id` | `threads` | `id` | CASCADE |
| `open_post_bodies` | `post_id` | `posts` | `id` | CASCADE |

## Indexes

### boards

| Index | Columns | Type |
|-------|---------|------|
| `PRIMARY` | `id` | BTREE |
| `idx_boards_slug` | `slug` | BTREE (UNIQUE) |
| `idx_boards_category` | `category`, `slug` | BTREE |
| `idx_boards_archived` | `archived`, `staff_only` | BTREE |

### threads

| Index | Columns | Type |
|-------|---------|------|
| `PRIMARY` | `id` | BTREE |
| `idx_threads_board` | `board_id` | BTREE |
| `idx_threads_bumped` | `board_id`, `sticky`, `bumped_at` | BTREE |
| `idx_threads_archived` | `board_id`, `archived` | BTREE |

### posts

| Index | Columns | Type |
|-------|---------|------|
| `PRIMARY` | `id` | BTREE |
| `idx_posts_thread` | `thread_id` | BTREE |
| `idx_posts_thread_deleted` | `thread_id`, `deleted` | BTREE |
| `idx_posts_created` | `created_at` | BTREE |
| `idx_posts_ip` | `ip_address` | BTREE |

### open_post_bodies

| Index | Columns | Type |
|-------|---------|------|
| `PRIMARY` | `post_id` | BTREE |
| `idx_open_post_expires` | `edit_expires_at` | BTREE |

### blotter

| Index | Columns | Type |
|-------|---------|------|
| `PRIMARY` | `id` | BTREE |

## Data Flow

### Creating a Thread

```
1. BoardService::createThread()
   ├── Allocate thread ID from posts_id_seq
   ├── Create Thread record
   ├── Create OP Post record
   ├── Update thread counters
   └── Invalidate caches

2. Database cascade:
   Thread (board_id → Board)
   Post (thread_id → Thread)
```

### Creating a Reply

```
1. BoardService::createPost()
   ├── Create Post record (is_op = false)
   ├── Increment thread.reply_count
   ├── Increment thread.image_count (if media)
   ├── Update thread.bumped_at (unless sage)
   └── Invalidate caches

2. Database relationships:
   Post (thread_id → Thread)
```

### Liveposting Flow

```
1. Open post allocated:
   Post (is_editing = true)
   OpenPostBody (post_id → Post)

2. Body updates:
   OpenPostBody.body updated (debounced)

3. Post closed:
   OpenPostBody.body → Post.content
   OpenPostBody deleted
   Post.is_editing = false
```

## Caching Strategy

| Cache Key | TTL | Invalidation Trigger |
|-----------|-----|---------------------|
| `boards:all` | 300s | Board create/update/delete |
| `board:{slug}` | 300s | Board update/delete |
| `blotter:recent` | 120s | Blotter insert |
| `thread:{id}` | 120s | Post create/delete |
| `catalog:{slug}` | 60s | Thread bump/create/delete |
