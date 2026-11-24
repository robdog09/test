-- Migration 001: teams <-> seasons becomes one-to-many from BOTH sides.
--
-- BEFORE: teams.season_id  (a team belonged to exactly one season)
-- AFTER:  team_seasons(team_id, season_id)  (a team can play in many seasons,
--         a season has many teams); players, games and announcements now point
--         at team_seasons.id instead of teams.id.
--
-- Run this ONCE against an existing database created from the old schema.sql:
--
--   mysqldump -u USER -p baseball_team > backup-before-001.sql     # take a backup first!
--   mysql -u USER -p baseball_team < migrations/001_team_seasons.sql
--
-- Fresh installs should use schema.sql and skip this file.
--
-- NOTE: MySQL commits DDL (CREATE/ALTER) immediately and cannot roll it back,
-- so a failure part-way leaves the database half-migrated. Restore the backup
-- and fix the cause before re-running.
--
-- Data mapping: every old team becomes one team_seasons row with the SAME id,
-- so player/game/announcement foreign key values carry over unchanged. Teams
-- that shared a name across different seasons (e.g. "Tigers" in Fall and
-- Spring) are then merged into one team, since team names are now unique.

-- Re-running is harmless: the next statement fails with "table already exists"
-- and the mysql client stops there (do not use --force).
CREATE TABLE team_seasons (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id     INT UNSIGNED NOT NULL,
  season_id   INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_team_seasons (team_id, season_id),
  KEY ix_team_seasons_season (season_id)
) ENGINE=InnoDB;

-- 1) One entry per old team, keeping the id.
INSERT INTO team_seasons (id, team_id, season_id)
SELECT id, id, season_id FROM teams;

-- 2) Merge same-named teams (from different seasons) into the lowest-id team.
UPDATE team_seasons ts
JOIN teams t ON t.id = ts.team_id
JOIN (SELECT name, MIN(id) AS keep_id FROM teams GROUP BY name) k ON k.name = t.name
SET ts.team_id = k.keep_id;

DELETE t FROM teams t
LEFT JOIN team_seasons ts ON ts.team_id = t.id
WHERE ts.id IS NULL;

-- 3) Detach teams from a single season.
ALTER TABLE teams DROP FOREIGN KEY fk_teams_season;
ALTER TABLE teams DROP INDEX uq_teams_season_name;
ALTER TABLE teams DROP COLUMN season_id;
ALTER TABLE teams ADD UNIQUE KEY uq_teams_name (name);

ALTER TABLE team_seasons
  ADD CONSTRAINT fk_team_seasons_team   FOREIGN KEY (team_id)   REFERENCES teams (id)   ON DELETE CASCADE,
  ADD CONSTRAINT fk_team_seasons_season FOREIGN KEY (season_id) REFERENCES seasons (id) ON DELETE RESTRICT;

-- 4) Re-point players, games and announcements at team_seasons.
ALTER TABLE players DROP FOREIGN KEY fk_players_team;
ALTER TABLE players DROP INDEX uq_players_team_jersey, DROP INDEX ix_players_team;
ALTER TABLE players
  CHANGE team_id team_season_id INT UNSIGNED NOT NULL,
  ADD UNIQUE KEY uq_players_ts_jersey (team_season_id, jersey_number),
  ADD KEY ix_players_ts (team_season_id),
  ADD CONSTRAINT fk_players_team_season FOREIGN KEY (team_season_id) REFERENCES team_seasons (id) ON DELETE CASCADE;

ALTER TABLE games DROP FOREIGN KEY fk_games_team;
ALTER TABLE games DROP INDEX ix_games_team_time;
ALTER TABLE games
  CHANGE team_id team_season_id INT UNSIGNED NOT NULL,
  ADD KEY ix_games_ts_time (team_season_id, game_datetime),
  ADD CONSTRAINT fk_games_team_season FOREIGN KEY (team_season_id) REFERENCES team_seasons (id) ON DELETE CASCADE;

ALTER TABLE announcements DROP FOREIGN KEY fk_announcements_team;
ALTER TABLE announcements DROP INDEX ix_announcements_team;
ALTER TABLE announcements
  CHANGE team_id team_season_id INT UNSIGNED NOT NULL,
  ADD KEY ix_announcements_ts (team_season_id, created_at),
  ADD CONSTRAINT fk_announcements_team_season FOREIGN KEY (team_season_id) REFERENCES team_seasons (id) ON DELETE CASCADE;
