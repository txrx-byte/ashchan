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

-- Moderation/Anti-spam schema
CREATE TABLE reports (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  reporter_id UUID,
  target_type VARCHAR(32) NOT NULL,
  target_id UUID NOT NULL,
  reason TEXT,
  metadata JSONB
);

CREATE TABLE moderation_decisions (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  moderator_id UUID NOT NULL,
  target_type VARCHAR(32) NOT NULL,
  target_id UUID NOT NULL,
  action VARCHAR(32) NOT NULL,
  reason TEXT,
  metadata JSONB
);

CREATE TABLE risk_scores (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  entity_type VARCHAR(32) NOT NULL,
  entity_id UUID NOT NULL,
  score NUMERIC(5,2) NOT NULL,
  factors JSONB
);

CREATE INDEX idx_reports_target ON reports(target_type, target_id);
CREATE INDEX idx_moderation_decisions_target ON moderation_decisions(target_type, target_id);
CREATE INDEX idx_risk_scores_entity ON risk_scores(entity_type, entity_id);
