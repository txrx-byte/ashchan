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
-- Ashchan – Seed Data (Fresh Install)
-- ============================================================
-- Run after install.sql to populate initial configuration data.
--
-- Usage:
--   psql -U ashchan -d ashchan -f seed.sql
--
-- !! WARNING !!
-- This creates a default admin account with password: admin123
-- CHANGE THIS PASSWORD IMMEDIATELY after first login at /staff/login
-- ============================================================

BEGIN;

-- ═══════════════════════════════════════════════════════════════
-- 1. ACCESS LEVELS & PERMISSIONS
-- ═══════════════════════════════════════════════════════════════

INSERT INTO access_levels (level_name, level_order, description, inherits_from) VALUES
    ('janitor', 1, 'Basic janitor access – can view and clear reports', NULL),
    ('mod',     2, 'Moderator – can approve bans and manage posts', 'janitor'),
    ('manager', 3, 'Manager – full configuration access', 'mod'),
    ('admin',   4, 'Administrator – unrestricted access', 'manager')
ON CONFLICT (level_name) DO NOTHING;

INSERT INTO permissions (permission_name, description, category) VALUES
    ('reports.view',       'View report queue',           'reports'),
    ('reports.clear',      'Clear reports',               'reports'),
    ('reports.delete',     'Delete reports',              'reports'),
    ('reports.approve_ban','Approve ban requests',         'reports'),
    ('bans.view',          'View bans',                   'bans'),
    ('bans.create',        'Create bans',                 'bans'),
    ('bans.edit',          'Edit bans',                   'bans'),
    ('config.categories',  'Manage report categories',     'config'),
    ('staff.view',         'View staff roster',            'staff'),
    ('staff.add',          'Add staff accounts',           'staff'),
    ('system.logs',        'View system logs',             'system')
ON CONFLICT (permission_name) DO NOTHING;

-- ═══════════════════════════════════════════════════════════════
-- 2. DEFAULT ADMIN ACCOUNT
-- ═══════════════════════════════════════════════════════════════
-- Username: admin
-- Password: admin123
-- !! CHANGE THIS PASSWORD IMMEDIATELY !!

INSERT INTO staff_users (username, email, password_hash, access_level, access_flags, is_active)
VALUES (
    'admin',
    'admin@localhost',
    -- bcrypt hash of 'admin123' (cost 12)
    '$2y$12$QdUQzAJwmQNhTEAL0gl3IOwuSnx0JspcbEzjypJashCGi9Dez3xma',
    'admin',
    ARRAY['all'],
    true
) ON CONFLICT (username) DO NOTHING;

-- ═══════════════════════════════════════════════════════════════
-- 3. CAPCODES
-- ═══════════════════════════════════════════════════════════════
-- Colors match OpenYotsuba/janichan.css:
--   Admin:     #FF0000 (red)
--   Moderator: #800080 (purple)
--   Janitor:   #117743 (green)

INSERT INTO capcodes (name, tripcode, label, color, boards, is_active) VALUES
    ('Admin',     '!!AdminCapcode',   'Administrator', '#FF0000', '{}', true),
    ('Mod',       '!!ModCapcode',     'Moderator',     '#800080', '{}', true),
    ('Janitor',   '!!JanitorCapcode', 'Janitor',       '#117743', '{j}', true)
ON CONFLICT (tripcode) DO UPDATE SET
    color = EXCLUDED.color,
    label = EXCLUDED.label,
    boards = EXCLUDED.boards;

-- ═══════════════════════════════════════════════════════════════
-- 4. REPORT CATEGORIES
-- ═══════════════════════════════════════════════════════════════

INSERT INTO report_categories (id, board, title, weight, exclude_boards, filtered, op_only, reply_only, image_only)
VALUES (31, '', 'This post violates applicable law.', 1000.00, '', 0, 0, 0, 0)
ON CONFLICT (id) DO NOTHING;

INSERT INTO report_categories (id, board, title, weight, exclude_boards, filtered, op_only, reply_only, image_only)
VALUES
    (1,  '_all_', 'Racism',                          10.00, '', 0, 0, 0, 0),
    (2,  '_all_', 'Pornography',                     50.00, '', 0, 0, 0, 0),
    (3,  '_all_', 'Advertising / Spam',              30.00, '', 0, 0, 0, 0),
    (4,  '_all_', 'Off-topic',                       5.00,  '', 0, 0, 0, 0),
    (5,  '_all_', 'Trolling',                        5.00,  '', 0, 0, 0, 0),
    (6,  '_all_', 'Harassment / Doxxing',            80.00, '', 0, 0, 0, 0),
    (7,  '_all_', 'Gore / Shock content',            90.00, '', 0, 0, 0, 0),
    (8,  '_all_', 'Underage content',               100.00, '', 0, 0, 0, 0),
    (9,  '_all_', 'Ban evasion',                     20.00, '', 0, 0, 0, 0),
    (10, '_all_', 'Flooding / Duplicate threads',    15.00, '', 0, 0, 0, 0),
    (11, '_ws_',  'NSFW on worksafe board',          40.00, '', 0, 0, 0, 0)
ON CONFLICT (id) DO NOTHING;

-- ═══════════════════════════════════════════════════════════════
-- 5. BAN TEMPLATES (from OpenYotsuba setup.php)
-- ═══════════════════════════════════════════════════════════════

INSERT INTO ban_templates (rule, name, ban_type, ban_days, banlen, public_reason, private_reason, publicban, can_warn, is_public, action, save_type, blacklist_image, exclude, appealable, active) VALUES
    ('global1', 'Child Pornography (Explicit Image)',     'zonly',  -1, 'indefinite', 'Child pornography', 'Child pornography', 0, 0, 1, 'quarantine',          '', 1, '__nofile__', 0, 1),
    ('global1', 'Child Pornography (Non-Explicit Image)', 'zonly',  -1, 'indefinite', 'Child pornography', 'Child pornography', 0, 0, 1, 'revokepass_illegal',  '', 1, '__nofile__', 0, 1),
    ('global1', 'Child Pornography (Links)',              'zonly',  -1, 'indefinite', 'Child pornography', 'Child pornography', 0, 0, 1, 'revokepass_illegal',  '', 0, '',           0, 1),
    ('global1', 'Illegal content',                        'zonly',  -1, 'indefinite', 'You will not upload, post, discuss, request, or link to anything that violates applicable law.', 'Illegal content', 0, 0, 1, 'revokepass_illegal', '', 0, '', 0, 1),
    ('global2', 'NSFW on blue board',                     'global',  1, '',           'All boards with the Yotsuba B style as the default are to be considered "work safe". Violators may be temporarily banned and their posts removed. Note: Spoilered pornography or other "not safe for work" content is NOT allowed.', 'NSFW on blue board', 1, 1, 1, 'delfile', 'everything', 0, '__nws__', 1, 1),
    ('global3', 'False reports',                          'global',  0, '',           'Submitting false or misclassified reports, or otherwise abusing the reporting system may result in a ban.', 'False reports', 0, 1, 0, '', '', 0, '', 1, 1),
    ('global4', 'Ban evasion',                            'global', -1, 'indefinite', 'Evading your ban will result in a permanent one. Instead, wait and appeal it!', 'Ban evasion', 1, 1, 1, '', 'everything', 0, '', 1, 1),
    ('global5', 'Spam',                                   'global',  1, '',           'No spamming or flooding of any kind. No intentionally evading spam or post filters.', 'Spam', 0, 1, 1, 'delall', 'everything', 0, '', 1, 1),
    ('global6', 'Advertising',                            'global',  1, '',           'Advertising (all forms) is not welcome—this includes any type of referral linking, "offers", soliciting, begging, stream threads, etc.', 'Advertising', 0, 1, 0, 'delall', '', 0, '', 1, 1)
ON CONFLICT DO NOTHING;

-- ═══════════════════════════════════════════════════════════════
-- 6. BOARDS
-- ═══════════════════════════════════════════════════════════════

-- Japanese Culture
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit) VALUES
    ('a',   'Anime & Manga',     'Anime & Manga',     NULL, 'Japanese Culture', false, 200, 300, 150),
    ('c',   'Anime/Cute',        'Anime/Cute',        NULL, 'Japanese Culture', false, 200, 300, 150),
    ('w',   'Anime/Wallpapers',  'Anime/Wallpapers',  NULL, 'Japanese Culture', false, 200, 300, 150),
    ('m',   'Mecha',             'Mecha',             NULL, 'Japanese Culture', false, 200, 300, 150),
    ('cgl', 'Cosplay & EGL',     'Cosplay & EGL',     NULL, 'Japanese Culture', false, 200, 300, 150),
    ('cm',  'Cute/Male',         'Cute/Male',         NULL, 'Japanese Culture', false, 200, 300, 150),
    ('f',   'Flash',             'Flash',             NULL, 'Japanese Culture', false, 200, 300, 150),
    ('n',   'Transportation',    'Transportation',    NULL, 'Japanese Culture', false, 200, 300, 150),
    ('jp',  'Otaku Culture',     'Otaku Culture',     NULL, 'Japanese Culture', false, 200, 300, 150),
    ('vt',  'Virtual YouTubers', 'Virtual YouTubers', NULL, 'Japanese Culture', false, 200, 300, 150)
ON CONFLICT (slug) DO NOTHING;

-- Video Games
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit) VALUES
    ('v',    'Video Games',             'Video Games',             NULL, 'Video Games', false, 200, 300, 150),
    ('vg',   'Video Game Generals',     'Video Game Generals',     NULL, 'Video Games', false, 200, 300, 150),
    ('vm',   'Video Games/Multiplayer', 'Video Games/Multiplayer', NULL, 'Video Games', false, 200, 300, 150),
    ('vmg',  'Video Games/Mobile',      'Video Games/Mobile',      NULL, 'Video Games', false, 200, 300, 150),
    ('vp',   'Pokemon',                 'Pokemon',                 NULL, 'Video Games', false, 200, 300, 150),
    ('vr',   'Retro Games',             'Retro Games',             NULL, 'Video Games', false, 200, 300, 150),
    ('vrpg', 'Video Games/RPG',         'Video Games/RPG',         NULL, 'Video Games', false, 200, 300, 150),
    ('vst',  'Video Games/Strategy',    'Video Games/Strategy',    NULL, 'Video Games', false, 200, 300, 150)
ON CONFLICT (slug) DO NOTHING;

-- Interests
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit) VALUES
    ('co',  'Comics & Cartoons',      'Comics & Cartoons',      NULL, 'Interests', false, 200, 300, 150),
    ('g',   'Technology',             'Technology',             NULL, 'Interests', false, 200, 300, 150),
    ('tv',  'Television & Film',      'Television & Film',      NULL, 'Interests', false, 200, 300, 150),
    ('k',   'Weapons',               'Weapons',               NULL, 'Interests', false, 200, 300, 150),
    ('o',   'Auto',                   'Auto',                   NULL, 'Interests', false, 200, 300, 150),
    ('an',  'Animals & Nature',       'Animals & Nature',       NULL, 'Interests', false, 200, 300, 150),
    ('tg',  'Traditional Games',      'Traditional Games',      NULL, 'Interests', false, 200, 300, 150),
    ('sp',  'Sports',                'Sports',                NULL, 'Interests', false, 200, 300, 150),
    ('xs',  'Extreme Sports',        'Extreme Sports',        NULL, 'Interests', false, 200, 300, 150),
    ('pw',  'Professional Wrestling', 'Professional Wrestling', NULL, 'Interests', false, 200, 300, 150),
    ('sci', 'Science & Math',        'Science & Math',        NULL, 'Interests', false, 200, 300, 150),
    ('his', 'History & Humanities',   'History & Humanities',   NULL, 'Interests', false, 200, 300, 150),
    ('int', 'International',         'International',         NULL, 'Interests', false, 200, 300, 150),
    ('out', 'Outdoors',              'Outdoors',              NULL, 'Interests', false, 200, 300, 150),
    ('toy', 'Toys',                  'Toys',                  NULL, 'Interests', false, 200, 300, 150)
ON CONFLICT (slug) DO NOTHING;

-- Creative
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit) VALUES
    ('i',   'Oekaki',               'Oekaki',               NULL, 'Creative', false, 200, 300, 150),
    ('po',  'Papercraft & Origami', 'Papercraft & Origami', NULL, 'Creative', false, 200, 300, 150),
    ('p',   'Photography',          'Photography',          NULL, 'Creative', false, 200, 300, 150),
    ('ck',  'Food & Cooking',       'Food & Cooking',       NULL, 'Creative', false, 200, 300, 150),
    ('ic',  'Artwork/Critique',     'Artwork/Critique',     NULL, 'Creative', false, 200, 300, 150),
    ('wg',  'Wallpapers/General',   'Wallpapers/General',   NULL, 'Creative', false, 200, 300, 150),
    ('lit', 'Literature',           'Literature',           NULL, 'Creative', false, 200, 300, 150),
    ('mu',  'Music',                'Music',                NULL, 'Creative', false, 200, 300, 150),
    ('fa',  'Fashion',              'Fashion',              NULL, 'Creative', false, 200, 300, 150),
    ('3',   '3DCG',                 '3DCG',                 NULL, 'Creative', false, 200, 300, 150),
    ('gd',  'Graphic Design',       'Graphic Design',       NULL, 'Creative', false, 200, 300, 150),
    ('diy', 'Do It Yourself',       'Do It Yourself',       NULL, 'Creative', false, 200, 300, 150),
    ('wsg', 'Worksafe GIF',         'Worksafe GIF',         NULL, 'Creative', false, 200, 300, 150),
    ('qst', 'Quests',               'Quests',               NULL, 'Creative', false, 200, 300, 150)
ON CONFLICT (slug) DO NOTHING;

-- Other
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit) VALUES
    ('biz',  'Business & Finance',   'Business & Finance',   NULL, 'Other', false, 200, 300, 150),
    ('trv',  'Travel',               'Travel',               NULL, 'Other', false, 200, 300, 150),
    ('fit',  'Fitness',              'Fitness',              NULL, 'Other', false, 200, 300, 150),
    ('x',    'Paranormal',           'Paranormal',           NULL, 'Other', false, 200, 300, 150),
    ('adv',  'Advice',               'Advice',               NULL, 'Other', false, 200, 300, 150),
    ('lgbt', 'LGBT',                 'LGBT',                 NULL, 'Other', false, 200, 300, 150),
    ('mlp',  'My Little Pony',       'My Little Pony',       NULL, 'Other', false, 200, 300, 150),
    ('news', 'Current News',         'Current News',         NULL, 'Other', false, 200, 300, 150),
    ('wsr',  'Worksafe Requests',    'Worksafe Requests',    NULL, 'Other', false, 200, 300, 150),
    ('vip',  'Very Important Posts', 'Very Important Posts',  NULL, 'Other', false, 200, 300, 150)
ON CONFLICT (slug) DO NOTHING;

-- Misc. (NSFW)
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit) VALUES
    ('b',    'Random',               'Random',               NULL, 'Misc. (NSFW)', true, 200, 300, 150),
    ('r9k',  'ROBOT9001',            'ROBOT9001',            NULL, 'Misc. (NSFW)', true, 200, 300, 150),
    ('pol',  'Politically Incorrect','Politically Incorrect', NULL, 'Misc. (NSFW)', true, 200, 300, 150),
    ('bant', 'International/Random', 'International/Random',  NULL, 'Misc. (NSFW)', true, 200, 300, 150),
    ('soc',  'Cams & Meetups',       'Cams & Meetups',       NULL, 'Misc. (NSFW)', true, 200, 300, 150),
    ('s4s',  'Shit 4chan Says',       'Shit 4chan Says',       NULL, 'Misc. (NSFW)', true, 200, 300, 150)
ON CONFLICT (slug) DO NOTHING;

-- Adult (NSFW)
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit) VALUES
    ('s',   'Sexy Beautiful Women', 'Sexy Beautiful Women', NULL, 'Adult (NSFW)', true, 200, 300, 150),
    ('hc',  'Hardcore',             'Hardcore',             NULL, 'Adult (NSFW)', true, 200, 300, 150),
    ('hm',  'Handsome Men',         'Handsome Men',         NULL, 'Adult (NSFW)', true, 200, 300, 150),
    ('h',   'Hentai',               'Hentai',               NULL, 'Adult (NSFW)', true, 200, 300, 150),
    ('e',   'Ecchi',                'Ecchi',                NULL, 'Adult (NSFW)', true, 200, 300, 150),
    ('u',   'Yuri',                 'Yuri',                 NULL, 'Adult (NSFW)', true, 200, 300, 150),
    ('d',   'Hentai/Alternative',   'Hentai/Alternative',   NULL, 'Adult (NSFW)', true, 200, 300, 150),
    ('y',   'Yaoi',                 'Yaoi',                 NULL, 'Adult (NSFW)', true, 200, 300, 150),
    ('t',   'Torrents',             'Torrents',             NULL, 'Adult (NSFW)', true, 200, 300, 150),
    ('hr',  'High Resolution',      'High Resolution',      NULL, 'Adult (NSFW)', true, 200, 300, 150),
    ('gif', 'Adult GIF',            'Adult GIF',            NULL, 'Adult (NSFW)', true, 200, 300, 150),
    ('aco', 'Adult Cartoons',       'Adult Cartoons',       NULL, 'Adult (NSFW)', true, 200, 300, 150),
    ('r',   'Adult Requests',       'Adult Requests',       NULL, 'Adult (NSFW)', true, 200, 300, 150)
ON CONFLICT (slug) DO NOTHING;

-- Staff Only
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit, staff_only) VALUES
    ('j', 'Janitor & Moderator Discussion', 'Janitor & Moderator Discussion',
     'Staff discussion board – capcodes are automatically applied',
     'Staff', false, 100, 300, 150, true)
ON CONFLICT (slug) DO NOTHING;

-- ═══════════════════════════════════════════════════════════════
-- 7. SITE SETTINGS
-- ═══════════════════════════════════════════════════════════════

INSERT INTO site_settings (key, value, description, category, value_type) VALUES
    -- ── Integrations ──
    ('spur_enabled',            'false',  'Enable Spur.us IP intelligence integration for VPN/proxy/bot detection',   'integrations', 'bool'),
    ('sfs_enabled',             'false',  'Enable StopForumSpam integration for spam IP/email/username checks',       'integrations', 'bool'),
    ('spur_timeout',            '3',      'Spur API request timeout (seconds)',                                       'integrations', 'int'),
    ('sfs_confidence_threshold','80',     'StopForumSpam confidence % threshold to flag as spam (0-100)',             'integrations', 'int'),
    ('sfs_check_timeout',       '2',      'StopForumSpam check API timeout (seconds)',                                'integrations', 'int'),
    ('sfs_report_timeout',      '5',      'StopForumSpam report API timeout (seconds)',                               'integrations', 'int'),
    ('sfs_api_key',             '',       'StopForumSpam API key for reporting (leave empty to disable)',              'integrations', 'string'),
    ('spur_api_token',          '',       'Spur.us API token for IP intelligence (leave empty to disable)',            'integrations', 'string'),

    -- ── Rate Limiting ──
    ('rate_limit_window',       '60',     'Global gateway rate limit window (seconds)',                                'rate_limiting', 'int'),
    ('rate_limit_max_requests', '120',    'Max requests per IP per window',                                           'rate_limiting', 'int'),
    ('post_rate_window',        '60',     'Post rate limit sliding window (seconds)',                                  'rate_limiting', 'int'),
    ('post_rate_limit',         '5',      'Max posts per IP per window',                                              'rate_limiting', 'int'),
    ('thread_rate_limit',       '1',      'Max threads per IP per window',                                            'rate_limiting', 'int'),
    ('thread_rate_window',      '300',    'Thread creation rate window (seconds)',                                     'rate_limiting', 'int'),
    ('feedback_rate_limit',     '5',      'Max feedback submissions per IP per hour',                                 'rate_limiting', 'int'),
    ('login_rate_limit',        '10',     'Max login attempts per IP per window',                                     'rate_limiting', 'int'),
    ('login_rate_window',       '300',    'Login rate limit window (seconds)',                                         'rate_limiting', 'int'),

    -- ── Spam / Risk Scoring ──
    ('altcha_enabled',          'true',   'Enable ALTCHA proof-of-work captcha for all posts and replies',            'spam', 'bool'),
    ('altcha_hmac_key',         '',       'HMAC key for ALTCHA challenge signing (auto-generated if empty)',           'spam', 'string'),
    ('captcha_ttl',             '300',    'Captcha validity period (seconds)',                                         'spam', 'int'),
    ('risk_threshold_block',    '10',     'Spam score threshold to auto-block a post',                                'spam', 'int'),
    ('risk_threshold_high',     '7',      'Spam score threshold to escalate (require captcha)',                        'spam', 'int'),
    ('duplicate_fingerprint_ttl','3600',  'Duplicate content detection window (seconds)',                              'spam', 'int'),
    ('min_fingerprint_length',  '10',     'Minimum content length for duplicate fingerprint check',                   'spam', 'int'),
    ('url_count_threshold',     '3',      'URLs in a post above this count trigger spam scoring',                     'spam', 'int'),
    ('repeated_char_threshold', '9',      'Repeated character count to trigger spam scoring',                         'spam', 'int'),
    ('caps_ratio_threshold',    '0.7',    'Caps-to-alpha ratio above which spam scoring triggers',                    'spam', 'float'),
    ('ip_reputation_ttl',       '86400',  'IP reputation penalty expiry (seconds)',                                   'spam', 'int'),
    ('reputation_escalation_threshold','7.0','Spam score at which IP reputation is incremented',                      'spam', 'float'),
    ('captcha_length',          '6',      'Number of characters in generated captcha',                                'spam', 'int'),
    ('excessive_length_threshold','1500', 'Post length in characters that triggers spam scoring',                     'spam', 'int'),

    -- ── Moderation Queue ──
    ('report_global_threshold', '1500',   'Report weight after which a report is globally unlocked',                  'moderation', 'int'),
    ('report_highlight_threshold','500',  'Report weight threshold for highlighting',                                 'moderation', 'int'),
    ('thread_weight_boost',     '1.25',   'Weight multiplier for thread reports vs post reports',                     'moderation', 'float'),
    ('abuse_clear_days',        '3',      'Days to look back when checking for report abuse',                         'moderation', 'int'),
    ('abuse_clear_count',       '50',     'Cleared reports threshold for auto-ban of report abuser',                  'moderation', 'int'),
    ('abuse_clear_ban_interval','5',      'Minimum days between consecutive report abuse auto-bans',                  'moderation', 'int'),
    ('report_abuse_template_id','190',    'Ban template ID used for report abuse auto-bans',                          'moderation', 'int'),

    -- ── Authentication / Sessions ──
    ('session_ttl',             '604800', 'User session lifetime (seconds, default 7 days)',                           'auth', 'int'),
    ('staff_session_timeout',   '8',      'Staff session timeout (hours)',                                            'auth', 'int'),
    ('max_login_attempts',      '5',      'Max login attempts before account lockout',                                'auth', 'int'),
    ('lockout_duration_minutes','30',     'Minutes an account is locked out after max login attempts',                'auth', 'int'),
    ('csrf_token_expiry_hours', '24',     'CSRF token lifetime (hours)',                                              'auth', 'int'),
    ('session_cache_ttl',       '60',     'Redis cache TTL for validated sessions (seconds)',                          'auth', 'int'),
    ('bcrypt_cost',             '12',     'bcrypt cost factor for password hashing',                                  'auth', 'int'),
    ('max_username_length',     '64',     'Maximum username length for registration',                                 'auth', 'int'),
    ('max_password_length',     '256',    'Maximum password length for registration',                                 'auth', 'int'),
    ('max_ban_duration',        '31536000','Maximum ban duration (seconds, default 1 year)',                           'auth', 'int'),
    ('min_ban_duration',        '60',     'Minimum ban duration (seconds)',                                           'auth', 'int'),
    ('max_email_length',        '254',    'Maximum email length per RFC 5321',                                        'auth', 'int'),
    ('login_rate_limit',        '10',     'Maximum login attempts per IP within rate-limit window',                   'auth', 'int'),
    ('login_rate_window',       '300',    'Login rate-limit sliding window in seconds',                               'auth', 'int'),
    ('ip_hmac_key',             '',       'HMAC key for IP hashing (leave empty to fall back to PII_ENCRYPTION_KEY env)', 'auth', 'string'),
    ('ip_hash_salt',            '',       'Salt for IP hashing in boards service (leave empty to fall back to IP_HASH_SALT env)', 'auth', 'string'),

    -- ── Media / Uploads ──
    ('max_file_size',           '4194304','Max upload file size in bytes (default 4MB)',                               'media', 'int'),
    ('allowed_mime_types',      'image/jpeg,image/png,image/gif,image/webp', 'Comma-separated allowed MIME types',    'media', 'string'),
    ('thumbnail_max_width',     '250',    'Thumbnail max width in pixels',                                            'media', 'int'),
    ('thumbnail_max_height',    '250',    'Thumbnail max height in pixels',                                           'media', 'int'),

    -- ── Search ──
    ('search_index_ttl',        '604800', 'Search index TTL (seconds, default 7 days)',                               'search', 'int'),
    ('search_default_per_page', '25',     'Default results per page for search queries',                               'search', 'int'),
    ('search_results_per_page', '25',     'Default search results per page',                                          'search', 'int'),
    ('search_min_query_length', '2',      'Minimum search query length in characters',                                'search', 'int'),
    ('search_excerpt_length',   '200',    'Length of search result text excerpt',                                      'search', 'int'),
    ('search_index_text_max',   '500',    'Max characters indexed per post for search',                               'search', 'int'),

    -- ── Cache TTLs ──
    ('cache_ttl_common_data',   '60',     'Cache TTL for boards list and blotter (seconds)',                           'cache', 'int'),
    ('cache_ttl_boards',        '300',    'Cache TTL for board listings (seconds)',                                    'cache', 'int'),
    ('varnish_url',             'http://127.0.0.1:6081', 'Varnish HTTP cache endpoint for BAN requests',              'cache', 'string'),

    -- ── 4chan-Compatible API ──
    ('fourchan_per_page',       '15',     '4chan-format API: posts per page',                                          'api', 'int'),
    ('fourchan_max_pages',      '10',     '4chan-format API: max pages per board',                                     'api', 'int'),
    ('fourchan_preview_replies','5',      '4chan-format API: preview replies on board index',                          'api', 'int'),
    ('fourchan_catalog_replies','5',      '4chan-format API: last replies shown in catalog',                           'api', 'int'),

    -- ── Data Retention (Privacy) ──
    ('retention_post_ip',       '30',     'Days to retain IP addresses in posts before purging',                       'retention', 'int'),
    ('retention_post_email',    '30',     'Days to retain email addresses in posts before purging',                    'retention', 'int'),
    ('retention_flood_log',     '1',      'Days to retain flood log entries',                                          'retention', 'int'),
    ('retention_report_ip',     '90',     'Days to retain IP addresses in reports',                                    'retention', 'int'),
    ('retention_ban_ip',        '30',     'Days to retain IP addresses in bans (after ban expiry)',                    'retention', 'int'),
    ('retention_sfs_pending',   '30',     'Days to retain pending SFS report entries',                                 'retention', 'int'),
    ('retention_report_clear_log','90',   'Days to retain report clear log IPs',                                       'retention', 'int'),
    ('retention_moderation_decisions','365','Days to retain moderation decision records',                               'retention', 'int'),
    ('retention_audit_log_ip',  '365',    'Days to retain IP addresses in audit logs',                                 'retention', 'int'),

    -- ── CORS ──
    ('cors_origins',            '*',      'Allowed CORS origins (comma-separated, or * for all)',                      'cors', 'string'),
    ('cors_max_age',            '3600',   'CORS preflight cache max-age (seconds)',                                    'cors', 'int'),

    -- ── Board Defaults ──
    ('blotter_display_limit',   '5',      'Number of blotter entries shown on home page',                              'api', 'int'),
    ('archive_thread_limit',    '3000',   'Max archived threads returned per board',                                   'api', 'int'),
    ('ip_post_search_limit',    '100',    'Default max posts returned when searching by IP hash',                      'moderation', 'int'),
    ('ip_post_scan_limit',      '5000',   'Max rows scanned when searching posts by IP hash',                          'moderation', 'int'),
    ('default_max_threads',     '200',    'Default max active threads when creating a board',                           'api', 'int'),
    ('default_bump_limit',      '300',    'Default bump limit per thread when creating a board',                        'api', 'int'),
    ('default_image_limit',     '150',    'Default image limit per thread when creating a board',                       'api', 'int'),
    ('default_cooldown_seconds','60',     'Default post cooldown when creating a board (seconds)',                      'api', 'int'),

    -- ── Media / Uploads ──
    ('max_file_size',           '4194304','Maximum upload file size in bytes (default 4MB)',                            'media', 'int'),
    ('allowed_mimes',           'image/jpeg,image/png,image/gif,image/webp', 'Allowed upload MIME types (comma-separated)', 'media', 'string'),
    ('thumb_max_width',         '250',    'Maximum thumbnail width in pixels',                                          'media', 'int'),
    ('thumb_max_height',        '250',    'Maximum thumbnail height in pixels',                                         'media', 'int'),
    ('upload_connect_timeout',  '3',      'cURL connect timeout for S3 uploads (seconds)',                              'media', 'int'),
    ('upload_timeout',          '15',     'cURL total timeout for S3 uploads (seconds)',                                'media', 'int'),
    ('local_storage_path',      '/workspaces/ashchan/data/media', 'Local disk fallback path for media when S3 is unreachable', 'media', 'string'),
    ('object_storage_bucket',   'ashchan', 'S3/MinIO bucket name for media storage',                                   'media', 'string'),
    ('object_storage_endpoint', 'http://minio:9000', 'S3/MinIO endpoint URL',                                          'media', 'string'),
    ('object_storage_access_key','minioadmin', 'S3/MinIO access key (credential)',                                      'media', 'string'),
    ('object_storage_secret_key','minioadmin', 'S3/MinIO secret key (credential)',                                      'media', 'string')

ON CONFLICT (key) DO NOTHING;

-- ═══════════════════════════════════════════════════════════════
-- 8. BLOTTER
-- ═══════════════════════════════════════════════════════════════

INSERT INTO blotter (content, is_important) VALUES
    ('Welcome to Ashchan! Please read the rules before posting.', false),
    ('The default admin password is admin123 – CHANGE IT IMMEDIATELY at /staff/login', true)
ON CONFLICT DO NOTHING;

COMMIT;
