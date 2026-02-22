"""Database operations – map 4chan API data into ashchan tables."""

from __future__ import annotations

import logging
from datetime import datetime, timezone
from typing import Any

import psycopg
from psycopg.rows import dict_row

from .config import DatabaseConfig

logger = logging.getLogger("harvester.db")


class Database:
    """Postgres interface for the harvester."""

    def __init__(self, cfg: DatabaseConfig | None = None) -> None:
        self.cfg = cfg or DatabaseConfig.from_env()
        self._conn: psycopg.Connection | None = None

    @property
    def conn(self) -> psycopg.Connection:
        if self._conn is None or self._conn.closed:
            self._conn = psycopg.connect(self.cfg.dsn, row_factory=dict_row, autocommit=False)
        return self._conn

    # ── board operations ─────────────────────────────────────────

    def get_board_id(self, slug: str) -> int | None:
        """Get the internal board ID for a slug, or None if not found."""
        row = self.conn.execute(
            "SELECT id FROM boards WHERE slug = %s", (slug,)
        ).fetchone()
        return row["id"] if row else None

    def ensure_board(self, slug: str, title: str = "", nsfw: bool = False) -> int:
        """Get or create a board, returning its integer ID."""
        bid = self.get_board_id(slug)
        if bid is not None:
            return bid
        row = self.conn.execute(
            """INSERT INTO boards (slug, name, title, nsfw)
               VALUES (%s, %s, %s, %s)
               ON CONFLICT (slug) DO UPDATE SET slug = EXCLUDED.slug
               RETURNING id""",
            (slug, title or slug, title or slug, nsfw),
        ).fetchone()
        self.conn.commit()
        return row["id"]

    def advance_post_counter(self, board_id: int, min_no: int) -> None:
        """Ensure the board's next_post_no is at least min_no + 1."""
        self.conn.execute(
            "UPDATE boards SET next_post_no = GREATEST(next_post_no, %s) WHERE id = %s",
            (min_no + 1, board_id),
        )

    # ── thread / post operations ─────────────────────────────────

    def thread_exists(self, thread_no: int) -> bool:
        row = self.conn.execute(
            "SELECT 1 FROM threads WHERE id = %s", (thread_no,)
        ).fetchone()
        return row is not None

    def post_exists(self, post_no: int) -> bool:
        row = self.conn.execute(
            "SELECT 1 FROM posts WHERE board_post_no = %s", (post_no,)
        ).fetchone()
        return row is not None

    def insert_thread(
        self,
        *,
        thread_no: int,
        board_id: int,
        created_at: datetime,
        sticky: bool = False,
        locked: bool = False,
        archived: bool = False,
        archived_at: datetime | None = None,
        reply_count: int = 0,
        image_count: int = 0,
    ) -> int:
        """Insert a thread row. Uses the 4chan post number as ID."""
        row = self.conn.execute(
            """INSERT INTO threads (id, board_id, created_at, updated_at, bumped_at,
                                    sticky, locked, archived, archived_at,
                                    reply_count, image_count)
               VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
               ON CONFLICT (id) DO UPDATE SET
                   reply_count = EXCLUDED.reply_count,
                   image_count = EXCLUDED.image_count,
                   sticky      = EXCLUDED.sticky,
                   locked      = EXCLUDED.locked,
                   archived    = EXCLUDED.archived,
                   archived_at = EXCLUDED.archived_at,
                   updated_at  = NOW()
               RETURNING id""",
            (
                thread_no, board_id, created_at, created_at, created_at,
                sticky, locked, archived, archived_at,
                reply_count, image_count,
            ),
        ).fetchone()
        return row["id"]

    def insert_post(
        self,
        *,
        thread_id: int,
        board_post_no: int,
        created_at: datetime,
        content: str,
        content_html: str | None = None,
        is_op: bool = False,
        author_name: str = "Anonymous",
        tripcode: str | None = None,
        capcode: str | None = None,
        subject: str | None = None,
        email: str | None = None,
        country_code: str | None = None,
        country_name: str | None = None,
        poster_id: str | None = None,
        media_url: str | None = None,
        thumb_url: str | None = None,
        media_filename: str | None = None,
        media_size: int | None = None,
        media_dimensions: str | None = None,
        media_hash: str | None = None,
        media_id: str | None = None,
        spoiler_image: bool = False,
    ) -> int:
        """Insert a post. Returns the inserted post's internal ID."""
        row = self.conn.execute(
            """INSERT INTO posts (
                   thread_id, board_post_no, created_at, updated_at,
                   content, content_html, is_op,
                   author_name, tripcode, capcode, subject, email,
                   country_code, country_name, poster_id,
                   media_url, thumb_url, media_filename,
                   media_size, media_dimensions, media_hash, media_id,
                   spoiler_image
               ) VALUES (
                   %s, %s, %s, %s,
                   %s, %s, %s,
                   %s, %s, %s, %s, %s,
                   %s, %s, %s,
                   %s, %s, %s,
                   %s, %s, %s, %s,
                   %s
               )
               ON CONFLICT (board_post_no, thread_id) DO UPDATE SET
                   content       = EXCLUDED.content,
                   content_html  = EXCLUDED.content_html,
                   media_url     = COALESCE(EXCLUDED.media_url, posts.media_url),
                   thumb_url     = COALESCE(EXCLUDED.thumb_url, posts.thumb_url),
                   media_id      = COALESCE(EXCLUDED.media_id, posts.media_id),
                   updated_at    = NOW()
               RETURNING id""",
            (
                thread_id, board_post_no, created_at, created_at,
                content, content_html, is_op,
                author_name, tripcode, capcode, subject, email,
                country_code, country_name, poster_id,
                media_url, thumb_url, media_filename,
                media_size, media_dimensions, media_hash, media_id,
                spoiler_image,
            ),
        ).fetchone()
        return row["id"]

    def set_op_post(self, thread_id: int, post_id: int) -> None:
        self.conn.execute(
            "UPDATE threads SET op_post_id = %s WHERE id = %s", (post_id, thread_id)
        )

    # ── media_objects dedup ──────────────────────────────────────

    def media_hash_exists(self, sha256: str) -> dict | None:
        """Return existing media_objects row if sha256 is already stored."""
        return self.conn.execute(
            "SELECT * FROM media_objects WHERE hash_sha256 = %s", (sha256,)
        ).fetchone()

    def insert_media_object(
        self,
        *,
        hash_sha256: str,
        mime_type: str | None = None,
        file_size: int | None = None,
        width: int | None = None,
        height: int | None = None,
        storage_key: str | None = None,
        thumb_key: str | None = None,
        original_filename: str | None = None,
    ) -> int:
        row = self.conn.execute(
            """INSERT INTO media_objects
                   (hash_sha256, mime_type, file_size, width, height,
                    storage_key, thumb_key, original_filename)
               VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
               ON CONFLICT (hash_sha256) DO UPDATE SET hash_sha256 = EXCLUDED.hash_sha256
               RETURNING id""",
            (hash_sha256, mime_type, file_size, width, height,
             storage_key, thumb_key, original_filename),
        ).fetchone()
        return row["id"]

    # ── transaction helpers ──────────────────────────────────────

    def commit(self) -> None:
        self.conn.commit()

    def rollback(self) -> None:
        self.conn.rollback()

    def close(self) -> None:
        if self._conn and not self._conn.closed:
            self._conn.close()

    def __enter__(self) -> Database:
        return self

    def __exit__(self, *args: object) -> None:
        self.close()
