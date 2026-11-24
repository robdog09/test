-- Dugout: youth baseball team manager
-- MySQL 8.0+ / MariaDB 10.4+
--
--   mysql -u root -p < schema.sql
--
-- Safe to re-run: every table uses CREATE TABLE IF NOT EXISTS.
-- Upgrading an existing database from the old layout (teams.season_id)?
-- Do NOT use this file; run the files in migrations/ in order instead.

CREATE DATABASE IF NOT EXISTS baseball_team
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE baseball_team;

-- A season, e.g. "Fall 2026".
CREATE TABLE IF NOT EXISTS seasons (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  start_date  DATE NULL,
  end_date    DATE NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_seasons_name (name)
) ENGINE=InnoDB;

-- A team is a lasting identity (e.g. "Tigers") that can play in many seasons.
CREATE TABLE IF NOT EXISTS teams (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  age_group   VARCHAR(50) NULL,           -- e.g. "10U"
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_teams_name (name)
) ENGINE=InnoDB;

-- A team's entry in one season. One season has many teams; one team has many
-- seasons. Each entry has its own roster, schedule, lineups and updates.
-- A season that still has team entries cannot be deleted.
CREATE TABLE IF NOT EXISTS team_seasons (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id     INT UNSIGNED NOT NULL,
  season_id   INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_team_seasons (team_id, season_id),   -- a team joins a season once
  KEY ix_team_seasons_season (season_id),
  CONSTRAINT fk_team_seasons_team FOREIGN KEY (team_id)
    REFERENCES teams (id) ON DELETE CASCADE,
  CONSTRAINT fk_team_seasons_season FOREIGN KEY (season_id)
    REFERENCES seasons (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- A player is on one team's roster for one season. A child who plays again
-- next season gets a new row (the app can copy the roster forward).
CREATE TABLE IF NOT EXISTS players (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_season_id  INT UNSIGNED NOT NULL,
  first_name      VARCHAR(100) NOT NULL,
  last_name       VARCHAR(100) NOT NULL,
  email           VARCHAR(255) NULL,
  parent_names    VARCHAR(255) NULL,
  jersey_number   TINYINT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_players_ts_jersey (team_season_id, jersey_number),   -- NULLs may repeat
  KEY ix_players_ts (team_season_id),
  CONSTRAINT fk_players_team_season FOREIGN KEY (team_season_id)
    REFERENCES team_seasons (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Pre-configured places games are played. map_url is a Google Maps link.
CREATE TABLE IF NOT EXISTS locations (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name      VARCHAR(150) NOT NULL,
  address   VARCHAR(255) NULL,
  map_url   VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_locations_name (name)
) ENGINE=InnoDB;

-- A team's schedule for a season is simply its games.
CREATE TABLE IF NOT EXISTS games (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_season_id  INT UNSIGNED NOT NULL,
  location_id     INT UNSIGNED NULL,
  game_datetime   DATETIME NOT NULL,        -- local time at the field, no time zone conversion
  opponent        VARCHAR(100) NOT NULL,
  home_away       ENUM('home','away') NOT NULL DEFAULT 'home',
  youtube_url     VARCHAR(500) NULL,
  our_score       SMALLINT UNSIGNED NULL,
  opp_score       SMALLINT UNSIGNED NULL,
  notes           VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY ix_games_ts_time (team_season_id, game_datetime),
  CONSTRAINT fk_games_team_season FOREIGN KEY (team_season_id)
    REFERENCES team_seasons (id) ON DELETE CASCADE,
  CONSTRAINT fk_games_location FOREIGN KEY (location_id)
    REFERENCES locations (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- DEFENSIVE POSITIONS, per game per inning (1-7).
-- Only the nine field positions are stored. Any rostered player without a row
-- for an inning is on the bench for that inning.
--   PRIMARY KEY  -> a position holds at most one player per inning
--   UNIQUE       -> a player holds at most one position per inning
CREATE TABLE IF NOT EXISTS lineup_positions (
  game_id         INT UNSIGNED NOT NULL,
  inning          TINYINT UNSIGNED NOT NULL,
  field_position  ENUM('P','C','1B','2B','3B','SS','LF','CF','RF') NOT NULL,
  player_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (game_id, inning, field_position),
  UNIQUE KEY uq_lineup_player_inning (game_id, inning, player_id),
  CONSTRAINT ck_lineup_inning CHECK (inning BETWEEN 1 AND 7),
  CONSTRAINT fk_lineup_game FOREIGN KEY (game_id)
    REFERENCES games (id) ON DELETE CASCADE,
  CONSTRAINT fk_lineup_player FOREIGN KEY (player_id)
    REFERENCES players (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- BATTING ORDER, per game per inning (1-7), alongside the fielding positions.
-- slot 1 bats first in that inning. Youth leagues usually bat the whole roster.
--   PRIMARY KEY  -> a slot holds at most one player per inning
--   UNIQUE       -> a player bats at most once per inning
CREATE TABLE IF NOT EXISTS batting_order (
  game_id    INT UNSIGNED NOT NULL,
  inning     TINYINT UNSIGNED NOT NULL,
  slot       TINYINT UNSIGNED NOT NULL,
  player_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (game_id, inning, slot),
  UNIQUE KEY uq_batting_player_inning (game_id, inning, player_id),
  CONSTRAINT ck_batting_inning CHECK (inning BETWEEN 1 AND 7),
  CONSTRAINT fk_batting_game FOREIGN KEY (game_id)
    REFERENCES games (id) ON DELETE CASCADE,
  CONSTRAINT fk_batting_player FOREIGN KEY (player_id)
    REFERENCES players (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Team updates that parents and family can read, per team per season.
CREATE TABLE IF NOT EXISTS announcements (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_season_id  INT UNSIGNED NOT NULL,
  title           VARCHAR(150) NOT NULL,
  body            TEXT NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_announcements_ts (team_season_id, created_at),
  CONSTRAINT fk_announcements_team_season FOREIGN KEY (team_season_id)
    REFERENCES team_seasons (id) ON DELETE CASCADE
) ENGINE=InnoDB;
