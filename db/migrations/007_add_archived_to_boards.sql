-- Add archived column to boards table
ALTER TABLE boards ADD COLUMN IF NOT EXISTS archived BOOLEAN DEFAULT false;
CREATE INDEX IF NOT EXISTS idx_boards_archived ON boards(archived);
