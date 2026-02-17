-- Boards/Threads/Posts schema
CREATE TABLE boards (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  slug VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(128) NOT NULL,
  description TEXT,
  settings JSONB
);

CREATE TABLE threads (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  board_id UUID NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  bumped_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  archived_at TIMESTAMPTZ,
  op_post_id UUID
);

CREATE TABLE posts (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  thread_id UUID NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
  author_id UUID,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  content TEXT NOT NULL,
  media_refs TEXT[],
  metadata JSONB
);

ALTER TABLE threads ADD CONSTRAINT fk_op_post FOREIGN KEY (op_post_id) REFERENCES posts(id) ON DELETE SET NULL;

CREATE INDEX idx_threads_board_id ON threads(board_id);
CREATE INDEX idx_threads_bumped_at ON threads(bumped_at DESC);
CREATE INDEX idx_posts_thread_id ON posts(thread_id);
CREATE INDEX idx_posts_created_at ON posts(created_at DESC);
