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

-- Additional log tables for staff tools

-- Flood log table
CREATE TABLE IF NOT EXISTS flood_log (
    id BIGSERIAL PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    board VARCHAR(20) NOT NULL,
    thread_id BIGINT,
    req_sig VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_flood_ip ON flood_log(ip);
CREATE INDEX idx_flood_board ON flood_log(board);
CREATE INDEX idx_flood_time ON flood_log(created_at);

-- Event log table (legacy compatibility - maps to admin_audit_log)
CREATE VIEW event_log AS
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

-- User actions table (legacy compatibility)
CREATE VIEW user_actions AS
SELECT 
    id,
    action_type as action,
    ip_address as ip,
    board,
    resource_id as postno,
    created_at as time
FROM admin_audit_log
WHERE action_type IN ('delete', 'ban', 'clear');

-- Mod users view (legacy compatibility - maps to staff_users)
CREATE VIEW mod_users AS
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
