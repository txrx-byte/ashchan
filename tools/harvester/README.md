# 4chan Harvester

A standalone Python utility for importing 4chan data (threads, posts, and images) into the Ashchan database and MinIO object storage.

## Requirements

- Python 3.10+
- PostgreSQL (with the Ashchan schema from `db/install.sql`)
- MinIO / S3-compatible storage (optional, for images)

## Installation

```bash
cd tools/
pip install -r harvester/requirements.txt
```

## Usage

Run with `python -m harvester` from the `tools/` directory:

```bash
cd tools/
python3 -m harvester --help
```

### Commands

| Command | Description |
|---------|-------------|
| `thread` | Harvest a single thread by board + thread number |
| `catalog` | Harvest a board's catalog (OP posts only, lightweight) |
| `board` | Harvest an entire board (all threads + full content + images) |
| `multi` | Harvest multiple boards sequentially |
| `list-boards` | List all available 4chan boards |
| `preview` | Preview a board's catalog without importing |

### Examples

```bash
# List all 4chan boards
python3 -m harvester list-boards

# Preview /g/ catalog
python3 -m harvester preview g --limit 10

# Harvest a single thread from /g/
python3 -m harvester thread g 108208945

# Harvest the /g/ catalog (OPs only)
python3 -m harvester catalog g

# Harvest entire /g/ board (all threads, all posts, all images)
python3 -m harvester board g

# Harvest /g/ but limit to 20 threads
python3 -m harvester board g --limit 20

# Harvest /g/ including archived threads
python3 -m harvester board g --archive

# Harvest without downloading images
python3 -m harvester board g --no-images

# Harvest without generating thumbnails
python3 -m harvester board g --no-thumbs

# Harvest multiple boards
python3 -m harvester multi g a v --limit 10

# Dry run (fetch data but don't write to DB)
python3 -m harvester thread g 108208945 --dry-run
```

### Global Options

```
--db-host TEXT        PostgreSQL host         (default: localhost, env: DB_HOST)
--db-port INTEGER     PostgreSQL port         (default: 5432, env: DB_PORT)
--db-name TEXT        Database name           (default: ashchan, env: DB_NAME)
--db-user TEXT        Database user           (default: ashchan, env: DB_USER)
--db-password TEXT    Database password        (default: ashchan, env: DB_PASSWORD)
--s3-endpoint TEXT    MinIO/S3 endpoint       (default: http://localhost:9000, env: S3_ENDPOINT)
--s3-access-key TEXT  S3 access key           (default: minioadmin, env: S3_ACCESS_KEY)
--s3-secret-key TEXT  S3 secret key           (default: minioadmin, env: S3_SECRET_KEY)
--s3-bucket TEXT      S3 bucket               (default: ashchan, env: S3_BUCKET)
-v, --verbose         Debug logging
```

## Architecture

```
harvester/
├── __init__.py      # Package docstring
├── __main__.py      # python -m harvester entrypoint
├── api.py           # 4chan API client (rate-limited, retrying)
├── cli.py           # Click CLI commands
├── config.py        # Configuration dataclasses
├── db.py            # PostgreSQL operations (psycopg3)
├── harvester.py     # Core orchestration logic
├── storage.py       # MinIO/S3 upload + thumbnail generation
└── requirements.txt # Python dependencies
```

### Pipeline

1. **Fetch** – Rate-limited HTTP requests to `a.4cdn.org` (1 req/sec, 3 retries)
2. **Download images** – Full images from `i.4cdn.org`, thumbnails auto-generated
3. **Deduplicate** – SHA-256 hash checked against `media_objects` table
4. **Upload** – Images stored in MinIO under `YYYY/MM/DD/<sha256>.<ext>`
5. **Insert** – Threads, posts, and media mapped into the Ashchan schema

### Database Mapping

| 4chan Field | Ashchan Table.Column |
|------------|---------------------|
| `no` | `posts.board_post_no`, `threads.id` |
| `resto` | `posts.thread_id` |
| `com` | `posts.content`, `posts.content_html` |
| `name` | `posts.author_name` |
| `trip` | `posts.tripcode` |
| `capcode` | `posts.capcode` |
| `sub` | `posts.subject` |
| `time` | `posts.created_at` |
| `tim`+`ext` | Downloaded → `media_objects.storage_key` |
| `filename` | `posts.media_filename` |
| `fsize` | `posts.media_size` |
| `md5` | `posts.media_hash` |
| `w`×`h` | `posts.media_dimensions`, `media_objects.width/height` |
| `sticky` | `threads.sticky` |
| `closed` | `threads.locked` |
| `archived` | `threads.archived` |
| `replies` | `threads.reply_count` |
| `images` | `threads.image_count` |
| `country` | `posts.country_code` |
| `country_name` | `posts.country_name` |
| `id` (poster) | `posts.poster_id` |

### Rate Limiting

The harvester respects 4chan's API guidelines:
- 1.1 second delay between API requests
- Automatic retry with exponential backoff (2s, 4s, 8s)
- Maximum 3 retries per request

### Image Deduplication

Images are deduplicated by SHA-256 hash via the `media_objects` table. If an identical image was already harvested, the existing storage reference is reused without re-uploading.
