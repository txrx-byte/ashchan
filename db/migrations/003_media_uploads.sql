-- Media/Uploads schema
CREATE TABLE media_objects (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  hash TEXT NOT NULL UNIQUE,
  content_type VARCHAR(64) NOT NULL,
  size_bytes BIGINT NOT NULL,
  storage_key TEXT NOT NULL,
  metadata JSONB
);

CREATE INDEX idx_media_objects_hash ON media_objects(hash);
