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

-- Boards/Threads/Posts schema
CREATE TABLE boards (
  id SERIAL PRIMARY KEY,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  slug VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(128) NOT NULL,
  description TEXT,
  settings JSONB,
  -- Additional board settings assumed by model
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
  rules TEXT
);

CREATE TABLE threads (
  id BIGINT PRIMARY KEY, -- Same as OP post ID
  board_id INTEGER NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  bumped_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  archived_at TIMESTAMPTZ, 
  op_post_id BIGINT,
  
  -- Missing columns required by App\Model\Thread
  sticky BOOLEAN DEFAULT false,
  locked BOOLEAN DEFAULT false,
  archived BOOLEAN DEFAULT false,
  reply_count INTEGER DEFAULT 0,
  image_count INTEGER DEFAULT 0
);

CREATE TABLE posts (
  id BIGSERIAL PRIMARY KEY,
  thread_id BIGINT NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
  author_id BIGINT, 
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  content TEXT NOT NULL,
  media_refs TEXT[],
  metadata JSONB,

  -- Missing columns required by App\Model\Post
  is_op BOOLEAN DEFAULT false,
  author_name VARCHAR(128),
  tripcode VARCHAR(128),
  capcode VARCHAR(128),
  email VARCHAR(128),
  subject VARCHAR(255),
  content_html TEXT,
  ip_address VARCHAR(45),
  country_code VARCHAR(4),
  
  -- Media fields
  media_id VARCHAR(64),
  media_url TEXT,
  thumb_url TEXT,
  media_filename VARCHAR(255),
  media_size INTEGER,
  media_dimensions VARCHAR(32),
  media_hash VARCHAR(64),
  spoiler_image BOOLEAN DEFAULT false,
  
  -- Deletion
  delete_password_hash VARCHAR(255),
  deleted BOOLEAN DEFAULT false,
  deleted_at TIMESTAMPTZ
);

ALTER TABLE threads ADD CONSTRAINT fk_op_post FOREIGN KEY (op_post_id) REFERENCES posts(id) ON DELETE SET NULL;

CREATE INDEX idx_threads_board_id ON threads(board_id);
CREATE INDEX idx_threads_bumped_at ON threads(bumped_at DESC);
CREATE INDEX idx_posts_thread_id ON posts(thread_id);
CREATE INDEX idx_posts_created_at ON posts(created_at DESC);

-- Initialize default boards (4chan list)
INSERT INTO boards (slug, name, title, category, nsfw) VALUES
('a', 'Anime & Manga', 'Anime & Manga', 'Japanese Culture', false),
('c', 'Anime/Cute', 'Anime/Cute', 'Japanese Culture', false),
('w', 'Anime/Wallpapers', 'Anime/Wallpapers', 'Japanese Culture', false),
('m', 'Mecha', 'Mecha', 'Japanese Culture', false),
('cgl', 'Cosplay & EGL', 'Cosplay & EGL', 'Japanese Culture', false),
('cm', 'Cute/Male', 'Cute/Male', 'Japanese Culture', false),
('f', 'Flash', 'Flash', 'Japanese Culture', false),
('n', 'Transportation', 'Transportation', 'Japanese Culture', false),
('jp', 'Otaku Culture', 'Otaku Culture', 'Japanese Culture', false),
('vt', 'Virtual YouTubers', 'Virtual YouTubers', 'Japanese Culture', false),
('v', 'Video Games', 'Video Games', 'Video Games', false),
('vg', 'Video Game Generals', 'Video Game Generals', 'Video Games', false),
('vmg', 'Video Games/Multiplayer', 'Video Games/Multiplayer', 'Video Games', false),
('vst', 'Video Games/Strategy', 'Video Games/Strategy', 'Video Games', false),
('vm', 'Video Games/Mobile', 'Video Games/Mobile', 'Video Games', false),
('vp', 'Pokémon', 'Pokémon', 'Video Games', false),
('vr', 'Retro Games', 'Retro Games', 'Video Games', false),
('vrpg', 'Video Games/RPG', 'Video Games/RPG', 'Video Games', false),
('co', 'Comics & Cartoons', 'Comics & Cartoons', 'Interests', false),
('g', 'Technology', 'Technology', 'Interests', false),
('tv', 'Television & Film', 'Television & Film', 'Interests', false),
('k', 'Weapons', 'Weapons', 'Interests', false),
('o', 'Auto', 'Auto', 'Interests', false),
('an', 'Animals & Nature', 'Animals & Nature', 'Interests', false),
('tg', 'Traditional Games', 'Traditional Games', 'Interests', false),
('sp', 'Sports', 'Sports', 'Interests', false),
('xs', 'Extreme Sports', 'Extreme Sports', 'Interests', false),
('pw', 'Professional Wrestling', 'Professional Wrestling', 'Interests', false),
('sci', 'Science & Math', 'Science & Math', 'Interests', false),
('his', 'History & Humanities', 'History & Humanities', 'Interests', false),
('int', 'International', 'International', 'Interests', false),
('out', 'Outdoors', 'Outdoors', 'Interests', false),
('toy', 'Toys', 'Toys', 'Interests', false),
('i', 'Oekaki', 'Oekaki', 'Creative', false),
('po', 'Papercraft & Origami', 'Papercraft & Origami', 'Creative', false),
('p', 'Photography', 'Photography', 'Creative', false),
('ck', 'Food & Cooking', 'Food & Cooking', 'Creative', false),
('ic', 'Artwork/Critique', 'Artwork/Critique', 'Creative', false),
('wg', 'Wallpapers/General', 'Wallpapers/General', 'Creative', false),
('lit', 'Literature', 'Literature', 'Creative', false),
('mu', 'Music', 'Music', 'Creative', false),
('fa', 'Fashion', 'Fashion', 'Creative', false),
('3', '3DCG', '3DCG', 'Creative', false),
('gd', 'Graphic Design', 'Graphic Design', 'Creative', false),
('diy', 'Do-It-Yourself', 'Do-It-Yourself', 'Creative', false),
('wsg', 'Worksafe GIF', 'Worksafe GIF', 'Creative', false),
('qst', 'Quests', 'Quests', 'Creative', false),
('biz', 'Business & Finance', 'Business & Finance', 'Other', false),
('trv', 'Travel', 'Travel', 'Other', false),
('fit', 'Fitness', 'Fitness', 'Other', false),
('x', 'Paranormal', 'Paranormal', 'Other', false),
('adv', 'Advice', 'Advice', 'Other', false),
('lgbt', 'LGBT', 'LGBT', 'Other', false),
('mlp', 'Pony', 'Pony', 'Other', false),
('news', 'Current News', 'Current News', 'Other', false),
('wsr', 'Worksafe Requests', 'Worksafe Requests', 'Other', false),
('vip', 'Very Important Posts', 'Very Important Posts', 'Other', false),
('b', 'Random', 'Random', 'Misc. (NSFW)', true),
('r9k', 'ROBOT9001', 'ROBOT9001', 'Misc. (NSFW)', true),
('pol', 'Politically Incorrect', 'Politically Incorrect', 'Misc. (NSFW)', true),
('bant', 'International/Random', 'International/Random', 'Misc. (NSFW)', true),
('s4s', 'Shit 4chan Says', 'Shit 4chan Says', 'Misc. (NSFW)', true),
('s', 'Sexy Beautiful Women', 'Sexy Beautiful Women', 'Adult (NSFW)', true),
('hc', 'Hardcore', 'Hardcore', 'Adult (NSFW)', true),
('hm', 'Handsome Men', 'Handsome Men', 'Adult (NSFW)', true),
('h', 'Hentai', 'Hentai', 'Adult (NSFW)', true),
('e', 'Ecchi', 'Ecchi', 'Adult (NSFW)', true),
('y', 'Yuri', 'Yuri', 'Adult (NSFW)', true),
('d', 'Hentai/Alternative', 'Hentai/Alternative', 'Adult (NSFW)', true),
('u', 'Yaoi', 'Yaoi', 'Adult (NSFW)', true),
('t', 'Torrents', 'Torrents', 'Adult (NSFW)', true),
('hr', 'High Resolution', 'High Resolution', 'Adult (NSFW)', true),
('gif', 'Adult GIF', 'Adult GIF', 'Adult (NSFW)', true),
('aco', 'Adult Cartoons', 'Adult Cartoons', 'Adult (NSFW)', true),
('r', 'Adult Requests', 'Adult Requests', 'Adult (NSFW)', true);