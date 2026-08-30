<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Tournament scoping, over real HTTP
 * =====================================================================
 *
 *     php tests/staff_http_test.php
 *
 *  staff_test.php proves the rules in the services. This proves the
 *  wiring, which is where scoping actually fails. Two questions the
 *  service layer cannot answer:
 *
 *    Can a scorer POST a ball into another tournament's innings by
 *    editing innings_id in the form? The page guard in score.php only
 *    hides the pad; the API is what writes.
 *
 *    Can a tournament administrator reach another tournament's screens
 *    by editing ?tournament=N in the address bar?
 *
 *  Both are one edited request away, and neither shows up in a test that
 *  calls a method directly.
 *
 *  Destructive to the `cric_auction` database, like the other suites.
 */

require_once dirname(__DIR__) . '/config/config.php';

use App\Services\AccountService;
use App\Services\TournamentService;

const TEST_PASSWORD = 'Testing2026';

$passed = 0;
$failed = 0;

function ok(string $label): void
{
    global $passed;
    $passed++;
    echo "    \033[32m✓\033[0m {$label}\n";
}

function bad(string $label, string $detail = ''): void
{
    global $failed;
    $failed++;
    echo "    \033[31m✗\033[0m {$label}\n" . ($detail !== '' ? "        {$detail}\n" : '');
}

function is(string $label, mixed $actual, mixed $expected): void
{
    $a = var_export($actual, true);
    $b = var_export($expected, true);

    $a === $b ? ok($label) : bad($label, "expected {$b}, got {$a}");
}

/** @return array{status:int,location:?string,body:string} */
function http(string $url, string $jar, ?array $post = null, bool $follow = true): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 10,
    ]);

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $raw = (string) curl_exec($ch);

    if ($raw === '' && curl_errno($ch) !== 0) {
        fwrite(STDERR, "\nHTTP error: " . curl_error($ch) . "\n");
        exit(1);
    }

    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body       = substr($raw, $headerSize);

    curl_close($ch);

    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $headerSize), $m);

    return ['status' => $status, 'location' => isset($m[1]) ? trim($m[1]) : null, 'body' => $body];
}

function csrf(string $html): string
{
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

/** Sign in and return the cookie jar. */
function signIn(string $username): string
{
    global $base;

    $jar   = tempnam(sys_get_temp_dir(), 'jar');
    $login = http("{$base}/login.php", $jar);

    http("{$base}/login.php", $jar, [
        'csrf_token' => csrf($login['body']),
        'identifier' => $username,
        'password'   => TEST_PASSWORD,
    ]);

    return $jar;
}

function section(string $name): void
{
    echo "\n  \033[1m{$name}\033[0m\n";
}

// ---------------------------------------------------------------------
//  Two tournaments, each with its own staff and a live innings
// ---------------------------------------------------------------------

/** @return array<string,mixed> */
function seed(): array
{
    $pdo = Database::pdo();

    foreach (['schema.sql', 'reset.sql'] as $file) {
        $pdo->exec((string) file_get_contents(BASE_PATH . '/database/' . $file));
    }

    $accounts    = new AccountService();
    $tournaments = new TournamentService();

    $adminId = (int) Database::scalar("SELECT id FROM users WHERE role = 'admin' LIMIT 1");

    Database::exec(
        'UPDATE users SET password_hash = :h, must_change_password = 0 WHERE id = :id',
        [':h' => password_hash(TEST_PASSWORD, PASSWORD_BCRYPT, ['cost' => 12]), ':id' => $adminId]
    );

    $dates = [
        'auction_date'              => date('Y-m-d', strtotime('+5 days')),
        'start_date'                => date('Y-m-d', strtotime('+20 days')),
        'end_date'                  => date('Y-m-d', strtotime('+60 days')),
        'team_name_change_deadline' => date('Y-m-d', strtotime('+15 days')),
    ];

    $out = ['admin_id' => $adminId, 'cups' => []];

    foreach (['A' => 'Harbour Cup', 'B' => 'Backwater Cup'] as $key => $name) {
        $cup = $tournaments->create(['name' => $name, 'season_year' => 2027] + $dates);
        $id  = (int) $cup['id'];

        foreach ([
            ['scorer',           "scorer.{$key}", "Scorer {$key}"],
            ['tournament_admin', "tadmin.{$key}", "Tadmin {$key}"],
        ] as [$role, $username, $person]) {
            $made = $accounts->createStaffAccount(
                $person, $username, "{$username}@t.test", $role, TEST_PASSWORD, $id
            );
            Database::exec('UPDATE users SET must_change_password = 0 WHERE id = :id',
                [':id' => $made['user_id']]);
        }

        // Two teams, a match, and an open first innings.
        $teamIds = [];

        foreach ([["{$name} Kings", strtoupper($key) . 'K'], ["{$name} Royals", strtoupper($key) . 'R']] as $i => [$tn, $sn]) {
            $ownerId = $accounts->createStaffAccount(
                "Owner {$key}{$i}", "owner.{$key}{$i}", "owner.{$key}{$i}@t.test", 'viewer', TEST_PASSWORD
            )['user_id'];

            $teamIds[] = (int) $tournaments->createTeam($ownerId, $id, ['name' => $tn, 'short_name' => $sn])['team_id'];
        }

        Database::exec(
            "INSERT INTO matches (tournament_id, match_number, team_a_id, team_b_id,
                                  scheduled_at, status, overs_per_innings)
             VALUES (:t, 1, :a, :b, NOW(), 'live', 20)",
            [':t' => $id, ':a' => $teamIds[0], ':b' => $teamIds[1]]
        );
        $matchId = Database::lastInsertId();

        Database::exec(
            "INSERT INTO innings (match_id, innings_number, batting_team_id, bowling_team_id, started_at)
             VALUES (:m, 1, :a, :b, NOW())",
            [':m' => $matchId, ':a' => $teamIds[0], ':b' => $teamIds[1]]
        );
        $inningsId = Database::lastInsertId();

        // Eleven a side, in the playing XI, so a ball can actually be
        // recorded. Without a squad the API refuses for a scoring reason
        // and the authorisation test proves nothing.
        $xi = [];

        foreach ($teamIds as $slot => $teamId) {
            $xi[$slot] = [];

            for ($n = 1; $n <= 11; $n++) {
                Database::exec(
                    "INSERT INTO players (tournament_id, full_name, country, role, status, team_id)
                     VALUES (:t, :name, 'India', 'batsman', 'sold', :team)",
                    [':t' => $id, ':name' => "{$key}{$slot} Player {$n}", ':team' => $teamId]
                );
                $playerId = Database::lastInsertId();
                $xi[$slot][] = $playerId;

                Database::exec(
                    'INSERT INTO match_squads (match_id, team_id, player_id, batting_order, is_playing_xi)
                     VALUES (:m, :team, :p, :order, 1)',
                    [':m' => $matchId, ':team' => $teamId, ':p' => $playerId, ':order' => $n]
                );
            }
        }

        $out['cups'][$key] = [
            'id'         => $id,
            'match_id'   => $matchId,
            'innings_id' => $inningsId,
            'teams'      => $teamIds,
            'batting'    => $xi[0],
            'bowling'    => $xi[1],
        ];
    }

    return $out;
}

// ---------------------------------------------------------------------

echo "\n\033[1mTournament scoping, over real HTTP\033[0m\n";

$world = seed();
$A     = $world['cups']['A'];
$B     = $world['cups']['B'];

$port = 8098;
$base = "http://127.0.0.1:{$port}";

$server = proc_open(
    sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(BASE_PATH . '/public')),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);

if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the development server.\n");
    exit(1);
}

for ($i = 0; $i < 50; $i++) {
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

    if ($probe !== false) {
        fclose($probe);
        break;
    }

    usleep(100_000);
}

// =====================================================================
section('A scorer writes into their own tournament only');

$jarA = signIn('scorer.A');

$pad = http("{$base}/score.php?innings={$A['innings_id']}", $jarA);
is('the pad opens on their own match', $pad['status'], 200);
is('and is not marked read only',
    str_contains($pad['body'], 'Read only'), false);

$token = csrf($pad['body']);
is('the pad carries a CSRF token', $token !== '', true);

// The API is the thing that writes, so it is the thing that must refuse.
$ball = [
    'csrf_token'     => $token,
    'innings_id'     => $A['innings_id'],
    'runs_off_bat'   => 1,
    'extra_runs'     => 0,
    'extra_type'     => 'none',
    'striker_id'     => $A['batting'][0],
    'non_striker_id' => $A['batting'][1],
    'bowler_id'      => $A['bowling'][0],
];

$own     = http("{$base}/api/scoring.php?action=ball", $jarA, $ball);
$ownJson = json_decode($own['body'], true);
is('a ball into their own innings is accepted', $own['status'], 200);
is('and the API says so',                       $ownJson['ok'] ?? null, true);
is('and it was written',
    (int) Database::scalar('SELECT COUNT(*) FROM ball_by_ball WHERE innings_id = :i',
        [':i' => $A['innings_id']]),
    1);

// The attack: the same scorer, the same session, another tournament's
// innings id and its squad. Nothing about the request is malformed —
// it would score perfectly well if the gate were not there.
$foreign = http("{$base}/api/scoring.php?action=ball", $jarA, [
    'innings_id'     => $B['innings_id'],
    'striker_id'     => $B['batting'][0],
    'non_striker_id' => $B['batting'][1],
    'bowler_id'      => $B['bowling'][0],
] + $ball);
$foreignJson = json_decode($foreign['body'], true);

is('a ball into ANOTHER tournament is refused',  $foreign['status'], 403);
is('with a reason, not a stack trace',           $foreignJson['error'] ?? null, 'WRONG_TOURNAMENT');
is('and nothing was written',
    (int) Database::scalar('SELECT COUNT(*) FROM ball_by_ball WHERE innings_id = :i',
        [':i' => $B['innings_id']]),
    0);

$undo = http("{$base}/api/scoring.php?action=undo", $jarA,
    ['csrf_token' => $token, 'innings_id' => $B['innings_id']]);
is('undo is scoped the same way', $undo['status'], 403);

// Reading is not scoped: a scorecard is public.
$read = http("{$base}/api/scoring.php?action=scorecard&innings_id={$B['innings_id']}", $jarA);
is('but reading another tournament\'s card is fine', $read['status'], 200);

$view = http("{$base}/score.php?innings={$B['innings_id']}", $jarA);
is('and the pad still opens on it',   $view['status'], 200);
is('marked read only',                str_contains($view['body'], 'Read only'), true);
is('naming the reason',
    str_contains($view['body'], 'belongs to a different tournament'), true);

// An unassigned scorer keeps their account and loses the pad.
Database::exec("UPDATE users SET tournament_id = NULL WHERE username = 'scorer.A'");
$loose = http("{$base}/score.php?innings={$A['innings_id']}", $jarA);
is('an unassigned scorer still reads',    $loose['status'], 200);
is('and is told to ask for a tournament',
    str_contains($loose['body'], 'not been given a tournament'), true);

$blocked = http("{$base}/api/scoring.php?action=ball", $jarA, $ball);
is('and cannot write at all', $blocked['status'], 403);

Database::exec("UPDATE users SET tournament_id = :t WHERE username = 'scorer.A'", [':t' => $A['id']]);

// An administrator is scoped to nothing.
$adminJar = signIn('admin');
$anywhere = http("{$base}/api/scoring.php?action=ball", $adminJar, [
    'innings_id'     => $B['innings_id'],
    'striker_id'     => $B['batting'][0],
    'non_striker_id' => $B['batting'][1],
    'bowler_id'      => $B['bowling'][0],
    'csrf_token'     => csrf(http("{$base}/score.php?innings={$B['innings_id']}", $adminJar)['body']),
] + $ball);
is('an administrator may score any tournament', $anywhere['status'], 200);
is('and that ball landed',
    (int) Database::scalar('SELECT COUNT(*) FROM ball_by_ball WHERE innings_id = :i',
        [':i' => $B['innings_id']]),
    1);

// =====================================================================
section('A tournament administrator sees one tournament');

$tadminA = signIn('tadmin.A');

$overview = http("{$base}/admin/index.php", $tadminA);
is('the admin overview opens',             $overview['status'], 200);
is('naming the tournament they run',       str_contains($overview['body'], 'Harbour Cup'), true);
is('with no People link',                  str_contains($overview['body'], 'users.php'), false);
is('and no Tournaments link',              str_contains($overview['body'], 'tournaments.php'), false);

is('People itself is refused',
    http("{$base}/admin/users.php", $tadminA)['status'], 403);
is('and so is Tournaments',
    http("{$base}/admin/tournaments.php", $tadminA)['status'], 403);

foreach (['applications', 'teams', 'players', 'auction'] as $screen) {
    is("{$screen}.php opens on their own tournament",
        http("{$base}/admin/{$screen}.php?tournament={$A['id']}", $tadminA)['status'], 200);

    // The attack: the same session, the other tournament's id.
    is("{$screen}.php refuses the other tournament",
        http("{$base}/admin/{$screen}.php?tournament={$B['id']}", $tadminA)['status'], 403);
}

// The purse is an administrator's field, so it is not even rendered.
$teamsPage = http("{$base}/admin/teams.php?tournament={$A['id']}", $tadminA);
is('the team form offers colour and ground',
    str_contains($teamsPage['body'], 'name="primary_color"')
        && str_contains($teamsPage['body'], 'name="home_venue"'), true);
is('but not the purse',
    str_contains($teamsPage['body'], 'name="purse_total"'), false);

// And posting it anyway does nothing.
$before = (float) Database::scalar('SELECT purse_total FROM teams WHERE id = :t', [':t' => $A['teams'][0]]);
http("{$base}/admin/teams.php", $tadminA, [
    'csrf_token'    => csrf($teamsPage['body']),
    'action'        => 'rename',
    'team_id'       => $A['teams'][0],
    'tournament_id' => $A['id'],
    'purse_total'   => '99000000',
]);
is('posting a purse they were never shown is ignored',
    (float) Database::scalar('SELECT purse_total FROM teams WHERE id = :t', [':t' => $A['teams'][0]]),
    $before);

// An administrator does get the field.
$adminTeams = http("{$base}/admin/teams.php?tournament={$A['id']}", $adminJar);
is('an administrator is offered the purse',
    str_contains($adminTeams['body'], 'name="purse_total"'), true);

// =====================================================================
section('Editing a player through the screen');

// Approve somebody into Harbour Cup so there is a lot to edit.
$accounts    = new AccountService();
$tournaments = new TournamentService();

$uid = $accounts->register([
    'name' => 'Arun Nair', 'email' => 'arun@t.test', 'username' => 'arun.n',
    'phone' => '9000000009', 'address' => '1 Test Road', 'player_type' => 'batsman',
    'password' => TEST_PASSWORD, 'password_confirm' => TEST_PASSWORD,
]);
$accounts->decideRegistration($uid, true, $world['admin_id']);

$code = (string) Database::scalar('SELECT secret_code FROM tournaments WHERE id = :t', [':t' => $A['id']]);
$reg  = $tournaments->apply($uid, $code);
$made = $tournaments->decideApplication((int) $reg['registration_id'], true, $world['admin_id'], '',
    ['base_price' => 200000]);
$playerId = (int) $made['player_id'];

$page = http("{$base}/admin/players.php?tournament={$A['id']}", $adminJar);
is('the players screen lists them',  str_contains($page['body'], 'Arun Nair'), true);
is('showing the base price',         str_contains($page['body'], '2,00,000'), true);

$post = http("{$base}/admin/players.php", $adminJar, [
    'csrf_token'    => csrf($page['body']),
    'player_id'     => $playerId,
    'tournament_id' => $A['id'],
    'full_name'     => 'Arun M Nair',
    'role'          => 'bowling_all_rounder',
    'auction_set'   => 'Marquee',
    'base_price'    => '5000',
    'country'       => 'India',
    'bowling_style' => 'left_arm_orthodox',
]);

$after = Database::one('SELECT * FROM players WHERE id = :p', [':p' => $playerId]);
is('the edit saves over HTTP',        $after['full_name'],   'Arun M Nair');
is('the type of player changes',      $after['role'],        'bowling_all_rounder');
is('the auction set is set',          $after['auction_set'], 'Marquee');
is('the base price is lowered',       (float) $after['base_price'], 5000.0);
is('and the AUCTION SHEET agrees',
    (float) Database::scalar('SELECT base_price FROM auction_lots WHERE player_id = :p', [':p' => $playerId]),
    5000.0);

// A tournament administrator may correct their own tournament's players,
// because they are the one who approved them.
$theirs = http("{$base}/admin/players.php", $tadminA, [
    'csrf_token'    => csrf(http("{$base}/admin/players.php?tournament={$A['id']}", $tadminA)['body']),
    'player_id'     => $playerId,
    'tournament_id' => $A['id'],
    'full_name'     => 'Arun Nair',
    'role'          => 'batsman',
]);
is('a tournament administrator can correct their own',
    Database::scalar('SELECT full_name FROM players WHERE id = :p', [':p' => $playerId]),
    'Arun Nair');

// But not somebody else's, even by id.
$tadminB = signIn('tadmin.B');
$crossed = http("{$base}/admin/players.php", $tadminB, [
    'csrf_token'    => csrf(http("{$base}/admin/players.php?tournament={$B['id']}", $tadminB)['body']),
    'player_id'     => $playerId,
    'tournament_id' => $B['id'],
    'full_name'     => 'Hijacked Name',
]);
is('another tournament\'s administrator is refused', $crossed['status'], 403);
is('and the name is untouched',
    Database::scalar('SELECT full_name FROM players WHERE id = :p', [':p' => $playerId]),
    'Arun Nair');

// ---------------------------------------------------------------------

proc_terminate($server);
proc_close($server);

echo "\n" . str_repeat('─', 60) . "\n";

if ($failed === 0) {
    echo "  \033[32m{$passed} passed\033[0m\n\n";
    exit(0);
}

echo "  \033[32m{$passed} passed\033[0m, \033[31m{$failed} failed\033[0m\n\n";
exit(1);
