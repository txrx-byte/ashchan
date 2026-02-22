"""Configuration and environment settings for the harvester."""

from __future__ import annotations

import os
from dataclasses import dataclass, field


@dataclass(frozen=True)
class DatabaseConfig:
    host: str = "localhost"
    port: int = 5432
    dbname: str = "ashchan"
    user: str = "ashchan"
    password: str = "ashchan"

    @property
    def dsn(self) -> str:
        return f"postgresql://{self.user}:{self.password}@{self.host}:{self.port}/{self.dbname}"

    @classmethod
    def from_env(cls) -> DatabaseConfig:
        return cls(
            host=os.getenv("DB_HOST", "localhost"),
            port=int(os.getenv("DB_PORT", "5432")),
            dbname=os.getenv("DB_NAME", "ashchan"),
            user=os.getenv("DB_USER", "ashchan"),
            password=os.getenv("DB_PASSWORD", "ashchan"),
        )


@dataclass(frozen=True)
class S3Config:
    endpoint: str = "http://localhost:9000"
    access_key: str = "minioadmin"
    secret_key: str = "minioadmin"
    bucket: str = "ashchan"
    use_ssl: bool = False

    @classmethod
    def from_env(cls) -> S3Config:
        return cls(
            endpoint=os.getenv("S3_ENDPOINT", "http://localhost:9000"),
            access_key=os.getenv("S3_ACCESS_KEY", "minioadmin"),
            secret_key=os.getenv("S3_SECRET_KEY", "minioadmin"),
            bucket=os.getenv("S3_BUCKET", "ashchan"),
            use_ssl=os.getenv("S3_USE_SSL", "false").lower() == "true",
        )


@dataclass(frozen=True)
class FourChanConfig:
    """4chan API configuration.  Respects the 1-request-per-second guideline."""
    api_base: str = "https://a.4cdn.org"
    image_base: str = "https://i.4cdn.org"
    thumb_base: str = "https://i.4cdn.org"
    request_delay: float = 1.1  # seconds between API requests
    max_retries: int = 3
    timeout: float = 30.0


@dataclass
class HarvesterConfig:
    db: DatabaseConfig = field(default_factory=DatabaseConfig.from_env)
    s3: S3Config = field(default_factory=S3Config.from_env)
    fourchan: FourChanConfig = field(default_factory=FourChanConfig)
    download_images: bool = True
    generate_thumbnails: bool = True
    thumbnail_max_size: int = 250
    dry_run: bool = False
