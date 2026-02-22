"""4chan API client – rate-limited, retrying HTTP fetcher."""

from __future__ import annotations

import time
import logging
from typing import Any

import httpx

from .config import FourChanConfig

logger = logging.getLogger("harvester.api")


class FourChanAPI:
    """Thin wrapper around the 4chan JSON API with rate limiting."""

    def __init__(self, cfg: FourChanConfig | None = None) -> None:
        self.cfg = cfg or FourChanConfig()
        self._last_request: float = 0.0
        self._client = httpx.Client(
            timeout=self.cfg.timeout,
            headers={"User-Agent": "ashchan-harvester/1.0 (+https://github.com/ashchane/ashchan)"},
            follow_redirects=True,
        )

    # ── rate limiting ────────────────────────────────────────────
    def _throttle(self) -> None:
        elapsed = time.monotonic() - self._last_request
        if elapsed < self.cfg.request_delay:
            time.sleep(self.cfg.request_delay - elapsed)
        self._last_request = time.monotonic()

    def _get_json(self, url: str) -> Any:
        for attempt in range(1, self.cfg.max_retries + 1):
            self._throttle()
            try:
                resp = self._client.get(url)
                if resp.status_code == 404:
                    logger.warning("404: %s", url)
                    return None
                resp.raise_for_status()
                return resp.json()
            except (httpx.HTTPStatusError, httpx.TransportError) as exc:
                logger.warning("Attempt %d/%d failed for %s: %s", attempt, self.cfg.max_retries, url, exc)
                if attempt == self.cfg.max_retries:
                    raise
                time.sleep(2 ** attempt)
        return None  # unreachable but keeps mypy happy

    def _get_bytes(self, url: str) -> bytes | None:
        for attempt in range(1, self.cfg.max_retries + 1):
            self._throttle()
            try:
                resp = self._client.get(url)
                if resp.status_code == 404:
                    logger.warning("404: %s", url)
                    return None
                resp.raise_for_status()
                return resp.content
            except (httpx.HTTPStatusError, httpx.TransportError) as exc:
                logger.warning("Attempt %d/%d failed for %s: %s", attempt, self.cfg.max_retries, url, exc)
                if attempt == self.cfg.max_retries:
                    raise
                time.sleep(2 ** attempt)
        return None

    # ── public API ───────────────────────────────────────────────

    def get_boards(self) -> list[dict]:
        """Fetch all boards from boards.json."""
        data = self._get_json(f"{self.cfg.api_base}/boards.json")
        return data.get("boards", []) if data else []

    def get_catalog(self, board: str) -> list[dict]:
        """Fetch the catalog for a board (pages with threads)."""
        data = self._get_json(f"{self.cfg.api_base}/{board}/catalog.json")
        return data if data else []

    def get_thread_list(self, board: str) -> list[dict]:
        """Fetch threads.json for a board (lightweight thread list)."""
        data = self._get_json(f"{self.cfg.api_base}/{board}/threads.json")
        return data if data else []

    def get_thread(self, board: str, thread_no: int) -> dict | None:
        """Fetch a full thread (OP + all replies)."""
        return self._get_json(f"{self.cfg.api_base}/{board}/thread/{thread_no}.json")

    def get_archive(self, board: str) -> list[int]:
        """Fetch the archive list for a board."""
        data = self._get_json(f"{self.cfg.api_base}/{board}/archive.json")
        return data if data else []

    def download_image(self, board: str, tim: int, ext: str) -> bytes | None:
        """Download a full-size image from i.4cdn.org."""
        url = f"{self.cfg.image_base}/{board}/{tim}{ext}"
        return self._get_bytes(url)

    def download_thumbnail(self, board: str, tim: int) -> bytes | None:
        """Download thumbnail from i.4cdn.org."""
        url = f"{self.cfg.thumb_base}/{board}/{tim}s.jpg"
        return self._get_bytes(url)

    def close(self) -> None:
        self._client.close()

    def __enter__(self) -> FourChanAPI:
        return self

    def __exit__(self, *args: object) -> None:
        self.close()
