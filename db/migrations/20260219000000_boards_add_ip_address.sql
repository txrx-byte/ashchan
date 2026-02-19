-- Phase 1: De-anonymization (Boards Service)

ALTER TABLE posts ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45);
CREATE INDEX idx_posts_ip_address ON posts(ip_address);

-- Optional: Drop hash if no longer needed
ALTER TABLE posts DROP COLUMN IF EXISTS ip_hash;
