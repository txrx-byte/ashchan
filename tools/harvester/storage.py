"""S3/MinIO storage layer – upload images and thumbnails."""

from __future__ import annotations

import hashlib
import io
import logging
from datetime import datetime, timezone

import boto3
from botocore.config import Config as BotoConfig
from PIL import Image

from .config import S3Config

logger = logging.getLogger("harvester.storage")

# Map file extension → MIME type
MIME_MAP: dict[str, str] = {
    ".jpg": "image/jpeg",
    ".jpeg": "image/jpeg",
    ".png": "image/png",
    ".gif": "image/gif",
    ".webm": "video/webm",
    ".webp": "image/webp",
    ".svg": "image/svg+xml",
    ".pdf": "application/pdf",
}


class StorageService:
    """Upload images and thumbnails to MinIO / S3."""

    def __init__(self, cfg: S3Config | None = None, thumb_max: int = 250) -> None:
        self.cfg = cfg or S3Config.from_env()
        self.thumb_max = thumb_max
        self._s3 = boto3.client(
            "s3",
            endpoint_url=self.cfg.endpoint,
            aws_access_key_id=self.cfg.access_key,
            aws_secret_access_key=self.cfg.secret_key,
            config=BotoConfig(signature_version="s3"),
            use_ssl=self.cfg.use_ssl,
        )
        self._ensure_bucket()

    def _ensure_bucket(self) -> None:
        try:
            self._s3.head_bucket(Bucket=self.cfg.bucket)
        except Exception:
            try:
                self._s3.create_bucket(Bucket=self.cfg.bucket)
                logger.info("Created bucket: %s", self.cfg.bucket)
            except Exception as exc:
                logger.warning("Could not ensure bucket %s exists: %s", self.cfg.bucket, exc)

    # ── helpers ──────────────────────────────────────────────────

    @staticmethod
    def sha256(data: bytes) -> str:
        return hashlib.sha256(data).hexdigest()

    @staticmethod
    def _storage_key(sha: str, ext: str) -> str:
        now = datetime.now(timezone.utc)
        return f"{now:%Y/%m/%d}/{sha}{ext}"

    @staticmethod
    def _thumb_key(sha: str, ext: str) -> str:
        now = datetime.now(timezone.utc)
        return f"{now:%Y/%m/%d}/{sha}_thumb{ext}"

    def _guess_mime(self, ext: str) -> str:
        return MIME_MAP.get(ext.lower(), "application/octet-stream")

    # ── thumbnail generation ────────────────────────────────────

    def make_thumbnail(self, data: bytes, ext: str) -> tuple[bytes, int, int] | None:
        """Create a thumbnail if the image is larger than thumb_max.

        Returns (thumb_bytes, width, height) or None if already small enough
        or if thumbnail generation fails (e.g. for video files).
        """
        if ext.lower() in (".webm", ".pdf", ".svg"):
            return None
        try:
            img = Image.open(io.BytesIO(data))
            if img.width <= self.thumb_max and img.height <= self.thumb_max:
                return None
            img.thumbnail((self.thumb_max, self.thumb_max), Image.Resampling.LANCZOS)
            buf = io.BytesIO()
            fmt = "JPEG" if ext.lower() in (".jpg", ".jpeg") else "PNG"
            img.save(buf, format=fmt)
            return buf.getvalue(), img.width, img.height
        except Exception as exc:
            logger.warning("Thumbnail generation failed: %s", exc)
            return None

    # ── image dimensions ────────────────────────────────────────

    @staticmethod
    def get_dimensions(data: bytes) -> tuple[int, int] | None:
        try:
            img = Image.open(io.BytesIO(data))
            return img.width, img.height
        except Exception:
            return None

    # ── upload ───────────────────────────────────────────────────

    def upload(
        self,
        data: bytes,
        ext: str,
        *,
        generate_thumb: bool = True,
    ) -> dict:
        """Upload original image (and optional thumbnail) to S3.

        Returns a dict with keys matching `media_objects` columns:
            hash_sha256, mime_type, file_size, width, height,
            storage_key, thumb_key, media_url, thumb_url
        """
        sha = self.sha256(data)
        mime = self._guess_mime(ext)
        storage_key = self._storage_key(sha, ext)
        thumb_key: str | None = None
        thumb_url: str | None = None
        tn_w: int | None = None
        tn_h: int | None = None

        # Upload original
        self._s3.put_object(
            Bucket=self.cfg.bucket,
            Key=storage_key,
            Body=data,
            ContentType=mime,
        )
        media_url = f"{self.cfg.endpoint}/{self.cfg.bucket}/{storage_key}"

        dims = self.get_dimensions(data)
        w = dims[0] if dims else None
        h = dims[1] if dims else None

        # Thumbnail
        if generate_thumb:
            thumb_result = self.make_thumbnail(data, ext)
            if thumb_result:
                thumb_data, tn_w, tn_h = thumb_result
                thumb_ext = ".jpg" if ext.lower() in (".jpg", ".jpeg") else ".png"
                thumb_key = self._thumb_key(sha, thumb_ext)
                thumb_mime = self._guess_mime(thumb_ext)
                self._s3.put_object(
                    Bucket=self.cfg.bucket,
                    Key=thumb_key,
                    Body=thumb_data,
                    ContentType=thumb_mime,
                )
                thumb_url = f"{self.cfg.endpoint}/{self.cfg.bucket}/{thumb_key}"

        return {
            "hash_sha256": sha,
            "mime_type": mime,
            "file_size": len(data),
            "width": w,
            "height": h,
            "storage_key": storage_key,
            "thumb_key": thumb_key,
            "media_url": media_url,
            "thumb_url": thumb_url,
            "tn_w": tn_w,
            "tn_h": tn_h,
        }

    def exists(self, sha256_hash: str) -> bool:
        """Check if a file with this hash already exists in the bucket (any date prefix)."""
        # We rely on the database dedup instead of scanning S3
        return False

    def close(self) -> None:
        pass
