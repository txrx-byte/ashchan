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

-- Board seeder: Initialize all boards (migrated from 4chan board layout)
-- Run with: podman exec ashchan-postgres-1 psql -U ashchan -d ashchan -f /app/db/seeders/boards.sql

-- ─────────────────────────────────────────────────────────────
-- Japanese Culture
-- ─────────────────────────────────────────────────────────────
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit)
VALUES
  ('a',   'Anime & Manga',       'Anime & Manga',       NULL, 'Japanese Culture', false, 200, 300, 150),
  ('c',   'Anime/Cute',          'Anime/Cute',          NULL, 'Japanese Culture', false, 200, 300, 150),
  ('w',   'Anime/Wallpapers',    'Anime/Wallpapers',    NULL, 'Japanese Culture', false, 200, 300, 150),
  ('m',   'Mecha',               'Mecha',               NULL, 'Japanese Culture', false, 200, 300, 150),
  ('cgl', 'Cosplay & EGL',       'Cosplay & EGL',       NULL, 'Japanese Culture', false, 200, 300, 150),
  ('cm',  'Cute/Male',           'Cute/Male',           NULL, 'Japanese Culture', false, 200, 300, 150),
  ('f',   'Flash',               'Flash',               NULL, 'Japanese Culture', false, 200, 300, 150),
  ('n',   'Transportation',      'Transportation',      NULL, 'Japanese Culture', false, 200, 300, 150),
  ('jp',  'Otaku Culture',       'Otaku Culture',       NULL, 'Japanese Culture', false, 200, 300, 150),
  ('vt',  'Virtual YouTubers',   'Virtual YouTubers',   NULL, 'Japanese Culture', false, 200, 300, 150)
ON CONFLICT (slug) DO NOTHING;

-- ─────────────────────────────────────────────────────────────
-- Video Games
-- ─────────────────────────────────────────────────────────────
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit)
VALUES
  ('v',   'Video Games',                'Video Games',                NULL, 'Video Games', false, 200, 300, 150),
  ('vg',  'Video Game Generals',        'Video Game Generals',        NULL, 'Video Games', false, 200, 300, 150),
  ('vm',  'Video Games/Multiplayer',    'Video Games/Multiplayer',    NULL, 'Video Games', false, 200, 300, 150),
  ('vmg', 'Video Games/Mobile',         'Video Games/Mobile',         NULL, 'Video Games', false, 200, 300, 150),
  ('vp',  'Pokemon',                    'Pokemon',                    NULL, 'Video Games', false, 200, 300, 150),
  ('vr',  'Retro Games',               'Retro Games',               NULL, 'Video Games', false, 200, 300, 150),
  ('vrpg','Video Games/RPG',            'Video Games/RPG',            NULL, 'Video Games', false, 200, 300, 150),
  ('vst', 'Video Games/Strategy',       'Video Games/Strategy',       NULL, 'Video Games', false, 200, 300, 150)
ON CONFLICT (slug) DO NOTHING;

-- ─────────────────────────────────────────────────────────────
-- Interests
-- ─────────────────────────────────────────────────────────────
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit)
VALUES
  ('co',  'Comics & Cartoons',     'Comics & Cartoons',     NULL, 'Interests', false, 200, 300, 150),
  ('g',   'Technology',            'Technology',            NULL, 'Interests', false, 200, 300, 150),
  ('tv',  'Television & Film',     'Television & Film',     NULL, 'Interests', false, 200, 300, 150),
  ('k',   'Weapons',              'Weapons',              NULL, 'Interests', false, 200, 300, 150),
  ('o',   'Auto',                  'Auto',                  NULL, 'Interests', false, 200, 300, 150),
  ('an',  'Animals & Nature',      'Animals & Nature',      NULL, 'Interests', false, 200, 300, 150),
  ('tg',  'Traditional Games',     'Traditional Games',     NULL, 'Interests', false, 200, 300, 150),
  ('sp',  'Sports',               'Sports',               NULL, 'Interests', false, 200, 300, 150),
  ('xs',  'Extreme Sports',       'Extreme Sports',       NULL, 'Interests', false, 200, 300, 150),
  ('pw',  'Professional Wrestling','Professional Wrestling',NULL, 'Interests', false, 200, 300, 150),
  ('sci', 'Science & Math',       'Science & Math',       NULL, 'Interests', false, 200, 300, 150),
  ('his', 'History & Humanities',  'History & Humanities',  NULL, 'Interests', false, 200, 300, 150),
  ('int', 'International',        'International',        NULL, 'Interests', false, 200, 300, 150),
  ('out', 'Outdoors',             'Outdoors',             NULL, 'Interests', false, 200, 300, 150),
  ('toy', 'Toys',                 'Toys',                 NULL, 'Interests', false, 200, 300, 150)
ON CONFLICT (slug) DO NOTHING;

-- ─────────────────────────────────────────────────────────────
-- Creative
-- ─────────────────────────────────────────────────────────────
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit)
VALUES
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

-- ─────────────────────────────────────────────────────────────
-- Other
-- ─────────────────────────────────────────────────────────────
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit)
VALUES
  ('biz', 'Business & Finance',   'Business & Finance',   NULL, 'Other', false, 200, 300, 150),
  ('trv', 'Travel',               'Travel',               NULL, 'Other', false, 200, 300, 150),
  ('fit', 'Fitness',              'Fitness',              NULL, 'Other', false, 200, 300, 150),
  ('x',   'Paranormal',           'Paranormal',           NULL, 'Other', false, 200, 300, 150),
  ('adv', 'Advice',               'Advice',               NULL, 'Other', false, 200, 300, 150),
  ('lgbt','LGBT',                  'LGBT',                  NULL, 'Other', false, 200, 300, 150),
  ('mlp', 'My Little Pony',       'My Little Pony',       NULL, 'Other', false, 200, 300, 150),
  ('news','Current News',         'Current News',         NULL, 'Other', false, 200, 300, 150),
  ('wsr', 'Worksafe Requests',    'Worksafe Requests',    NULL, 'Other', false, 200, 300, 150),
  ('vip', 'Very Important Posts', 'Very Important Posts', NULL, 'Other', false, 200, 300, 150)
ON CONFLICT (slug) DO NOTHING;

-- ─────────────────────────────────────────────────────────────
-- Misc. (NSFW)
-- ─────────────────────────────────────────────────────────────
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit)
VALUES
  ('b',   'Random',               'Random',               NULL, 'Misc. (NSFW)', true, 200, 300, 150),
  ('r9k', 'ROBOT9001',            'ROBOT9001',            NULL, 'Misc. (NSFW)', true, 200, 300, 150),
  ('pol', 'Politically Incorrect','Politically Incorrect',NULL, 'Misc. (NSFW)', true, 200, 300, 150),
  ('bant','International/Random', 'International/Random', NULL, 'Misc. (NSFW)', true, 200, 300, 150),
  ('soc', 'Cams & Meetups',       'Cams & Meetups',       NULL, 'Misc. (NSFW)', true, 200, 300, 150),
  ('s4s', 'Shit 4chan Says',       'Shit 4chan Says',       NULL, 'Misc. (NSFW)', true, 200, 300, 150)
ON CONFLICT (slug) DO NOTHING;

-- ─────────────────────────────────────────────────────────────
-- Adult (NSFW)
-- ─────────────────────────────────────────────────────────────
INSERT INTO boards (slug, name, title, subtitle, category, nsfw, max_threads, bump_limit, image_limit)
VALUES
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
