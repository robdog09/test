-- Optional sample data so the app isn't empty on first run.
--   mysql -u root -p < seed.sql
-- All names, addresses and links below are made up; replace them with real ones.

USE baseball_team;

INSERT INTO seasons (id, name, start_date, end_date) VALUES
  (1, 'Fall 2026',   '2026-09-12', '2026-12-12'),
  (2, 'Spring 2027', '2027-03-06', '2027-05-29');

INSERT INTO teams (id, name, age_group) VALUES
  (1, 'Tigers', '10U'),
  (2, 'Hawks',  '10U');

-- Who plays in which season. The Tigers play in both seasons.
INSERT INTO team_seasons (id, team_id, season_id) VALUES
  (1, 1, 1),   -- Tigers, Fall 2026
  (2, 2, 1),   -- Hawks,  Fall 2026
  (3, 1, 2);   -- Tigers, Spring 2027 (empty roster: copy it forward from the app)

INSERT INTO locations (id, name, address, map_url) VALUES
  (1, 'Sample Park - Field 1', '123 Main St, Anytown, TX',
      'https://www.google.com/maps/search/?api=1&query=baseball+field+Anytown+TX'),
  (2, 'Sample Park - Field 2', '123 Main St, Anytown, TX',
      'https://www.google.com/maps/search/?api=1&query=baseball+field+Anytown+TX');

-- Eleven players: nine take the field each inning, two sit on the bench.
INSERT INTO players (id, team_season_id, first_name, last_name, email, parent_names, jersey_number) VALUES
  ( 1, 1, 'Liam',     'Johnson',   'johnson.family@example.com',   'Sarah & Mike Johnson',  7),
  ( 2, 1, 'Noah',     'Garcia',    'garcia.family@example.com',    'Maria Garcia',         12),
  ( 3, 1, 'Mason',    'Smith',     'smith.family@example.com',     'Dana & Chris Smith',    3),
  ( 4, 1, 'Ethan',    'Williams',  'williams.family@example.com',  'Tom Williams',         21),
  ( 5, 1, 'Lucas',    'Brown',     'brown.family@example.com',     'Kim & Jay Brown',       9),
  ( 6, 1, 'Oliver',   'Davis',     'davis.family@example.com',     'Priya Davis',          15),
  ( 7, 1, 'Elijah',   'Martinez',  'martinez.family@example.com',  'Ana & Luis Martinez',   5),
  ( 8, 1, 'James',    'Wilson',    'wilson.family@example.com',    'Beth Wilson',          18),
  ( 9, 1, 'Benjamin', 'Lee',       'lee.family@example.com',       'Grace & David Lee',     1),
  (10, 1, 'Henry',    'Taylor',    'taylor.family@example.com',    'Rob Taylor',           24),
  (11, 1, 'Jack',     'Anderson',  'anderson.family@example.com',  'Erin Anderson',        30);

INSERT INTO games (id, team_season_id, location_id, game_datetime, opponent, home_away, youtube_url, our_score, opp_score, notes) VALUES
  (1, 1, 1, '2026-11-07 10:00:00', 'Rockets',   'home', NULL, 8, 5, NULL),
  (2, 1, 2, '2026-11-14 11:30:00', 'Eagles',    'away', NULL, 3, 6, NULL),
  (3, 1, 1, '2026-11-21 09:00:00', 'Bears',     'home', NULL, 10, 4, NULL),
  (4, 1, 1, '2026-11-28 10:00:00', 'Cardinals', 'home', 'https://www.youtube.com/@yourteam/live', NULL, NULL, 'Arrive 45 minutes early for warm-ups.'),
  (5, 1, 2, '2026-12-05 12:00:00', 'Rockets',   'away', NULL, NULL, NULL, NULL),
  (6, 1, 1, '2026-12-12 09:00:00', 'Eagles',    'home', NULL, NULL, NULL, 'Last game of the season - team party after.');

INSERT INTO announcements (team_season_id, title, body) VALUES
  (1, 'Welcome to the season!',
      'Games are on Saturdays. Please check the schedule each week for time and field changes.');
