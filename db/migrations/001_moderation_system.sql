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

-- Ashchan Moderation System Migrations
-- Run this file directly in PostgreSQL

-- Reports table
CREATE TABLE IF NOT EXISTS reports (
    id BIGSERIAL PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    pwd VARCHAR(255),
    pass_id VARCHAR(255),
    req_sig VARCHAR(255),
    board VARCHAR(10) NOT NULL,
    no BIGINT NOT NULL,
    resto BIGINT DEFAULT 0,
    cat SMALLINT DEFAULT 1,
    weight DECIMAL(10,2) DEFAULT 1.00,
    report_category INTEGER NOT NULL,
    post_ip VARCHAR(45) NOT NULL,
    post_json TEXT NOT NULL,
    cleared SMALLINT DEFAULT 0,
    cleared_by VARCHAR(255) DEFAULT '',
    ws SMALLINT DEFAULT 0,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_board_cleared ON reports(board, cleared);
CREATE INDEX IF NOT EXISTS idx_post_board ON reports(no, board);
CREATE INDEX IF NOT EXISTS idx_cleared_ts ON reports(cleared, ts);
CREATE INDEX IF NOT EXISTS idx_ip_cleared ON reports(ip, cleared);
CREATE INDEX IF NOT EXISTS idx_category ON reports(report_category);

-- Report categories table
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

-- Insert canonical report category (ID 31 from 4chan)
INSERT INTO report_categories (id, board, title, weight, exclude_boards, filtered, op_only, reply_only, image_only, created_at, updated_at)
VALUES (31, '', 'This post violates applicable law.', 1000.00, '', 0, 0, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO NOTHING;

-- Ban templates table
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

-- Insert canonical ban templates from 4chan setup.php:168-176
INSERT INTO ban_templates (rule, name, ban_type, ban_days, banlen, public_reason, private_reason, publicban, can_warn, is_public, action, save_type, blacklist_image, exclude, appealable, active, created_at, updated_at) VALUES
('global1', 'Child Pornography (Explicit Image)', 'zonly', -1, 'indefinite', 'Child pornography', 'Child pornography', 0, 0, 1, 'quarantine', '', 1, '__nofile__', 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('global1', 'Child Pornography (Non-Explicit Image)', 'zonly', -1, 'indefinite', 'Child pornography', 'Child pornography', 0, 0, 1, 'revokepass_illegal', '', 1, '__nofile__', 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('global1', 'Child Pornography (Links)', 'zonly', -1, 'indefinite', 'Child pornography', 'Child pornography', 0, 0, 1, 'revokepass_illegal', '', 0, '', 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('global1', 'Illegal content', 'zonly', -1, 'indefinite', 'You will not upload, post, discuss, request, or link to anything that violates applicable law.', 'Illegal content', 0, 0, 1, 'revokepass_illegal', '', 0, '', 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('global2', 'NSFW on blue board', 'global', 1, '', 'All boards with the Yotsuba B style as the default are to be considered "work safe". Violators may be temporarily banned and their posts removed. Note: Spoilered pornography or other "not safe for work" content is NOT allowed.', 'NSFW on blue board', 1, 1, 1, 'delfile', 'everything', 0, '__nws__', 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('global3', 'False reports', 'global', 0, '', 'Submitting false or misclassified reports, or otherwise abusing the reporting system may result in a ban.', 'False reports', 0, 1, 0, '', '', 0, '', 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('global4', 'Ban evasion', 'global', -1, 'indefinite', 'Evading your ban will result in a permanent one. Instead, wait and appeal it!', 'Ban evasion', 1, 1, 1, '', 'everything', 0, '', 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('global5', 'Spam', 'global', 1, '', 'No spamming or flooding of any kind. No intentionally evading spam or post filters.', 'Spam', 0, 1, 1, 'delall', 'everything', 0, '', 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('global6', 'Advertising', 'global', 1, '', 'Advertising (all forms) is not welcome—this includes any type of referral linking, "offers", soliciting, begging, stream threads, etc.', 'Advertising', 0, 1, 0, 'delall', '', 0, '', 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT DO NOTHING;

-- Banned users table
CREATE TABLE IF NOT EXISTS banned_users (
    id BIGSERIAL PRIMARY KEY,
    board VARCHAR(10) DEFAULT '',
    global SMALLINT DEFAULT 0,
    zonly SMALLINT DEFAULT 0,
    name VARCHAR(255) DEFAULT 'Anonymous',
    host VARCHAR(45) NOT NULL,
    reverse VARCHAR(255) DEFAULT '',
    xff VARCHAR(255) DEFAULT '',
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
    admin_ip VARCHAR(45) DEFAULT '',
    active SMALLINT DEFAULT 1,
    appealable SMALLINT DEFAULT 1,
    unbannedon TIMESTAMP,
    ban_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_ban_host_active ON banned_users(host, active);
CREATE INDEX IF NOT EXISTS idx_ban_active_length ON banned_users(active, length);

-- Ban requests table
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

-- Report clear log table
CREATE TABLE IF NOT EXISTS report_clear_log (
    id BIGSERIAL PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    pwd VARCHAR(255),
    pass_id VARCHAR(255),
    category INTEGER NOT NULL,
    weight DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_clear_ip_created ON report_clear_log(ip, created_at);

-- Janitor stats table
CREATE TABLE IF NOT EXISTS janitor_stats (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    action_type SMALLINT NOT NULL,
    board VARCHAR(10) NOT NULL,
    post_id BIGINT NOT NULL,
    requested_tpl INTEGER NOT NULL,
    accepted_tpl INTEGER NOT NULL,
    created_by_id BIGINT NOT NULL,
    created_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_stats_user ON janitor_stats(user_id, created_on);
