-- Ashchan Staff Authentication & Authorization Schema
-- Production-ready security schema
-- Run: docker exec ashchan-postgres psql -U ashchan -d ashchan -f /tmp/auth.sql

-- Staff users table with secure password storage
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
    CONSTRAINT valid_access_level CHECK (access_level IN ('janitor', 'mod', 'manager', 'admin')),
    CONSTRAINT failed_login_check CHECK (failed_login_attempts >= 0)
);

CREATE INDEX idx_staff_username ON staff_users(username);
CREATE INDEX idx_staff_email ON staff_users(email);
CREATE INDEX idx_staff_level ON staff_users(access_level);
CREATE INDEX idx_staff_active ON staff_users(is_active);

-- Access levels
CREATE TABLE IF NOT EXISTS access_levels (
    id SERIAL PRIMARY KEY,
    level_name VARCHAR(20) NOT NULL UNIQUE,
    level_order INTEGER NOT NULL,
    description TEXT,
    inherits_from VARCHAR(20)
);

INSERT INTO access_levels (level_name, level_order, description, inherits_from) VALUES
('janitor', 1, 'Basic janitor access', NULL),
('mod', 2, 'Moderator - can approve bans', 'janitor'),
('manager', 3, 'Manager - full config access', 'mod'),
('admin', 4, 'Administrator - full access', 'manager')
ON CONFLICT (level_name) DO NOTHING;

-- Permissions
CREATE TABLE IF NOT EXISTS permissions (
    id SERIAL PRIMARY KEY,
    permission_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    category VARCHAR(50)
);

INSERT INTO permissions (permission_name, description, category) VALUES
('reports.view', 'View report queue', 'reports'),
('reports.clear', 'Clear reports', 'reports'),
('reports.delete', 'Delete reports', 'reports'),
('reports.approve_ban', 'Approve ban requests', 'reports'),
('bans.view', 'View bans', 'bans'),
('bans.create', 'Create bans', 'bans'),
('bans.edit', 'Edit bans', 'bans'),
('config.categories', 'Manage report categories', 'config'),
('staff.view', 'View staff roster', 'staff'),
('staff.add', 'Add staff accounts', 'staff'),
('system.logs', 'View system logs', 'system')
ON CONFLICT (permission_name) DO NOTHING;

-- Level-permission mapping
CREATE TABLE IF NOT EXISTS level_permissions (
    level_name VARCHAR(20) REFERENCES access_levels(level_name),
    permission_id INTEGER REFERENCES permissions(id),
    PRIMARY KEY (level_name, permission_id)
);

-- Session tokens
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

CREATE INDEX idx_session_token ON staff_sessions(token_hash);
CREATE INDEX idx_session_user ON staff_sessions(user_id);
CREATE INDEX idx_session_expires ON staff_sessions(expires_at);

-- Audit log
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
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    request_uri VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_audit_user ON admin_audit_log(user_id);
CREATE INDEX idx_audit_action ON admin_audit_log(action_type);
CREATE INDEX idx_audit_time ON admin_audit_log(created_at);

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

CREATE INDEX idx_login_ip ON login_attempts(ip_address_hash);
CREATE INDEX idx_login_time ON login_attempts(created_at);

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

CREATE INDEX idx_csrf_token ON csrf_tokens(token_hash);
CREATE INDEX idx_csrf_expires ON csrf_tokens(expires_at);

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

-- Security settings
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

-- Update timestamp trigger
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

CREATE TRIGGER update_staff_users_updated_at BEFORE UPDATE ON staff_users
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- Default admin (CHANGE PASSWORD IMMEDIATELY!)
-- Password: ChangeMe123! (bcrypt cost 12)
INSERT INTO staff_users (username, email, password_hash, access_level, access_flags, is_active)
VALUES (
    'admin',
    'admin@localhost',
    '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4.G.2f2f2f2f2f2f',
    'admin',
    ARRAY['all'],
    true
) ON CONFLICT (username) DO NOTHING;

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
