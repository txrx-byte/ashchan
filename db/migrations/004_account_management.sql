-- Staff Account Management Schema

-- Staff accounts table (already exists as staff_users, adding more fields)
ALTER TABLE staff_users ADD COLUMN IF NOT EXISTS capcode VARCHAR(100) DEFAULT NULL;
ALTER TABLE staff_users ADD COLUMN IF NOT EXISTS capcode_label VARCHAR(100) DEFAULT NULL;
ALTER TABLE staff_users ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL;
ALTER TABLE staff_users ADD COLUMN IF NOT EXISTS boards_allowed TEXT[] DEFAULT '{}';

-- Capcodes table
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

CREATE INDEX idx_capcode_tripcode ON capcodes(tripcode);
CREATE INDEX idx_capcode_active ON capcodes(is_active);

-- IP Range Bans table
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

CREATE INDEX idx_ip_range_start ON ip_range_bans(range_start);
CREATE INDEX idx_ip_range_end ON ip_range_bans(range_end);
CREATE INDEX idx_ip_range_active ON ip_range_bans(is_active);

-- Autopurge rules table
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

CREATE INDEX idx_autopurge_pattern ON autopurge_rules(pattern);
CREATE INDEX idx_autopurge_active ON autopurge_rules(is_active);

-- DMCA notices table
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

CREATE INDEX idx_dmca_status ON dmca_notices(status);
CREATE INDEX idx_dmca_received ON dmca_notices(received_at);

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

CREATE INDEX idx_dmca_takedown_notice ON dmca_takedowns(notice_id);
CREATE INDEX idx_dmca_takedown_board ON dmca_takedowns(board);

-- Blotter messages table
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

CREATE INDEX idx_blottter_active ON blotter_messages(is_active);
CREATE INDEX idx_blottter_priority ON blotter_messages(priority);

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

CREATE INDEX idx_site_message_active ON site_messages(is_active);
CREATE INDEX idx_site_message_boards ON site_messages(boards);

-- Insert default capcode for admin
INSERT INTO capcodes (name, tripcode, label, color, boards, is_active) VALUES
('Admin', '!!AdminTripCode123', 'Administrator', '#FF0000', '{}', true),
('Mod', '!!ModTripCode456', 'Moderator', '#0000FF', '{}', true)
ON CONFLICT (tripcode) DO NOTHING;
