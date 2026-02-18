-- Fix media_objects table to match MediaObject model and MediaService expectations
DROP TABLE IF EXISTS media_objects;

CREATE TABLE media_objects (
    id SERIAL PRIMARY KEY,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    hash_sha256 TEXT NOT NULL UNIQUE,
    mime_type VARCHAR(64) NOT NULL,
    file_size INTEGER NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    storage_key TEXT NOT NULL,
    thumb_key TEXT,
    original_filename TEXT NOT NULL,
    phash TEXT,
    nsfw_flagged BOOLEAN DEFAULT false,
    banned BOOLEAN DEFAULT false
);

CREATE INDEX idx_media_objects_hash_sha256 ON media_objects(hash_sha256);
