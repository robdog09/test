<?php
declare(strict_types=1);

/**
 * JSON API for Dugout.
 *
 *   GET    api.php?r=<resource>[&id=N][&team_season_id=N|season_id=N]
 *   (r=teams rows are a team's entry in a season: id = team_seasons.id)
 *   POST   api.php?r=<resource>             (JSON body)
 *   PUT    api.php?r=<resource>&id=N        (JSON body)
 *   DELETE api.php?r=<resource>&id=N
 *   GET    api.php?r=lineup&game_id=N
 *   PUT    api.php?r=lineup&game_id=N       {positions:[{inning, position, player_id}],
 *                                            batting:[{inning, order:[player_id, ...]}]}
 *
 * There is no login yet. The browser sends the chosen role in the X-Role
 * header and this file enforces what each role may do. That is a convenience,
 * NOT security: anyone can send any header. Do not expose this to the public
 * internet until real authentication is added.
 */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const ROLES       = ['admin', 'coach', 'parent', 'family'];
const STAFF       = ['admin', 'coach'];          // may edit teams, players, schedule, lineups
const POSITIONS   = ['P', 'C', '1B', '2B', '3B', 'SS', 'LF', 'CF', 'RF'];
const MAX_INNINGS = 7;

// ---------------------------------------------------------------- helpers

function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function fail(string $message, int $status = 400): never
{
    respond(['error' => $message], $status);
}

function require_role(array $allowed): void
{
    global $role;
    if (!in_array($role, $allowed, true)) {
        fail("The {$role} role is not allowed to do that.", 403);
    }
}

function read_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail('Request body must be valid JSON.');
    }
    return $data;
}

function field_label(string $name): string
{
    $name = preg_replace('/_id$/', '', $name);
    return ucfirst(str_replace('_', ' ', $name));
}

function parse_datetime(string $v): ?string
{
    foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $v);
        if ($d && $d->format($fmt) === $v) {
            return $d->format('Y-m-d H:i:s');
        }
    }
    return null;
}

/**
 * Validate and normalise input against a field spec.
 * Spec entry: name => [type, required, extra]
 *   str/text: extra = max length   int: extra = max value   enum: extra = allowed values
 */
function clean_fields(array $spec, array $in): array
{
    $out = [];
    foreach ($spec as $name => [$type, $required, $extra]) {
        $label = field_label($name);
        $v = $in[$name] ?? null;
        if (is_string($v)) {
            $v = trim($v);
        }
        if ($v === null || $v === '') {
            if ($required) {
                fail("{$label} is required.");
            }
            $out[$name] = null;
            continue;
        }
        if (!is_scalar($v)) {
            fail("{$label} is not valid.");
        }

        switch ($type) {
            case 'str':
            case 'text':
                $v = (string)$v;
                if (mb_strlen($v) > $extra) {
                    fail("{$label} must be at most {$extra} characters.");
                }
                $out[$name] = $v;
                break;

            case 'int':
                $n = filter_var($v, FILTER_VALIDATE_INT);
                $min = str_ends_with($name, '_id') ? 1 : 0;
                if ($n === false || $n < $min || ($extra !== null && $n > $extra)) {
                    fail("{$label} is not valid.");
                }
                $out[$name] = $n;
                break;

            case 'date':
                $d = DateTime::createFromFormat('!Y-m-d', (string)$v);
                if (!$d || $d->format('Y-m-d') !== (string)$v) {
                    fail("{$label} must be a date (YYYY-MM-DD).");
                }
                $out[$name] = (string)$v;
                break;

            case 'datetime':
                $dt = parse_datetime((string)$v);
                if ($dt === null) {
                    fail("{$label} must be a date and time.");
                }
                $out[$name] = $dt;
                break;

            case 'url':
                $v = (string)$v;
                $scheme = strtolower((string)parse_url($v, PHP_URL_SCHEME));
                if (!filter_var($v, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                    fail("{$label} must be a full http(s) link.");
                }
                if (mb_strlen($v) > $extra) {
                    fail("{$label} is too long.");
                }
                $out[$name] = $v;
                break;

            case 'email':
                $v = (string)$v;
                if (!filter_var($v, FILTER_VALIDATE_EMAIL) || mb_strlen($v) > $extra) {
                    fail("{$label} must be a valid email address.");
                }
                $out[$name] = $v;
                break;

            case 'enum':
                if (!in_array($v, $extra, true)) {
                    fail("{$label} must be one of: " . implode(', ', $extra) . '.');
                }
                $out[$name] = $v;
                break;

            default:
                fail('Server error: unknown field type.', 500);
        }
    }
    return $out;
}

// -------------------------------------------------------- resource config

$resources = [
    'seasons' => [
        'table'  => 'seasons',
        'alias'  => 's',
        'select' => 'SELECT s.* FROM seasons s',
        'order'  => 's.start_date DESC, s.id DESC',
        'filters' => [],
        'write'  => STAFF,
        'fields' => [
            'name'       => ['str', true, 100],
            'start_date' => ['date', false, null],
            'end_date'   => ['date', false, null],
        ],
    ],
    // Read-only list of every team (any season), used to enrol a team in a season.
    // Writing teams goes through handle_teams() below.
    'all_teams' => [
        'table'  => 'teams',
        'alias'  => 't',
        'select' => 'SELECT t.id, t.name, t.age_group FROM teams t',
        'order'  => 't.name',
        'filters' => [],
        'write'  => [],
        'fields' => [],
    ],
    'players' => [
        'table'  => 'players',
        'alias'  => 'p',
        'select' => 'SELECT p.* FROM players p',
        'order'  => 'p.last_name, p.first_name',
        'filters' => ['team_season_id' => 'p.team_season_id'],
        'write'  => STAFF,
        // Contact details are only returned to admins and coaches.
        'staff_only' => ['email', 'parent_names'],
        'fields' => [
            'team_season_id' => ['int', true, null],
            'first_name'    => ['str', true, 100],
            'last_name'     => ['str', true, 100],
            'email'         => ['email', false, 255],
            'parent_names'  => ['str', false, 255],
            'jersey_number' => ['int', false, 99],
        ],
    ],
    'locations' => [
        'table'  => 'locations',
        'alias'  => 'l',
        'select' => 'SELECT l.* FROM locations l',
        'order'  => 'l.name',
        'filters' => [],
        'write'  => ['admin'],
        'fields' => [
            'name'    => ['str', true, 150],
            'address' => ['str', false, 255],
            'map_url' => ['url', false, 500],
        ],
    ],
    'games' => [
        'table'  => 'games',
        'alias'  => 'g',
        'select' => 'SELECT g.*, l.name AS location_name, l.address AS location_address, l.map_url AS location_map_url
                     FROM games g LEFT JOIN locations l ON l.id = g.location_id',
        'order'  => 'g.game_datetime, g.id',
        'filters' => ['team_season_id' => 'g.team_season_id'],
        'write'  => STAFF,
        'fields' => [
            'team_season_id' => ['int', true, null],
            'location_id'   => ['int', false, null],
            'game_datetime' => ['datetime', true, null],
            'opponent'      => ['str', true, 100],
            'home_away'     => ['enum', true, ['home', 'away']],
            'youtube_url'   => ['url', false, 500],
            'our_score'     => ['int', false, 999],
            'opp_score'     => ['int', false, 999],
            'notes'         => ['str', false, 500],
        ],
    ],
    'announcements' => [
        'table'  => 'announcements',
        'alias'  => 'a',
        'select' => 'SELECT a.* FROM announcements a',
        'order'  => 'a.created_at DESC, a.id DESC',
        'filters' => ['team_season_id' => 'a.team_season_id'],
        'write'  => STAFF,
        'fields' => [
            'team_season_id' => ['int', true, null],
            'title'          => ['str', true, 150],
            'body'           => ['text', true, 5000],
        ],
    ],
];

function present(array $cfg, array $row): array
{
    global $role;
    if (!in_array($role, STAFF, true)) {
        foreach ($cfg['staff_only'] ?? [] as $col) {
            unset($row[$col]);
        }
    }
    return $row;
}

function fetch_one(array $cfg, int $id): ?array
{
    $st = db()->prepare($cfg['select'] . ' WHERE ' . $cfg['alias'] . '.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function handle_resource(array $cfg, string $method, ?int $id): never
{
    $pdo = db();

    if ($method === 'GET') {
        if ($id !== null) {
            $row = fetch_one($cfg, $id);
            if (!$row) {
                fail('Not found.', 404);
            }
            respond(present($cfg, $row));
        }
        $conds = [];
        $args  = [];
        foreach ($cfg['filters'] as $param => $column) {
            if (isset($_GET[$param]) && $_GET[$param] !== '') {
                $conds[] = "{$column} = ?";
                $args[]  = (int)$_GET[$param];
            }
        }
        $sql = $cfg['select']
            . ($conds ? ' WHERE ' . implode(' AND ', $conds) : '')
            . ' ORDER BY ' . $cfg['order'];
        $st = $pdo->prepare($sql);
        $st->execute($args);
        respond(array_map(fn($r) => present($cfg, $r), $st->fetchAll()));
    }

    require_role($cfg['write']);
    $table = $cfg['table'];

    if ($method === 'POST') {
        $data = clean_fields($cfg['fields'], read_body());
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $marks = implode(', ', array_fill(0, count($data), '?'));
        $pdo->prepare("INSERT INTO `{$table}` ({$cols}) VALUES ({$marks})")->execute(array_values($data));
        respond(present($cfg, fetch_one($cfg, (int)$pdo->lastInsertId())), 201);
    }

    if ($id === null) {
        fail('An id is required.');
    }

    if ($method === 'PUT') {
        if (!fetch_one($cfg, $id)) {
            fail('Not found.', 404);
        }
        $data = clean_fields($cfg['fields'], read_body());
        $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($data)));
        $pdo->prepare("UPDATE `{$table}` SET {$set} WHERE id = ?")->execute([...array_values($data), $id]);
        respond(present($cfg, fetch_one($cfg, $id)));
    }

    if ($method === 'DELETE') {
        $st = $pdo->prepare("DELETE FROM `{$table}` WHERE id = ?");
        $st->execute([$id]);
        if ($st->rowCount() === 0) {
            fail('Not found.', 404);
        }
        respond(['ok' => true]);
    }

    fail('Method not allowed.', 405);
}

// ------------------------------------------------------------------ teams

/**
 * "Teams" as the app sees them are a team's entry in one season (team_seasons),
 * so `id` here is team_seasons.id. That is the id players, games, lineups and
 * updates hang off. `team_id` is the lasting team behind it.
 */
const TEAM_SEASON_SQL =
    'SELECT ts.id, ts.team_id, ts.season_id, t.name, t.age_group, s.name AS season_name,
            (SELECT COUNT(*) FROM players p WHERE p.team_season_id = ts.id) AS player_count,
            (SELECT COUNT(*) FROM team_seasons x WHERE x.team_id = ts.team_id) AS season_count
     FROM team_seasons ts
     JOIN teams t ON t.id = ts.team_id
     JOIN seasons s ON s.id = ts.season_id';

function team_season_row(int $id): ?array
{
    $st = db()->prepare(TEAM_SEASON_SQL . ' WHERE ts.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function handle_teams(string $method, ?int $id): never
{
    if ($method !== 'GET') {
        require_role(STAFF);
    }
    $pdo = db();

    if ($method === 'GET') {
        if ($id !== null) {
            $row = team_season_row($id);
            $row ? respond($row) : fail('Not found.', 404);
        }
        $sql = TEAM_SEASON_SQL;
        $args = [];
        if (isset($_GET['season_id']) && $_GET['season_id'] !== '') {
            $sql .= ' WHERE ts.season_id = ?';
            $args[] = (int)$_GET['season_id'];
        }
        $st = $pdo->prepare($sql . ' ORDER BY t.name');
        $st->execute($args);
        respond($st->fetchAll());
    }

    if ($method === 'POST') {
        // Enrol a team in a season: an existing team (team_id) or a brand-new one (name).
        $in = read_body();
        $seasonId = clean_fields(['season_id' => ['int', true, null]], $in)['season_id'];
        $st = $pdo->prepare('SELECT id FROM seasons WHERE id = ?');
        $st->execute([$seasonId]);
        if (!$st->fetch()) {
            fail('Season not found.', 404);
        }

        $pdo->beginTransaction();
        try {
            $existing = $in['team_id'] ?? null;
            if ($existing !== null && $existing !== '') {
                $teamId = filter_var($existing, FILTER_VALIDATE_INT);
                $st = $pdo->prepare('SELECT id FROM teams WHERE id = ?');
                $st->execute([$teamId === false ? 0 : $teamId]);
                if ($teamId === false || !$st->fetch()) {
                    fail('Team not found.', 404);
                }
            } else {
                $d = clean_fields(['name' => ['str', true, 100], 'age_group' => ['str', false, 50]], $in);
                $st = $pdo->prepare('SELECT id FROM teams WHERE name = ?');
                $st->execute([$d['name']]);
                if ($st->fetch()) {
                    fail("A team named \"{$d['name']}\" already exists. Choose it from the list to add it to this season.", 409);
                }
                $pdo->prepare('INSERT INTO teams (name, age_group) VALUES (?, ?)')->execute([$d['name'], $d['age_group']]);
                $teamId = (int)$pdo->lastInsertId();
            }

            $pdo->prepare('INSERT INTO team_seasons (team_id, season_id) VALUES (?, ?)')->execute([$teamId, $seasonId]);
            $newId = (int)$pdo->lastInsertId();

            // Optionally carry the roster over from this team's most recent other season.
            if (filter_var($in['copy_roster'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $st = $pdo->prepare(
                    'SELECT ts.id FROM team_seasons ts JOIN seasons s ON s.id = ts.season_id
                     WHERE ts.team_id = ? AND ts.id <> ?
                     ORDER BY s.start_date DESC, ts.id DESC LIMIT 1'
                );
                $st->execute([$teamId, $newId]);
                if ($source = $st->fetchColumn()) {
                    $pdo->prepare(
                        'INSERT INTO players (team_season_id, first_name, last_name, email, parent_names, jersey_number)
                         SELECT ?, first_name, last_name, email, parent_names, jersey_number
                         FROM players WHERE team_season_id = ?'
                    )->execute([$newId, $source]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        respond(team_season_row($newId), 201);
    }

    if ($id === null) {
        fail('An id is required.');
    }
    $row = team_season_row($id);
    if (!$row) {
        fail('Not found.', 404);
    }

    if ($method === 'PUT') {
        // Renames the team itself, so it changes in every season the team plays.
        $d = clean_fields(['name' => ['str', true, 100], 'age_group' => ['str', false, 50]], read_body());
        $pdo->prepare('UPDATE teams SET name = ?, age_group = ? WHERE id = ?')
            ->execute([$d['name'], $d['age_group'], $row['team_id']]);
        respond(team_season_row($id));
    }

    if ($method === 'DELETE') {
        // Removes the team from this season (its roster, games and lineups go with it).
        // The team itself is deleted only once it is in no season at all.
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM team_seasons WHERE id = ?')->execute([$id]);
            $st = $pdo->prepare('SELECT COUNT(*) FROM team_seasons WHERE team_id = ?');
            $st->execute([$row['team_id']]);
            if ((int)$st->fetchColumn() === 0) {
                $pdo->prepare('DELETE FROM teams WHERE id = ?')->execute([$row['team_id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        respond(['ok' => true]);
    }

    fail('Method not allowed.', 405);
}

// ----------------------------------------------------------------- lineup

function handle_lineup(string $method): never
{
    $gameId = (int)($_GET['game_id'] ?? 0);
    if ($gameId < 1) {
        fail('game_id is required.');
    }
    $pdo = db();

    $st = $pdo->prepare(
        'SELECT g.*, l.name AS location_name, l.address AS location_address, l.map_url AS location_map_url
         FROM games g LEFT JOIN locations l ON l.id = g.location_id WHERE g.id = ?'
    );
    $st->execute([$gameId]);
    $game = $st->fetch();
    if (!$game) {
        fail('Game not found.', 404);
    }

    $st = $pdo->prepare('SELECT * FROM players WHERE team_season_id = ? ORDER BY last_name, first_name');
    $st->execute([$game['team_season_id']]);
    $players = $st->fetchAll();
    $playerIds = array_flip(array_map(fn($p) => (int)$p['id'], $players));

    if ($method === 'GET') {
        $cfg = ['staff_only' => ['email', 'parent_names']];
        $st = $pdo->prepare(
            'SELECT inning, field_position, player_id FROM lineup_positions
             WHERE game_id = ? ORDER BY inning, field_position'
        );
        $st->execute([$gameId]);
        $positions = array_map(fn($r) => [
            'inning'    => (int)$r['inning'],
            'position'  => $r['field_position'],
            'player_id' => (int)$r['player_id'],
        ], $st->fetchAll());

        // One batting order per inning: [{inning: 1, order: [player_id, ...]}, ...]
        $st = $pdo->prepare('SELECT inning, player_id FROM batting_order WHERE game_id = ? ORDER BY inning, slot');
        $st->execute([$gameId]);
        $byInning = [];
        foreach ($st->fetchAll() as $r) {
            $byInning[(int)$r['inning']][] = (int)$r['player_id'];
        }
        $batting = [];
        foreach ($byInning as $inning => $order) {
            $batting[] = ['inning' => $inning, 'order' => $order];
        }

        respond([
            'game'      => $game,
            'players'   => array_map(fn($p) => present($cfg, $p), $players),
            'positions' => $positions,
            'batting'   => $batting,
        ]);
    }

    if ($method !== 'PUT') {
        fail('Method not allowed.', 405);
    }
    require_role(STAFF);

    $body = read_body();
    $positions = $body['positions'] ?? [];
    $batting   = $body['batting'] ?? [];
    if (!is_array($positions) || !is_array($batting)) {
        fail('positions and batting must be lists.');
    }

    $rows = [];
    $slotUsed = [];
    $playerUsed = [];
    foreach ($positions as $p) {
        if (!is_array($p)) {
            fail('Each position entry must be an object.');
        }
        $inning = filter_var($p['inning'] ?? null, FILTER_VALIDATE_INT);
        $pos    = $p['position'] ?? null;
        $pid    = filter_var($p['player_id'] ?? null, FILTER_VALIDATE_INT);

        if ($inning === false || $inning < 1 || $inning > MAX_INNINGS) {
            fail('Inning must be between 1 and ' . MAX_INNINGS . '.');
        }
        if (!is_string($pos) || !in_array($pos, POSITIONS, true)) {
            fail('Unknown field position.');
        }
        if ($pid === false || !isset($playerIds[$pid])) {
            fail('Every selected player must be on this team.');
        }
        if (isset($slotUsed[$inning][$pos])) {
            fail("Inning {$inning}: {$pos} is assigned more than once.");
        }
        if (isset($playerUsed[$inning][$pid])) {
            fail("Inning {$inning}: a player is assigned to more than one position.");
        }
        $slotUsed[$inning][$pos] = true;
        $playerUsed[$inning][$pid] = true;
        $rows[] = [$gameId, $inning, $pos, $pid];
    }

    $batRows = [];
    $inningSeen = [];
    foreach ($batting as $b) {
        if (!is_array($b) || !array_key_exists('inning', $b)) {
            fail('Each batting entry must be an object with an inning and an order.');
        }
        $inning = filter_var($b['inning'], FILTER_VALIDATE_INT);
        if ($inning === false || $inning < 1 || $inning > MAX_INNINGS) {
            fail('Inning must be between 1 and ' . MAX_INNINGS . '.');
        }
        if (isset($inningSeen[$inning])) {
            fail("Inning {$inning} has more than one batting order.");
        }
        $inningSeen[$inning] = true;

        $list = $b['order'] ?? [];
        if (!is_array($list)) {
            fail("Inning {$inning}: the batting order must be a list.");
        }
        $seen = [];
        foreach (array_values($list) as $i => $raw) {
            $pid = filter_var($raw, FILTER_VALIDATE_INT);
            if ($pid === false || !isset($playerIds[$pid])) {
                fail("Inning {$inning}: every player in the batting order must be on this team.");
            }
            if (isset($seen[$pid])) {
                fail("Inning {$inning}: a player appears more than once in the batting order.");
            }
            $seen[$pid] = true;
            $batRows[] = [$gameId, $inning, $i + 1, $pid];
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM lineup_positions WHERE game_id = ?')->execute([$gameId]);
        $pdo->prepare('DELETE FROM batting_order WHERE game_id = ?')->execute([$gameId]);

        $ins = $pdo->prepare(
            'INSERT INTO lineup_positions (game_id, inning, field_position, player_id) VALUES (?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $ins->execute($r);
        }
        $ins = $pdo->prepare('INSERT INTO batting_order (game_id, inning, slot, player_id) VALUES (?, ?, ?, ?)');
        foreach ($batRows as $r) {
            $ins->execute($r);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    respond(['ok' => true, 'positions' => count($rows), 'batters' => count($batRows)]);
}

// --------------------------------------------------------------- dispatch

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $role   = $_SERVER['HTTP_X_ROLE'] ?? '';
    if (!in_array($role, ROLES, true)) {
        fail('Choose a role first.', 401);
    }

    $resource = (string)($_GET['r'] ?? '');
    $id = (isset($_GET['id']) && $_GET['id'] !== '') ? (int)$_GET['id'] : null;

    if ($resource === 'lineup') {
        handle_lineup($method);
    }
    if ($resource === 'teams') {
        handle_teams($method, $id);
    }
    if (!isset($resources[$resource])) {
        fail('Unknown resource.', 404);
    }
    handle_resource($resources[$resource], $method, $id);
} catch (PDOException $e) {
    $code = (int)($e->errorInfo[1] ?? 0);
    if ($code === 1451) {
        fail('That record is still in use (for example, a season that still has teams). Remove the related records first.', 409);
    }
    if ($code === 1452) {
        fail('A linked record (season, team, location or player) no longer exists.', 409);
    }
    if ($code === 1062) {
        fail('That would create a duplicate (for example, the team is already in this season, or the name or jersey number is already in use).', 409);
    }
    error_log('Dugout DB error: ' . $e->getMessage());
    fail('Database error. Check the server log.', 500);
} catch (RuntimeException $e) {
    fail($e->getMessage(), 500);
}
