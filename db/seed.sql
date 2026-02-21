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
) ON CONFLICT (username) DO UPDATE SET
    password_hash = EXCLUDED.password_hash,
    access_level = EXCLUDED.access_level,
    access_flags = EXCLUDED.access_flags,
    is_active = EXCLUDED.is_active;

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

INSERT INTO site_settings (key, value, description) VALUES
    ('spur_enabled', 'false', 'Enable Spur.us IP intelligence integration for VPN/proxy/bot detection'),
    ('sfs_enabled',  'false',  'Enable StopForumSpam integration for spam IP/email/username checks')
ON CONFLICT (key) DO NOTHING;

-- ═══════════════════════════════════════════════════════════════
-- 8. BLOTTER
-- ═══════════════════════════════════════════════════════════════

INSERT INTO blotter (content, is_important) VALUES
    ('Welcome to Ashchan! Please read the rules before posting.', false),
    ('The default admin password is admin123 – CHANGE IT IMMEDIATELY at /staff/login', true)
ON CONFLICT DO NOTHING;

COMMIT;
