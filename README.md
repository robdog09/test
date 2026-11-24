# Dugout - youth baseball team manager

A small PHP + MySQL web app for running a youth baseball team through a season: seasons, teams, rosters, schedule, game locations, per-game lineups and batting orders.

## Files

| File | What it is |
|---|---|
| `schema.sql` | MySQL schema (creates the `baseball_team` database) |
| `seed.sql` | Optional sample data (Fall 2026, two teams, 11 players, 6 games) |
| `config.php` | Database connection settings |
| `api.php` | JSON API; enforces what each role may do |
| `index.html` | Responsive single-page front end (no build step, no dependencies) |

## Run it

Requires PHP 8.1+ with `pdo_mysql`, and MySQL 8.0+ (or MariaDB 10.4+).

```bash
mysql -u root -p < schema.sql
mysql -u root -p < seed.sql        # optional sample data

# edit the DB_* fallbacks in config.php, or set environment variables:
export DB_HOST=127.0.0.1 DB_NAME=baseball_team DB_USER=root DB_PASS=secret

php -S localhost:8000
```

Open <http://localhost:8000>, pick a role, and go.

## Roles

There is no login yet. You choose a role on first visit and can switch from the top bar. The choice is sent to the API as an `X-Role` header, and `api.php` enforces it.

| Role | Can do |
|---|---|
| Admin | Everything, including managing locations |
| Coach | Seasons, teams, players, schedule, scores, updates, lineups |
| Parent | Read updates, schedule, roster (names and numbers) and lineups |
| Family | Same as Parent |

Player email addresses and parent names are only returned to Admin and Coach.

**Important:** because the role is just a header, anyone can pretend to be an admin. Keep this on a trusted network until real authentication is added.

## Upgrading an existing database

If your database was created before teams could span seasons (it has a `teams.season_id` column), take a backup and run the migration once instead of `schema.sql`:

```bash
mysqldump -u USER -p baseball_team > backup-before-001.sql
mysql -u USER -p baseball_team < migrations/001_team_seasons.sql
```

Teams that had the same name in different seasons are merged into one team. All players, games and lineups are kept.

If your `batting_order` table has no `inning` column (one batting order per game), run migration 002 the same way. Each game's existing order is copied into all 7 innings.

```bash
mysqldump -u USER -p baseball_team > backup-before-002.sql
mysql -u USER -p baseball_team < migrations/002_batting_per_inning.sql
```

## Data model

```
seasons 1--* team_seasons *--1 teams        (a team can play in many seasons;
                |                            a season has many teams)
                +--1--* players
                +--1--* games *--0..1 locations
                |         1--* lineup_positions (game, inning 1-7, position, player)
                |         1--* batting_order    (game, inning 1-7, slot, player)
                +--1--* announcements
```

- A **team** (e.g. Tigers) is a lasting identity. A **team_season** is that team's entry in one season, and owns its own roster, schedule, lineups and updates. Adding a team to a new season can copy its roster forward from the previous one.
- Removing a team from a season deletes that season's roster, games and lineups. The team itself is deleted only when it is in no season.

- **Every inning has a fielding position and a batting position.** `lineup_positions` holds the nine field positions (P, C, 1B, 2B, 3B, SS, LF, CF, RF) per inning, for up to 7 innings. `batting_order` holds an ordered list of batters per inning. The lineup page can copy an inning's order to the next inning or to every inning.
- **The bench is implicit.** A rostered player with no position row for an inning is on the bench that inning.
- The database enforces that a position has one player per inning, a player has one position per inning, and a player bats at most once per inning. The API also checks that every player belongs to the game's team.
- A season that still has teams can't be deleted.
