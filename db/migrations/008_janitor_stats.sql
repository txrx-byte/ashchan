-- Migration: janitor_stats table
-- Tracks janitor ban request acceptance/denial history for accountability

CREATE TABLE IF NOT EXISTS janitor_stats (
    id               SERIAL PRIMARY KEY,
    janitor_username VARCHAR(64)  NOT NULL,
    action           SMALLINT     NOT NULL DEFAULT 0,  -- 0=denied, 1=accepted
    board            VARCHAR(16)  NOT NULL DEFAULT '',
    post_id          BIGINT       NOT NULL DEFAULT 0,
    requested_template INT        NOT NULL DEFAULT 0,
    accepted_template  INT        NOT NULL DEFAULT 0,
    mod_username     VARCHAR(64)  NOT NULL DEFAULT '',
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_janitor_stats_janitor  ON janitor_stats (janitor_username);
CREATE INDEX idx_janitor_stats_created  ON janitor_stats (created_at);
CREATE INDEX idx_janitor_stats_mod      ON janitor_stats (mod_username);
