"""Core harvesting logic – orchestrates API → Storage → DB."""

from __future__ import annotations

import logging
from datetime import datetime, timezone
from typing import Any

from rich.progress import Progress, SpinnerColumn, BarColumn, TextColumn, TimeElapsedColumn

from .api import FourChanAPI
from .config import HarvesterConfig
from .db import Database
from .storage import DiskStorageService, StorageService

logger = logging.getLogger("harvester.core")


def _ts_to_dt(ts: int) -> datetime:
    return datetime.fromtimestamp(ts, tz=timezone.utc)


class Harvester:
    """Orchestrates the full 4chan → ashchan import pipeline."""

    def __init__(self, cfg: HarvesterConfig | None = None) -> None:
        self.cfg = cfg or HarvesterConfig()
        self.api = FourChanAPI(self.cfg.fourchan)
        self.db = Database(self.cfg.db)
        if self.cfg.download_images:
            if self.cfg.storage_driver == "disk":
                self.storage: StorageService | DiskStorageService | None = DiskStorageService(
                    self.cfg.disk, thumb_max=self.cfg.thumbnail_max_size
                )
            else:
                self.storage = StorageService(
                    self.cfg.s3, thumb_max=self.cfg.thumbnail_max_size
                )
        else:
            self.storage = None
        # Stats
        self.stats = {"threads": 0, "posts": 0, "images": 0, "skipped": 0, "errors": 0}

    # ── image handling ───────────────────────────────────────────

    def _process_image(self, board_slug: str, post: dict) -> dict:
        """Download an image from 4chan and upload to S3.

        Returns a dict with media fields to merge into the DB post row.
        """
        result: dict[str, Any] = {}
        tim = post.get("tim")
        ext = post.get("ext")
        if not tim or not ext or not self.cfg.download_images or not self.storage:
            return result

        filename = post.get("filename", str(tim))
        fsize = post.get("fsize", 0)
        md5 = post.get("md5", "")
        w = post.get("w")
        h = post.get("h")

        # Download full image
        image_data = self.api.download_image(board_slug, tim, ext)
        if not image_data:
            logger.warning("Failed to download image %s%s from /%s/", tim, ext, board_slug)
            self.stats["errors"] += 1
            return result

        sha256 = StorageService.sha256(image_data)

        # Dedup: check if we already have this hash
        existing = self.db.media_hash_exists(sha256)
        if existing:
            logger.debug("Image %s already stored (hash=%s)", filename, sha256[:12])
            self.stats["skipped"] += 1
            url_prefix = (
                self.cfg.disk.url_prefix
                if self.cfg.storage_driver == "disk"
                else f"{self.cfg.s3.endpoint}/{self.cfg.s3.bucket}"
            )
            return {
                "media_url": f"{url_prefix}/{existing['storage_key']}",
                "thumb_url": f"{url_prefix}/{existing['thumb_key']}" if existing.get("thumb_key") else None,
                "media_filename": filename + ext,
                "media_size": fsize or existing.get("file_size"),
                "media_dimensions": f"{w}x{h}" if w and h else None,
                "media_hash": md5,
                "media_id": str(existing["id"]),
            }

        # Upload to storage (S3 or disk)
        upload_info = self.storage.upload(
            image_data, ext, generate_thumb=self.cfg.generate_thumbnails
        )

        # Store in media_objects
        media_id = self.db.insert_media_object(
            hash_sha256=upload_info["hash_sha256"],
            mime_type=upload_info["mime_type"],
            file_size=upload_info["file_size"],
            width=upload_info.get("width") or w,
            height=upload_info.get("height") or h,
            storage_key=upload_info["storage_key"],
            thumb_key=upload_info.get("thumb_key"),
            original_filename=filename + ext,
        )

        self.stats["images"] += 1
        return {
            "media_url": upload_info["media_url"],
            "thumb_url": upload_info.get("thumb_url"),
            "media_filename": filename + ext,
            "media_size": fsize or upload_info["file_size"],
            "media_dimensions": f"{w}x{h}" if w and h else None,
            "media_hash": md5,
            "media_id": str(media_id),
        }

    # ── post mapping ─────────────────────────────────────────────

    def _map_post(self, board_slug: str, post: dict, thread_no: int) -> dict:
        """Convert a 4chan post object into keyword args for Database.insert_post()."""
        is_op = post.get("resto", 0) == 0
        ts = post.get("time", 0)
        created_at = _ts_to_dt(ts) if ts else datetime.now(timezone.utc)

        # Process image if present
        media_fields = self._process_image(board_slug, post)

        return {
            "thread_id": thread_no,
            "board_post_no": post["no"],
            "created_at": created_at,
            "content": post.get("com", ""),
            "content_html": post.get("com"),
            "is_op": is_op,
            "author_name": post.get("name", "Anonymous"),
            "tripcode": post.get("trip"),
            "capcode": post.get("capcode"),
            "subject": post.get("sub"),
            "email": post.get("email"),
            "country_code": post.get("country"),
            "country_name": post.get("country_name"),
            "poster_id": post.get("id"),  # 4chan's poster ID field
            "spoiler_image": bool(post.get("spoiler", 0)),
            **media_fields,
        }

    # ── thread harvesting ────────────────────────────────────────

    def harvest_thread(self, board_slug: str, thread_no: int, *, board_id: int | None = None) -> bool:
        """Harvest a single thread from 4chan and import into the database.

        Returns True if the thread was successfully imported.
        """
        if board_id is None:
            board_id = self.db.ensure_board(board_slug)

        thread_data = self.api.get_thread(board_slug, thread_no)
        if not thread_data or "posts" not in thread_data:
            logger.warning("Thread /%s/%d not found or empty", board_slug, thread_no)
            return False

        posts = thread_data["posts"]
        if not posts:
            return False

        op = posts[0]
        created_at = _ts_to_dt(op.get("time", 0))

        # Insert thread
        self.db.insert_thread(
            thread_no=thread_no,
            board_id=board_id,
            created_at=created_at,
            sticky=bool(op.get("sticky", 0)),
            locked=bool(op.get("closed", 0)),
            archived=bool(op.get("archived", 0)),
            archived_at=_ts_to_dt(op["archived_on"]) if op.get("archived_on") else None,
            reply_count=op.get("replies", 0),
            image_count=op.get("images", 0),
        )

        # Insert all posts
        max_post_no = 0
        for post in posts:
            post_args = self._map_post(board_slug, post, thread_no)
            post_id = self.db.insert_post(**post_args)
            max_post_no = max(max_post_no, post["no"])

            # Link OP post to thread
            if post.get("resto", 0) == 0:
                self.db.set_op_post(thread_no, post_id)

            self.stats["posts"] += 1

        # Advance board counter
        self.db.advance_post_counter(board_id, max_post_no)
        self.db.commit()
        self.stats["threads"] += 1
        logger.info("Harvested thread /%s/%d (%d posts)", board_slug, thread_no, len(posts))
        return True

    # ── catalog / board harvesting ───────────────────────────────

    def harvest_catalog(self, board_slug: str) -> int:
        """Harvest the catalog for a board (OPs only, no full threads)."""
        board_id = self.db.ensure_board(board_slug)
        catalog = self.api.get_catalog(board_slug)
        count = 0
        for page in catalog:
            for thread in page.get("threads", []):
                thread_no = thread["no"]
                if self.db.thread_exists(thread_no):
                    logger.debug("Thread %d already exists, skipping catalog entry", thread_no)
                    self.stats["skipped"] += 1
                    continue
                created_at = _ts_to_dt(thread.get("time", 0))
                self.db.insert_thread(
                    thread_no=thread_no,
                    board_id=board_id,
                    created_at=created_at,
                    sticky=bool(thread.get("sticky", 0)),
                    locked=bool(thread.get("closed", 0)),
                    reply_count=thread.get("replies", 0),
                    image_count=thread.get("images", 0),
                )
                # Insert OP post
                post_args = self._map_post(board_slug, thread, thread_no)
                post_id = self.db.insert_post(**post_args)
                self.db.set_op_post(thread_no, post_id)
                self.stats["posts"] += 1
                self.stats["threads"] += 1
                count += 1
        self.db.commit()
        logger.info("Catalog harvest for /%s/: %d new threads", board_slug, count)
        return count

    def harvest_board(self, board_slug: str, *, include_archive: bool = False, limit: int = 0) -> int:
        """Harvest all threads from a board (full content + images).

        Fetches the catalog for thread numbers, then fetches each thread fully.
        If include_archive is True, also fetches archived threads.
        If limit > 0, stops after that many threads.
        """
        board_id = self.db.ensure_board(board_slug)
        thread_nos: list[int] = []

        # Gather active thread numbers from catalog
        catalog = self.api.get_catalog(board_slug)
        for page in catalog:
            for t in page.get("threads", []):
                thread_nos.append(t["no"])

        # Optionally include archived threads
        if include_archive:
            archive = self.api.get_archive(board_slug)
            thread_nos.extend(archive)

        # Deduplicate and sort
        thread_nos = sorted(set(thread_nos))

        if limit > 0:
            thread_nos = thread_nos[:limit]

        total = len(thread_nos)
        harvested = 0

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            BarColumn(),
            TextColumn("[progress.percentage]{task.percentage:>3.0f}%"),
            TimeElapsedColumn(),
        ) as progress:
            task = progress.add_task(f"/{board_slug}/ threads", total=total)
            for tno in thread_nos:
                if self.db.thread_exists(tno):
                    logger.debug("Thread %d already exists, updating", tno)
                try:
                    self.harvest_thread(board_slug, tno, board_id=board_id)
                    harvested += 1
                except Exception as exc:
                    logger.error("Error harvesting /%s/%d: %s", board_slug, tno, exc)
                    self.stats["errors"] += 1
                    self.db.rollback()
                progress.advance(task)

        logger.info(
            "Board /%s/ harvest complete: %d/%d threads", board_slug, harvested, total
        )
        return harvested

    # ── multi-board ──────────────────────────────────────────────

    def harvest_boards(self, slugs: list[str], **kwargs: Any) -> dict[str, int]:
        """Harvest multiple boards sequentially."""
        results = {}
        for slug in slugs:
            logger.info("Starting harvest of /%s/", slug)
            results[slug] = self.harvest_board(slug, **kwargs)
        return results

    # ── lifecycle ────────────────────────────────────────────────

    def close(self) -> None:
        self.api.close()
        self.db.close()

    def __enter__(self) -> Harvester:
        return self

    def __exit__(self, *args: object) -> None:
        self.close()
