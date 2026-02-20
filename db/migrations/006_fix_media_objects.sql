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
