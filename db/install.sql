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
-- Ashchan – Complete Database Schema (Fresh Install)
-- ============================================================
-- This file creates ALL tables, indexes, views, and triggers
-- needed for a clean Ashchan installation. Run once on a fresh
-- PostgreSQL database.
--
-- Usage:
--   psql -U ashchan -d ashchan -f install.sql
--
-- After running this file, run seed.sql to populate initial data.
-- ============================================================

BEGIN;

-- ═══════════════════════════════════════════════════════════════
-- 1. AUTH – User accounts (anonymous + registered)
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    is_anonymous BOOLEAN DEFAULT true,
    username VARCHAR(64) UNIQUE,
    password_hash VARCHAR(255),
    roles TEXT[],
    ban_status JSONB
);

CREATE TABLE IF NOT EXISTS sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS consents (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    policy_version VARCHAR(20) NOT NULL,
    consented BOOLEAN NOT NULL,
    metadata JSONB,
    ip_hash VARCHAR(64),
    ip_encrypted TEXT
);

CREATE TABLE IF NOT EXISTS deletion_requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    completed_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_consents_ip_hash ON consents(ip_hash);

-- ═══════════════════════════════════════════════════════════════
-- 2. STAFF AUTH – Staff users, sessions, security
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS staff_users (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    access_level VARCHAR(20) NOT NULL DEFAULT 'janitor',
    access_flags TEXT[],
    board_access TEXT[],
    is_active BOOLEAN DEFAULT true,
    is_locked BOOLEAN DEFAULT false,
    locked_until TIMESTAMP,
    failed_login_attempts INTEGER DEFAULT 0,
    last_failed_login TIMESTAMP,
    current_session_token VARCHAR(255),
    session_expires_at TIMESTAMP,
    last_login_at TIMESTAMP,
    last_login_ip VARCHAR(45),
    last_user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT REFERENCES staff_users(id),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by BIGINT REFERENCES staff_users(id),
    -- Account management extensions
    capcode VARCHAR(100) DEFAULT NULL,
    capcode_label VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    boards_allowed TEXT[] DEFAULT '{}',
    CONSTRAINT valid_access_level CHECK (access_level IN ('janitor', 'mod', 'manager', 'admin')),
    CONSTRAINT failed_login_check CHECK (failed_login_attempts >= 0)
);

CREATE INDEX IF NOT EXISTS idx_staff_username ON staff_users(username);
CREATE INDEX IF NOT EXISTS idx_staff_email ON staff_users(email);
CREATE INDEX IF NOT EXISTS idx_staff_level ON staff_users(access_level);
CREATE INDEX IF NOT EXISTS idx_staff_active ON staff_users(is_active);

-- Access level hierarchy
CREATE TABLE IF NOT EXISTS access_levels (
    id SERIAL PRIMARY KEY,
    level_name VARCHAR(20) NOT NULL UNIQUE,
    level_order INTEGER NOT NULL,
    description TEXT,
    inherits_from VARCHAR(20)
);

-- Permissions
CREATE TABLE IF NOT EXISTS permissions (
    id SERIAL PRIMARY KEY,
    permission_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    category VARCHAR(50)
);

-- Level-permission mapping
CREATE TABLE IF NOT EXISTS level_permissions (
    level_name VARCHAR(20) REFERENCES access_levels(level_name),
    permission_id INTEGER REFERENCES permissions(id),
    PRIMARY KEY (level_name, permission_id)
);

-- Staff sessions
CREATE TABLE IF NOT EXISTS staff_sessions (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES staff_users(id) ON DELETE CASCADE,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_valid BOOLEAN DEFAULT true,
    invalidated_at TIMESTAMP,
    invalidate_reason VARCHAR(100)
);

CREATE INDEX IF NOT EXISTS idx_session_token ON staff_sessions(token_hash);
CREATE INDEX IF NOT EXISTS idx_session_user ON staff_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_session_expires ON staff_sessions(expires_at);

-- Admin audit log
CREATE TABLE IF NOT EXISTS admin_audit_log (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT REFERENCES staff_users(id),
    username VARCHAR(50),
    action_type VARCHAR(50) NOT NULL,
    action_category VARCHAR(50),
    resource_type VARCHAR(50),
    resource_id BIGINT,
    description TEXT,
    old_values JSONB,
    new_values JSONB,
    ip_address TEXT NOT NULL,
    user_agent TEXT,
    request_uri VARCHAR(500),
    board VARCHAR(20),
    thread_id BIGINT,
    req_sig VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_audit_user ON admin_audit_log(user_id);
CREATE INDEX IF NOT EXISTS idx_audit_action ON admin_audit_log(action_type);
CREATE INDEX IF NOT EXISTS idx_audit_time ON admin_audit_log(created_at);
CREATE INDEX IF NOT EXISTS idx_audit_board ON admin_audit_log(board);

-- Login attempts tracking
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGSERIAL PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username_attempted VARCHAR(50),
    success BOOLEAN DEFAULT false,
    failure_reason VARCHAR(100),
    ip_address_hash VARCHAR(64) NOT NULL,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_login_ip ON login_attempts(ip_address_hash);
CREATE INDEX IF NOT EXISTS idx_login_time ON login_attempts(created_at);

-- CSRF tokens
CREATE TABLE IF NOT EXISTS csrf_tokens (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT REFERENCES staff_users(id) ON DELETE CASCADE,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN DEFAULT false,
    used_at TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_csrf_token ON csrf_tokens(token_hash);
CREATE INDEX IF NOT EXISTS idx_csrf_expires ON csrf_tokens(expires_at);

-- Password reset tokens
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES staff_users(id) ON DELETE CASCADE,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN DEFAULT false,
    requested_ip VARCHAR(45)
);

-- Security settings per staff user
CREATE TABLE IF NOT EXISTS staff_security_settings (
    user_id BIGINT PRIMARY KEY REFERENCES staff_users(id) ON DELETE CASCADE,
    max_concurrent_sessions INTEGER DEFAULT 3,
    session_timeout_minutes INTEGER DEFAULT 480,
    require_2fa BOOLEAN DEFAULT false,
    require_ip_whitelist BOOLEAN DEFAULT false,
    allowed_ips TEXT[],
    notify_on_login BOOLEAN DEFAULT true,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Auto-update timestamp trigger
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

CREATE TRIGGER update_staff_users_updated_at BEFORE UPDATE ON staff_users
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ═══════════════════════════════════════════════════════════════
-- 3. BOARDS, THREADS, POSTS
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS boards (
    id SERIAL PRIMARY KEY,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    slug VARCHAR(32) NOT NULL UNIQUE,
    name VARCHAR(128) NOT NULL,
    description TEXT,
    settings JSONB,
    title VARCHAR(255),
    subtitle VARCHAR(255),
    category VARCHAR(64),
    nsfw BOOLEAN DEFAULT false,
    max_threads INTEGER DEFAULT 200,
    bump_limit INTEGER DEFAULT 300,
    image_limit INTEGER DEFAULT 150,
    cooldown_seconds INTEGER DEFAULT 60,
    text_only BOOLEAN DEFAULT false,
    require_subject BOOLEAN DEFAULT false,
    rules TEXT,
    archived BOOLEAN DEFAULT false,
    staff_only BOOLEAN DEFAULT false,
    user_ids BOOLEAN DEFAULT false,
    country_flags BOOLEAN DEFAULT false,
    next_post_no BIGINT DEFAULT 1
);

CREATE INDEX IF NOT EXISTS idx_boards_archived ON boards(archived);
CREATE INDEX IF NOT EXISTS idx_boards_staff_only ON boards(staff_only);

CREATE TABLE IF NOT EXISTS threads (
    id BIGINT PRIMARY KEY,
    board_id INTEGER NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    bumped_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    archived_at TIMESTAMPTZ,
    op_post_id BIGINT,
    sticky BOOLEAN DEFAULT false,
    locked BOOLEAN DEFAULT false,
    archived BOOLEAN DEFAULT false,
    reply_count INTEGER DEFAULT 0,
    image_count INTEGER DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_threads_board_id ON threads(board_id);
CREATE INDEX IF NOT EXISTS idx_threads_bumped_at ON threads(bumped_at DESC);

CREATE TABLE IF NOT EXISTS posts (
    id BIGSERIAL PRIMARY KEY,
    thread_id BIGINT NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    author_id BIGINT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    content TEXT NOT NULL,
    media_refs TEXT[],
    metadata JSONB,
    is_op BOOLEAN DEFAULT false,
    author_name VARCHAR(128),
    tripcode VARCHAR(128),
    capcode VARCHAR(128),
    email TEXT,
    subject VARCHAR(255),
    content_html TEXT,
    ip_address TEXT,
    country_code VARCHAR(4),
    country_name VARCHAR(64),
    poster_id VARCHAR(8),
    board_post_no BIGINT,
    media_id VARCHAR(64),
    media_url TEXT,
    thumb_url TEXT,
    media_filename VARCHAR(255),
    media_size INTEGER,
    media_dimensions VARCHAR(32),
    media_hash VARCHAR(64),
    spoiler_image BOOLEAN DEFAULT false,
    delete_password_hash VARCHAR(255),
    deleted BOOLEAN DEFAULT false,
    deleted_at TIMESTAMPTZ
);

ALTER TABLE threads ADD CONSTRAINT fk_op_post FOREIGN KEY (op_post_id) REFERENCES posts(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_posts_thread_id ON posts(thread_id);
CREATE INDEX IF NOT EXISTS idx_posts_created_at ON posts(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_posts_ip_retention ON posts(created_at) WHERE ip_address IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_posts_email_retention ON posts(created_at) WHERE email IS NOT NULL AND email != '';
CREATE INDEX IF NOT EXISTS idx_posts_board_post_no ON posts(board_post_no);
CREATE UNIQUE INDEX IF NOT EXISTS idx_posts_board_post_no_unique ON posts(board_post_no, thread_id);

-- ═══════════════════════════════════════════════════════════════
-- 4. MODERATION SYSTEM
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS reports (
    id BIGSERIAL PRIMARY KEY,
    ip TEXT NOT NULL,
    ip_hash VARCHAR(64),
    pwd VARCHAR(255),
    pass_id VARCHAR(255),
    req_sig VARCHAR(255),
    board VARCHAR(10) NOT NULL,
    no BIGINT NOT NULL,
    resto BIGINT DEFAULT 0,
    cat SMALLINT DEFAULT 1,
    weight DECIMAL(10,2) DEFAULT 1.00,
    report_category INTEGER NOT NULL,
    post_ip TEXT NOT NULL,
    post_json TEXT NOT NULL,
    cleared SMALLINT DEFAULT 0,
    cleared_by VARCHAR(255) DEFAULT '',
    ws SMALLINT DEFAULT 0,
    ip_address TEXT,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_board_cleared ON reports(board, cleared);
CREATE INDEX IF NOT EXISTS idx_post_board ON reports(no, board);
CREATE INDEX IF NOT EXISTS idx_cleared_ts ON reports(cleared, ts);
CREATE INDEX IF NOT EXISTS idx_ip_cleared ON reports(ip, cleared);
CREATE INDEX IF NOT EXISTS idx_category ON reports(report_category);
CREATE INDEX IF NOT EXISTS idx_reports_ip_hash ON reports(ip_hash);

-- Report categories
CREATE TABLE IF NOT EXISTS report_categories (
    id BIGSERIAL PRIMARY KEY,
    board VARCHAR(20) DEFAULT '',
    title VARCHAR(255) NOT NULL,
    weight DECIMAL(10,2) DEFAULT 1.00,
    exclude_boards VARCHAR(255) DEFAULT '',
    filtered INTEGER DEFAULT 0,
    op_only SMALLINT DEFAULT 0,
    reply_only SMALLINT DEFAULT 0,
    image_only SMALLINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_category_board ON report_categories(board);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_board_title ON report_categories(board, title);

-- Ban templates
CREATE TABLE IF NOT EXISTS ban_templates (
    id BIGSERIAL PRIMARY KEY,
    rule VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    ban_type VARCHAR(20) DEFAULT 'local',
    ban_days INTEGER DEFAULT 0,
    banlen VARCHAR(50) DEFAULT '',
    can_warn SMALLINT DEFAULT 1,
    publicban SMALLINT DEFAULT 0,
    is_public SMALLINT DEFAULT 0,
    public_reason TEXT,
    private_reason TEXT,
    action VARCHAR(50) DEFAULT '',
    save_type VARCHAR(20) DEFAULT '',
    blacklist_image SMALLINT DEFAULT 0,
    reject_image SMALLINT DEFAULT 0,
    access VARCHAR(20) DEFAULT 'janitor',
    boards VARCHAR(255) DEFAULT '',
    exclude VARCHAR(50) DEFAULT '',
    appealable SMALLINT DEFAULT 1,
    active SMALLINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_template_rule ON ban_templates(rule);
CREATE INDEX IF NOT EXISTS idx_template_active ON ban_templates(active);

-- Banned users
CREATE TABLE IF NOT EXISTS banned_users (
    id BIGSERIAL PRIMARY KEY,
    board VARCHAR(10) DEFAULT '',
    global SMALLINT DEFAULT 0,
    zonly SMALLINT DEFAULT 0,
    name VARCHAR(255) DEFAULT 'Anonymous',
    host TEXT NOT NULL,
    host_hash VARCHAR(64),
    reverse VARCHAR(255) DEFAULT '',
    xff TEXT DEFAULT '',
    reason TEXT,
    length TIMESTAMP,
    now TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    admin VARCHAR(255) NOT NULL,
    md5 VARCHAR(32) DEFAULT '',
    post_num BIGINT DEFAULT 0,
    rule VARCHAR(50) DEFAULT '',
    post_time VARCHAR(20) DEFAULT '',
    template_id BIGINT,
    password VARCHAR(255) DEFAULT '',
    pass_id VARCHAR(255) DEFAULT '',
    post_json TEXT,
    admin_ip TEXT DEFAULT '',
    active SMALLINT DEFAULT 1,
    appealable SMALLINT DEFAULT 1,
    unbannedon TIMESTAMP,
    ban_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_ban_host_active ON banned_users(host, active);
CREATE INDEX IF NOT EXISTS idx_ban_active_length ON banned_users(active, length);
CREATE INDEX IF NOT EXISTS idx_banned_users_host_hash ON banned_users(host_hash);

-- Ban requests
CREATE TABLE IF NOT EXISTS ban_requests (
    id BIGSERIAL PRIMARY KEY,
    board VARCHAR(10) NOT NULL,
    post_no BIGINT NOT NULL,
    janitor VARCHAR(255) NOT NULL,
    ban_template BIGINT NOT NULL,
    post_json TEXT NOT NULL,
    reason TEXT,
    length VARCHAR(20) DEFAULT '',
    image_hash VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_request_board ON ban_requests(board);
CREATE INDEX IF NOT EXISTS idx_request_janitor ON ban_requests(janitor);

-- Report clear log
CREATE TABLE IF NOT EXISTS report_clear_log (
    id BIGSERIAL PRIMARY KEY,
    ip TEXT NOT NULL,
    ip_hash VARCHAR(64),
    pwd VARCHAR(255),
    pass_id VARCHAR(255),
    category INTEGER NOT NULL,
    weight DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_clear_ip_created ON report_clear_log(ip, created_at);
CREATE INDEX IF NOT EXISTS idx_report_clear_log_ip_hash ON report_clear_log(ip_hash);

-- Janitor stats
CREATE TABLE IF NOT EXISTS janitor_stats (
    id SERIAL PRIMARY KEY,
    janitor_username VARCHAR(64) NOT NULL,
    action SMALLINT NOT NULL DEFAULT 0,
    board VARCHAR(16),
    post_id BIGINT,
    requested_template INT,
    accepted_template INT,
    mod_username VARCHAR(64),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- SFS pending reports
CREATE TABLE IF NOT EXISTS sfs_pending_reports (
    id BIGSERIAL PRIMARY KEY,
    post_id BIGINT,
    board_slug VARCHAR(32),
    ip_address TEXT,
    ja4_fingerprint VARCHAR(128),
    post_content TEXT,
    evidence_snapshot JSONB,
    reporter_id VARCHAR(128),
    status VARCHAR(20) DEFAULT 'pending',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- ═══════════════════════════════════════════════════════════════
-- 5. MEDIA
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS media_objects (
    id SERIAL PRIMARY KEY,
    hash_sha256 TEXT NOT NULL UNIQUE,
    mime_type VARCHAR(64),
    file_size INT,
    width INT,
    height INT,
    storage_key TEXT,
    thumb_key TEXT,
    original_filename TEXT,
    phash TEXT,
    nsfw_flagged BOOLEAN DEFAULT false,
    banned BOOLEAN DEFAULT false
);

-- ═══════════════════════════════════════════════════════════════
-- 6. ACCOUNT MANAGEMENT & CAPCODES
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS capcodes (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    tripcode VARCHAR(100) NOT NULL UNIQUE,
    label VARCHAR(100),
    color VARCHAR(20) DEFAULT '#0000FF',
    boards TEXT[] DEFAULT '{}',
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT REFERENCES staff_users(id),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_capcode_tripcode ON capcodes(tripcode);
CREATE INDEX IF NOT EXISTS idx_capcode_active ON capcodes(is_active);

-- IP Range Bans
CREATE TABLE IF NOT EXISTS ip_range_bans (
    id BIGSERIAL PRIMARY KEY,
    range_start INET NOT NULL,
    range_end INET NOT NULL,
    reason TEXT NOT NULL,
    boards TEXT[] DEFAULT '{}',
    is_active BOOLEAN DEFAULT true,
    expires_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT REFERENCES staff_users(id),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_ip_range_start ON ip_range_bans(range_start);
CREATE INDEX IF NOT EXISTS idx_ip_range_end ON ip_range_bans(range_end);
CREATE INDEX IF NOT EXISTS idx_ip_range_active ON ip_range_bans(is_active);

-- Autopurge rules
CREATE TABLE IF NOT EXISTS autopurge_rules (
    id BIGSERIAL PRIMARY KEY,
    pattern TEXT NOT NULL,
    is_regex BOOLEAN DEFAULT false,
    boards TEXT[] DEFAULT '{}',
    purge_threads BOOLEAN DEFAULT true,
    purge_replies BOOLEAN DEFAULT true,
    ban_length_days INTEGER DEFAULT 0,
    ban_reason TEXT,
    is_active BOOLEAN DEFAULT true,
    hit_count INTEGER DEFAULT 0,
    last_hit_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT REFERENCES staff_users(id),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_autopurge_pattern ON autopurge_rules(pattern);
CREATE INDEX IF NOT EXISTS idx_autopurge_active ON autopurge_rules(is_active);

-- DMCA notices
CREATE TABLE IF NOT EXISTS dmca_notices (
    id BIGSERIAL PRIMARY KEY,
    claimant_name VARCHAR(255) NOT NULL,
    claimant_company VARCHAR(255),
    claimant_email VARCHAR(255) NOT NULL,
    claimant_phone VARCHAR(50),
    copyrighted_work TEXT NOT NULL,
    infringing_urls TEXT[] NOT NULL,
    statement TEXT NOT NULL,
    signature TEXT,
    status VARCHAR(20) DEFAULT 'pending',
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP,
    processed_by BIGINT REFERENCES staff_users(id),
    notes TEXT
);

CREATE INDEX IF NOT EXISTS idx_dmca_status ON dmca_notices(status);
CREATE INDEX IF NOT EXISTS idx_dmca_received ON dmca_notices(received_at);

-- DMCA takedowns log
CREATE TABLE IF NOT EXISTS dmca_takedowns (
    id BIGSERIAL PRIMARY KEY,
    notice_id BIGINT REFERENCES dmca_notices(id),
    board VARCHAR(20) NOT NULL,
    post_no BIGINT NOT NULL,
    md5_hash VARCHAR(32),
    takedown_reason TEXT,
    takedown_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    takedown_by BIGINT REFERENCES staff_users(id)
);

CREATE INDEX IF NOT EXISTS idx_dmca_takedown_notice ON dmca_takedowns(notice_id);
CREATE INDEX IF NOT EXISTS idx_dmca_takedown_board ON dmca_takedowns(board);

-- Blotter messages
CREATE TABLE IF NOT EXISTS blotter_messages (
    id BIGSERIAL PRIMARY KEY,
    message TEXT NOT NULL,
    is_html BOOLEAN DEFAULT false,
    is_active BOOLEAN DEFAULT true,
    priority INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT REFERENCES staff_users(id),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_blotter_active ON blotter_messages(is_active);
CREATE INDEX IF NOT EXISTS idx_blotter_priority ON blotter_messages(priority);

-- Site messages (global announcements)
CREATE TABLE IF NOT EXISTS site_messages (
    id BIGSERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_html BOOLEAN DEFAULT false,
    boards TEXT[] DEFAULT '{}',
    is_active BOOLEAN DEFAULT true,
    start_at TIMESTAMP,
    end_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT REFERENCES staff_users(id),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_site_message_active ON site_messages(is_active);
CREATE INDEX IF NOT EXISTS idx_site_message_boards ON site_messages(boards);

-- ═══════════════════════════════════════════════════════════════
-- 7. LOGS & TOOLS
-- ═══════════════════════════════════════════════════════════════

-- Flood log
CREATE TABLE IF NOT EXISTS flood_log (
    id BIGSERIAL PRIMARY KEY,
    ip TEXT NOT NULL,
    ip_hash VARCHAR(64),
    board VARCHAR(20) NOT NULL,
    thread_id BIGINT,
    req_sig VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_flood_ip ON flood_log(ip);
CREATE INDEX IF NOT EXISTS idx_flood_board ON flood_log(board);
CREATE INDEX IF NOT EXISTS idx_flood_time ON flood_log(created_at);
CREATE INDEX IF NOT EXISTS idx_flood_log_ip_hash ON flood_log(ip_hash);

-- Blotter (simple version for frontend)
CREATE TABLE IF NOT EXISTS blotter (
    id SERIAL PRIMARY KEY,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    content TEXT NOT NULL,
    is_important BOOLEAN DEFAULT false
);

-- User actions (staff tool lookups)
CREATE TABLE IF NOT EXISTS user_actions (
    id BIGSERIAL PRIMARY KEY,
    ip_hash VARCHAR(64) NOT NULL,
    action VARCHAR(50) NOT NULL,
    board VARCHAR(16),
    postno BIGINT,
    time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    details JSONB
);

CREATE INDEX IF NOT EXISTS idx_user_actions_ip_hash ON user_actions(ip_hash);
CREATE INDEX IF NOT EXISTS idx_user_actions_time ON user_actions(time);

-- Blacklist
CREATE TABLE IF NOT EXISTS blacklist (
    id BIGSERIAL PRIMARY KEY,
    field VARCHAR(50) NOT NULL,
    contents TEXT NOT NULL,
    banreason TEXT,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_blacklist_field_contents ON blacklist(field, contents);

-- Post filter
CREATE TABLE IF NOT EXISTS postfilter (
    id BIGSERIAL PRIMARY KEY,
    pattern TEXT NOT NULL,
    regex BOOLEAN DEFAULT FALSE,
    board VARCHAR(16),
    active BOOLEAN DEFAULT TRUE,
    ban_days INTEGER DEFAULT 0,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_postfilter_active ON postfilter(active);

-- ═══════════════════════════════════════════════════════════════
-- 8. SITE SETTINGS
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS site_settings (
    key VARCHAR(255) PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '',
    description TEXT DEFAULT '',
    updated_by BIGINT REFERENCES staff_users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_site_settings_key ON site_settings(key);

CREATE TABLE IF NOT EXISTS site_settings_audit_log (
    id BIGSERIAL PRIMARY KEY,
    setting_key VARCHAR(255) NOT NULL,
    old_value TEXT,
    new_value TEXT NOT NULL,
    changed_by BIGINT REFERENCES staff_users(id),
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reason TEXT DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_site_settings_audit_key ON site_settings_audit_log(setting_key);
CREATE INDEX IF NOT EXISTS idx_site_settings_audit_time ON site_settings_audit_log(changed_at);

-- ═══════════════════════════════════════════════════════════════
-- 9. PII RETENTION & AUDIT
-- ═══════════════════════════════════════════════════════════════

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

CREATE TABLE IF NOT EXISTS sfs_audit_log (
    id BIGSERIAL PRIMARY KEY,
    report_id BIGINT NOT NULL,
    admin_user_id VARCHAR(128) NOT NULL,
    action VARCHAR(32) NOT NULL,
    reason TEXT DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sfs_audit_report ON sfs_audit_log(report_id);
CREATE INDEX IF NOT EXISTS idx_sfs_audit_admin ON sfs_audit_log(admin_user_id);
CREATE INDEX IF NOT EXISTS idx_sfs_audit_time ON sfs_audit_log(created_at);

CREATE TABLE IF NOT EXISTS pii_access_log (
    id BIGSERIAL PRIMARY KEY,
    admin_user_id VARCHAR(128) NOT NULL,
    table_name VARCHAR(64) NOT NULL,
    record_id VARCHAR(128) NOT NULL,
    column_name VARCHAR(64) NOT NULL,
    purpose VARCHAR(128) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pii_access_admin ON pii_access_log(admin_user_id);
CREATE INDEX IF NOT EXISTS idx_pii_access_time ON pii_access_log(created_at);

-- ═══════════════════════════════════════════════════════════════
-- 10. LEGACY COMPATIBILITY VIEWS
-- ═══════════════════════════════════════════════════════════════

-- Event log view (maps to admin_audit_log)
CREATE OR REPLACE VIEW event_log AS
SELECT
    id,
    action_type as type,
    ip_address as ip,
    board,
    resource_id as post_id,
    thread_id,
    description as arg_str,
    username as pwd,
    req_sig,
    created_at as created_on
FROM admin_audit_log;

-- Mod users view (maps to staff_users)
CREATE OR REPLACE VIEW mod_users AS
SELECT
    id,
    username,
    email as password,
    access_level as level,
    access_flags as flags,
    last_login_at as last_login,
    '{}'::text[] as ips,
    is_active,
    created_at
FROM staff_users;

-- Active sessions view
CREATE OR REPLACE VIEW active_staff_sessions AS
SELECT
    s.id, s.user_id, u.username, u.access_level,
    s.ip_address, s.user_agent, s.created_at,
    s.expires_at, s.last_activity,
    EXTRACT(EPOCH FROM (s.expires_at - NOW())) as seconds_remaining
FROM staff_sessions s
JOIN staff_users u ON s.user_id = u.id
WHERE s.is_valid = true AND s.expires_at > NOW();

-- ═══════════════════════════════════════════════════════════════
-- COMMENTS
-- ═══════════════════════════════════════════════════════════════

COMMENT ON TABLE pii_retention_log IS 'Immutable audit log of automated PII deletion actions. Never contains actual PII.';
COMMENT ON TABLE sfs_audit_log IS 'Tracks all SFS queue actions including decrypt approvals. Never logs decrypted IPs.';
COMMENT ON TABLE pii_access_log IS 'Records every instance of PII decryption by staff. Used for accountability.';
COMMENT ON COLUMN boards.staff_only IS 'When true, only authenticated staff can view and post on this board.';\nCOMMENT ON COLUMN boards.user_ids IS 'When true, display per-thread poster IDs (8-char hash derived from IP+thread+salt).';\nCOMMENT ON COLUMN boards.country_flags IS 'When true, display country flags on posts using GeoIP lookup.';\nCOMMENT ON COLUMN boards.next_post_no IS 'Atomic counter for per-board post numbering. Incremented via UPDATE ... RETURNING.';\nCOMMENT ON COLUMN posts.board_post_no IS 'Per-board post number (displayed to users instead of global ID).';\nCOMMENT ON COLUMN posts.poster_id IS '8-character per-thread poster ID derived from HMAC(IP, thread_id || daily_salt).';\nCOMMENT ON COLUMN posts.country_name IS 'Human-readable country name from GeoIP lookup.';

-- ═══════════════════════════════════════════════════════════════
-- FEEDBACK
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS feedback (
    id              BIGSERIAL PRIMARY KEY,
    category        VARCHAR(50) NOT NULL,
    subject         VARCHAR(150) NOT NULL,
    message         TEXT NOT NULL,
    board           VARCHAR(20) DEFAULT NULL,
    url             VARCHAR(500) DEFAULT NULL,
    browser         VARCHAR(500) DEFAULT NULL,
    priority        VARCHAR(20) NOT NULL DEFAULT 'normal',
    email           VARCHAR(200) DEFAULT NULL,
    name            VARCHAR(100) DEFAULT NULL,
    ip_address      VARCHAR(45) DEFAULT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'new',
    staff_notes     TEXT DEFAULT NULL,
    resolved_by     INTEGER DEFAULT NULL REFERENCES staff_users(id),
    resolved_at     TIMESTAMPTZ DEFAULT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_feedback_category ON feedback(category);
CREATE INDEX idx_feedback_status ON feedback(status);
CREATE INDEX idx_feedback_priority ON feedback(priority);
CREATE INDEX idx_feedback_created_at ON feedback(created_at DESC);

COMMENT ON TABLE feedback IS 'User-submitted feedback, suggestions, and bug reports.';
COMMENT ON COLUMN feedback.ip_address IS 'Stored for anti-spam; automatically purged after 7 days.';

COMMIT;
