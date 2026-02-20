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

-- ============================================================
-- IP Encryption + Hash Dual-Column Pattern
-- ============================================================
-- Encrypted IPs (XChaCha20-Poly1305) use random nonces, producing
-- non-deterministic ciphertext. This means WHERE ip = ? cannot match.
--
-- Solution: store a deterministic SHA-256 hash alongside the encrypted
-- value for lookups, while the encrypted value allows admin decryption.
--
-- Pattern applied to:
--   reports:          ip (encrypted) + ip_hash (SHA-256)
--   report_clear_log: ip (encrypted) + ip_hash (SHA-256)
--   banned_users:     host (encrypted) + host_hash (SHA-256)
--   consents:         ip_encrypted (encrypted) + ip_hash (SHA-256)
-- ============================================================

-- ─────────────────────────────────────────────────────────────
-- 1. reports: add ip_hash for lookups
-- ─────────────────────────────────────────────────────────────
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='reports' AND column_name='ip_hash') THEN
        ALTER TABLE reports ADD COLUMN ip_hash VARCHAR(64);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_reports_ip_hash ON reports(ip_hash);

-- ─────────────────────────────────────────────────────────────
-- 2. report_clear_log: add ip_hash for lookups
-- ─────────────────────────────────────────────────────────────
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='report_clear_log' AND column_name='ip_hash') THEN
        ALTER TABLE report_clear_log ADD COLUMN ip_hash VARCHAR(64);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_report_clear_log_ip_hash ON report_clear_log(ip_hash);

-- ─────────────────────────────────────────────────────────────
-- 3. banned_users: add host_hash for lookups
-- ─────────────────────────────────────────────────────────────
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='banned_users' AND column_name='host_hash') THEN
        ALTER TABLE banned_users ADD COLUMN host_hash VARCHAR(64);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_banned_users_host_hash ON banned_users(host_hash);

-- ─────────────────────────────────────────────────────────────
-- 4. consents: add ip_encrypted for admin decryption
--    ip_hash stays as the SHA-256 lookup column
-- ─────────────────────────────────────────────────────────────
DO $$ BEGIN
    -- Widen ip_hash to TEXT to accommodate SHA-256 hex if needed,
    -- though VARCHAR(64) is sufficient for hex-encoded SHA-256.
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='consents' AND column_name='ip_hash') THEN
        ALTER TABLE consents ADD COLUMN ip_hash VARCHAR(64);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='consents' AND column_name='ip_encrypted') THEN
        ALTER TABLE consents ADD COLUMN ip_encrypted TEXT;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_consents_ip_hash ON consents(ip_hash);

-- ─────────────────────────────────────────────────────────────
-- 5. flood_log: add ip_hash for lookups
-- ─────────────────────────────────────────────────────────────
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='flood_log' AND column_name='ip_hash') THEN
        ALTER TABLE flood_log ADD COLUMN ip_hash VARCHAR(64);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_flood_log_ip_hash ON flood_log(ip_hash);
