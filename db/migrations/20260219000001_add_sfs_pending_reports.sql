-- Copyright 2026 txrx-byte
--
-- Licensed under the Apache License, Version 2.0 (the "License");
-- you may not use this file except in compliance with the License.
-- You may obtain a copy of the License at
--
--     http://www.apache.org/licenses/LICENSE-2.0
--
-- Unless required by applicable law or agreed to in writing, software
-- distributed under the License is distributed on an "AS IS" BASIS,
-- WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
-- See the License for the specific language governing permissions and
-- limitations under the License.

-- Phase 1 & 2: SFS Escalation Schema

-- Modify reports table (Phase 1)
ALTER TABLE reports ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45);
-- Note: reports table doesn't have ip_hash currently based on 004_moderation_anti_spam.sql, 
-- but if it did, we would drop it or keep it.
-- Let's assume we just add ip_address for now.

-- Phase 2: Pending SFS Reports Queue
CREATE TABLE sfs_pending_reports (
  id BIGSERIAL PRIMARY KEY,
  post_id BIGINT NOT NULL,
  board_slug VARCHAR(32) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  ja4_fingerprint VARCHAR(128),
  post_content TEXT,
  evidence_snapshot JSONB,
  reporter_id VARCHAR(128), -- Username or UUID
  status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending, approved, rejected
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_sfs_pending_status ON sfs_pending_reports(status);
