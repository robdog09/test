-- Migration 002: the batting order is set per inning, like fielding positions.
--
-- BEFORE: batting_order(game_id, slot, player_id)          one order per game
-- AFTER:  batting_order(game_id, inning, slot, player_id)  one order per inning
--
-- Run this ONCE, after 001, against an existing database:
--
--   mysqldump -u USER -p baseball_team > backup-before-002.sql     # take a backup first!
--   mysql -u USER -p baseball_team < migrations/002_batting_per_inning.sql
--
-- Fresh installs should use schema.sql and skip this file.
--
-- NOTE: MySQL commits DDL immediately and cannot roll it back. If this fails
-- part-way, restore the backup and fix the cause before re-running.
--
-- Data mapping: each game's existing batting order is copied into all 7
-- innings, so every inning starts with the order the game had before.

-- Re-running is harmless: this fails with "duplicate column name 'inning'"
-- and the mysql client stops there (do not use --force).
ALTER TABLE batting_order
  ADD COLUMN inning TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER game_id,
  DROP PRIMARY KEY,
  DROP INDEX uq_batting_player,
  ADD PRIMARY KEY (game_id, inning, slot),
  ADD UNIQUE KEY uq_batting_player_inning (game_id, inning, player_id),
  ADD CONSTRAINT ck_batting_inning CHECK (inning BETWEEN 1 AND 7);

ALTER TABLE batting_order ALTER COLUMN inning DROP DEFAULT;

-- Existing rows are now inning 1; copy them into innings 2-7.
INSERT INTO batting_order (game_id, inning, slot, player_id)
SELECT b.game_id, n.inning, b.slot, b.player_id
FROM batting_order b
JOIN (SELECT 2 AS inning UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7) n
WHERE b.inning = 1;
