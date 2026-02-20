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

-- Phase 1: De-anonymization (Boards Service)

ALTER TABLE posts ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45);
CREATE INDEX idx_posts_ip_address ON posts(ip_address);

-- Optional: Drop hash if no longer needed
ALTER TABLE posts DROP COLUMN IF EXISTS ip_hash;
