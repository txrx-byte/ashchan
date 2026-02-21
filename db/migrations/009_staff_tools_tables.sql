-- Staff tools support tables: user_actions, blacklist, postfilter

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
