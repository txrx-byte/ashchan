-- Site Settings (Feature Toggles)
-- Provides a key-value store for runtime-configurable site settings,
-- allowing admins to enable/disable features like Spur, SFS, etc.
-- without restarting services or changing environment variables.

CREATE TABLE IF NOT EXISTS site_settings (
    key         VARCHAR(255) PRIMARY KEY,
    value       TEXT NOT NULL DEFAULT '',
    description TEXT DEFAULT '',
    updated_by  BIGINT REFERENCES staff_users(id),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Index for fast lookups
CREATE INDEX IF NOT EXISTS idx_site_settings_key ON site_settings(key);

-- Audit log for setting changes
CREATE TABLE IF NOT EXISTS site_settings_audit_log (
    id          BIGSERIAL PRIMARY KEY,
    setting_key VARCHAR(255) NOT NULL,
    old_value   TEXT,
    new_value   TEXT NOT NULL,
    changed_by  BIGINT REFERENCES staff_users(id),
    changed_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reason      TEXT DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_site_settings_audit_key ON site_settings_audit_log(setting_key);
CREATE INDEX IF NOT EXISTS idx_site_settings_audit_time ON site_settings_audit_log(changed_at);

-- Seed default settings for anti-spam feature toggles
INSERT INTO site_settings (key, value, description) VALUES
    ('spur_enabled',     'false', 'Enable Spur.us IP intelligence integration for VPN/proxy/bot detection'),
    ('sfs_enabled',      'true',  'Enable StopForumSpam integration for spam IP/email/username checks')
ON CONFLICT (key) DO NOTHING;
