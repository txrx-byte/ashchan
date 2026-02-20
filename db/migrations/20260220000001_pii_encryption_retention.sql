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
-- PII Encryption at Rest & Automated Retention System
-- ============================================================
-- This migration:
-- 1. Widens IP columns to TEXT to accommodate encrypted (base64) values
-- 2. Creates the PII retention audit log table
-- 3. Creates the SFS submission audit log table
-- 4. Adds indexes to support retention cron jobs
--
-- Encryption is performed at the application layer (PiiEncryptionService)
-- using XChaCha20-Poly1305. Encrypted values are prefixed with 'enc:'
-- and base64 encoded, which can exceed VARCHAR(45) for IPv4.
-- ============================================================

-- ─────────────────────────────────────────────────────────────
-- 1. Widen posts.ip_address for encrypted values
-- ─────────────────────────────────────────────────────────────
-- Encrypted + base64 encoded IPs need ~120 chars.
-- Using TEXT to be safe; column will be NULLed after 30 days anyway.
ALTER TABLE posts ALTER COLUMN ip_address TYPE TEXT;
ALTER TABLE posts ALTER COLUMN email TYPE TEXT;

-- ─────────────────────────────────────────────────────────────
-- 2. Widen moderation tables for encrypted values
-- ─────────────────────────────────────────────────────────────
-- reports table: ip, post_ip, ip_address columns
DO $$ BEGIN
    -- Check if 'ip' column exists (from ported schema)
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='reports' AND column_name='ip') THEN
        ALTER TABLE reports ALTER COLUMN ip TYPE TEXT;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='reports' AND column_name='post_ip') THEN
        ALTER TABLE reports ALTER COLUMN post_ip TYPE TEXT;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='reports' AND column_name='ip_address') THEN
        ALTER TABLE reports ALTER COLUMN ip_address TYPE TEXT;
    END IF;
END $$;

-- banned_users table: host, xff, admin_ip
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='banned_users' AND column_name='host') THEN
        ALTER TABLE banned_users ALTER COLUMN host TYPE TEXT;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='banned_users' AND column_name='xff') THEN
        ALTER TABLE banned_users ALTER COLUMN xff TYPE TEXT;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='banned_users' AND column_name='admin_ip') THEN
        ALTER TABLE banned_users ALTER COLUMN admin_ip TYPE TEXT;
    END IF;
END $$;

-- sfs_pending_reports: ip_address
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='sfs_pending_reports' AND column_name='ip_address') THEN
        ALTER TABLE sfs_pending_reports ALTER COLUMN ip_address TYPE TEXT;
    END IF;
END $$;

-- report_clear_log: ip
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='report_clear_log' AND column_name='ip') THEN
        ALTER TABLE report_clear_log ALTER COLUMN ip TYPE TEXT;
    END IF;
END $$;

-- flood_log: ip
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='flood_log' AND column_name='ip') THEN
        ALTER TABLE flood_log ALTER COLUMN ip TYPE TEXT;
    END IF;
END $$;

-- admin_audit_log: ip_address
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_audit_log' AND column_name='ip_address') THEN
        ALTER TABLE admin_audit_log ALTER COLUMN ip_address TYPE TEXT;
    END IF;
END $$;

-- ─────────────────────────────────────────────────────────────
-- 3. PII Retention Audit Log
-- ─────────────────────────────────────────────────────────────
-- Immutable log of all automated PII deletion actions.
-- NEVER contains actual PII — only metadata about what was deleted.
CREATE TABLE IF NOT EXISTS pii_retention_log (
    id BIGSERIAL PRIMARY KEY,
    table_name VARCHAR(64) NOT NULL,
    column_name VARCHAR(128) NOT NULL,
    rows_affected INTEGER NOT NULL,
    retention_days INTEGER NOT NULL,
    executed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pii_retention_log_table ON pii_retention_log(table_name);
CREATE INDEX IF NOT EXISTS idx_pii_retention_log_time ON pii_retention_log(executed_at);

-- ─────────────────────────────────────────────────────────────
-- 4. SFS Submission Audit Log
-- ─────────────────────────────────────────────────────────────
-- Tracks who decrypted PII for SFS submission purposes.
-- NEVER contains the actual decrypted IP address.
CREATE TABLE IF NOT EXISTS sfs_audit_log (
    id BIGSERIAL PRIMARY KEY,
    report_id BIGINT NOT NULL,
    admin_user_id VARCHAR(128) NOT NULL,
    action VARCHAR(32) NOT NULL,  -- 'queued', 'approved_and_submitted', 'rejected', 'decrypt_failed', 'submission_failed'
    reason TEXT DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sfs_audit_report ON sfs_audit_log(report_id);
CREATE INDEX IF NOT EXISTS idx_sfs_audit_admin ON sfs_audit_log(admin_user_id);
CREATE INDEX IF NOT EXISTS idx_sfs_audit_time ON sfs_audit_log(created_at);

-- ─────────────────────────────────────────────────────────────
-- 5. Indexes to support retention cron performance
-- ─────────────────────────────────────────────────────────────
-- Posts: find old posts with non-null IPs efficiently
CREATE INDEX IF NOT EXISTS idx_posts_ip_retention 
    ON posts(created_at) 
    WHERE ip_address IS NOT NULL;

-- Posts: find old posts with non-null emails efficiently
CREATE INDEX IF NOT EXISTS idx_posts_email_retention 
    ON posts(created_at) 
    WHERE email IS NOT NULL AND email != '';

-- Reports: find old reports with non-null IPs
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='reports' AND column_name='ip') THEN
        CREATE INDEX IF NOT EXISTS idx_reports_ip_retention 
            ON reports(created_at) 
            WHERE ip IS NOT NULL;
    END IF;
END $$;

-- Banned users: find expired bans for IP cleanup
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='banned_users' AND column_name='host') THEN
        CREATE INDEX IF NOT EXISTS idx_banned_ip_retention 
            ON banned_users(length) 
            WHERE active = 0 AND host IS NOT NULL;
    END IF;
END $$;

-- ─────────────────────────────────────────────────────────────
-- 6. PII Decryption Access Log (for "View IP" in admin panel)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pii_access_log (
    id BIGSERIAL PRIMARY KEY,
    admin_user_id VARCHAR(128) NOT NULL,
    table_name VARCHAR(64) NOT NULL,
    record_id VARCHAR(128) NOT NULL,
    column_name VARCHAR(64) NOT NULL,
    purpose VARCHAR(128) NOT NULL,  -- 'moderation', 'sfs_submission', 'dsr_export', 'ban_check'
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pii_access_admin ON pii_access_log(admin_user_id);
CREATE INDEX IF NOT EXISTS idx_pii_access_time ON pii_access_log(created_at);

COMMENT ON TABLE pii_retention_log IS 'Immutable audit log of automated PII deletion actions. Never contains actual PII.';
COMMENT ON TABLE sfs_audit_log IS 'Tracks all SFS queue actions including decrypt approvals. Never logs decrypted IPs.';
COMMENT ON TABLE pii_access_log IS 'Records every instance of PII decryption by staff. Used for accountability.';
