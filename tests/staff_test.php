<?php

declare(strict_types=1);

/**
 * Integration tests for the two features added in migration 005:
 *
 *   a scorer belongs to one tournament and scores only that one
 *   a tournament administrator runs one tournament
 *
 * and for the editing that goes with them — an approved player's auction
 * fields, and a team's details including its purse.
 *
 *     php tests/staff_test.php
 *
 * Reloads schema.sql + seed.sql itself, so it is destructive to the
 * `cric_auction` database and safe to re-run.
 */

require_once dirname(__DIR__) . '/config/config.php';

use App\Core\Auth;
use App\Exceptions\AccountException;
use App\Services\AccountService;
use App\Services\ActivityLog;
use App\Services\TournamentService;

$passed = 0;
$failed = 0;

function ok(string $label): void
{
    global $passed;
    $passed++;
    echo "  \033[32m✓\033[0m {$label}\n";
}

function bad(string $label, string $detail): void
{
    global $failed;
    $failed++;
    echo "  \033[31m✗\033[0m {$label}\n      {$detail}\n";
}

function is(string $label, mixed $actual, mixed $expected): void
{
    $a = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
    $b = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);

    $a === $b ? ok($label) : bad($label, "expected {$b}, got {$a}");
}

function rejects(string $label, string $expectedCode, callable $work): void
{
    try {
        $work();
        bad($label, "expected {$expectedCode}, but the call succeeded");
    } catch (AccountException $e) {
        $e->errorCode() === $expectedCode
            ? ok($label . '  (' . $e->getMessage() . ')')
            : bad($label, "expected {$expectedCode}, got {$e->errorCode()}: {$e->getMessage()}");
    }
}

function section(string $name): void
{
    echo "\n\033[1m{$name}\033[0m\n";
}

/** Pretend to be this user, the way a signed-in request would. */
function actAs(?int $userId): void
{
    if ($userId === null) {
        unset($_SESSION['user']);

        return;
    }

    $row = Database::one('SELECT id, username, name, email, role, team_id, tournament_id FROM users WHERE id = :id',
        [':id' => $userId]);

    $_SESSION['user'] = [
        'id'            => (int) $row['id'],
        'username'      => $row['username'],
        'name'          => $row['name'],
        'email'         => $row['email'],
        'role'          => $row['role'],
        'team_id'       => $row['team_id'] !== null ? (int) $row['team_id'] : null,
        'tournament_id' => $row['tournament_id'] !== null ? (int) $row['tournament_id'] : null,
        'must_change_password' => false,
    ];
}

function playerRow(int $id): array
{
    return Database::one('SELECT * FROM players WHERE id = :id', [':id' => $id]) ?? [];
}

// ---------------------------------------------------------------------

echo "\n\033[1mTournament staff, and editing what approval set\033[0m\n";

$pdo = Database::pdo();

foreach (['schema.sql', 'seed.sql'] as $file) {
    $pdo->exec((string) file_get_contents(BASE_PATH . '/database/' . $file));
}

$_SESSION = [];

$accounts    = new AccountService();
$tournaments = new TournamentService();

$ADMIN = (int) Database::scalar("SELECT id FROM users WHERE role = 'admin' LIMIT 1");

$dates = [
    'auction_date'              => date('Y-m-d', strtotime('+5 days')),
    'start_date'                => date('Y-m-d', strtotime('+20 days')),
    'end_date'                  => date('Y-m-d', strtotime('+60 days')),
    'team_name_change_deadline' => date('Y-m-d', strtotime('+15 days')),
];

$cupA = $tournaments->create(['name' => 'Harbour Cup', 'season_year' => 2027] + $dates);
$cupB = $tournaments->create(['name' => 'Backwater Cup', 'season_year' => 2027] + $dates);
$A    = (int) $cupA['id'];
$B    = (int) $cupB['id'];

// =====================================================================
section('Who may work on which tournament');

$scorerA = $accounts->createStaffAccount('Divya Menon', 'divya.s', 'divya@t.test', 'scorer', null, $A)['user_id'];
$scorerB = $accounts->createStaffAccount('Sneha Kurup', 'sneha.s', 'sneha@t.test', 'scorer', null, $B)['user_id'];
$tadminA = $accounts->createStaffAccount('Rohit K', 'rohit.t', 'rohit@t.test', 'tournament_admin', null, $A)['user_id'];
$loose   = $accounts->createStaffAccount('Unassigned', 'loose.s', 'loose@t.test', 'viewer')['user_id'];

actAs($ADMIN);
is('an administrator works on anything',        Auth::worksOn($A), true);
is('including the other tournament',            Auth::worksOn($B), true);
is('and on nothing in particular',              Auth::worksOn(null), true);
is('an administrator is scoped to no tournament', Auth::tournamentId(), null);

actAs($scorerA);
is('a scorer works on their own tournament',    Auth::worksOn($A), true);
is('and not on somebody else\'s',               Auth::worksOn($B), false);
is('nor on a null one',                         Auth::worksOn(null), false);
is('they are not any kind of admin',            Auth::isAnyAdmin(), false);

actAs($tadminA);
is('a tournament administrator works on theirs', Auth::worksOn($A), true);
is('and not on the other',                       Auth::worksOn($B), false);
is('but counts as an admin for shared screens',  Auth::isAnyAdmin(), true);

actAs($loose);
is('a viewer works on nothing',                  Auth::worksOn($A), false);

// A scorer whose tournament is taken away loses the tournament, not the
// account: they can still sign in and read.
Database::exec('UPDATE users SET tournament_id = NULL WHERE id = :id', [':id' => $scorerB]);
actAs($scorerB);
is('an unassigned scorer works on nothing',      Auth::worksOn($B), false);
Database::exec('UPDATE users SET tournament_id = :t WHERE id = :id', [':t' => $B, ':id' => $scorerB]);

// Deleting a tournament releases its staff rather than deleting them —
// that is what ON DELETE SET NULL buys.
$doomed   = (int) $tournaments->create(['name' => 'Doomed Cup', 'season_year' => 2029] + $dates)['id'];
$orphaned = $accounts->createStaffAccount('Temp Scorer', 'temp.s', 'temp@t.test', 'scorer', null, $doomed)['user_id'];
Database::exec('DELETE FROM tournaments WHERE id = :t', [':t' => $doomed]);

$after = Database::one('SELECT id, role, tournament_id FROM users WHERE id = :id', [':id' => $orphaned]);
is('deleting a tournament keeps its scorer',     $after !== null, true);
is('and simply unassigns them',                  $after['tournament_id'], null);
is('with the role intact',                       $after['role'], 'scorer');

actAs(null);
is('a signed-out visitor works on nothing',      Auth::worksOn($A), false);

// =====================================================================
section('The tournament list is narrowed to the one you run');

actAs($ADMIN);
$adminSees = array_column($tournaments->listTournamentsForCurrentUser(), 'id');
is('an administrator sees them all',
    count(array_intersect([$A, $B], array_map('intval', $adminSees))), 2);

actAs($tadminA);
$mine = $tournaments->listTournamentsForCurrentUser();
is('a tournament administrator sees one',        count($mine), 1);
is('and it is theirs',                           (int) $mine[0]['id'], $A);

actAs($scorerA);
$theirs = $tournaments->listTournamentsForCurrentUser();
is('a scorer sees only theirs too',              count($theirs), 1);

// =====================================================================
section('Editing a player who is already in the auction');

actAs($ADMIN);

// Two applicants, approved into Harbour Cup, so there are real lots.
$ids = [];
foreach ([['Arun Nair', 'arun@t.test', 'arun.n', '9000000001'],
          ['Vivek Raj', 'vivek@t.test', 'vivek.r', '9000000002']] as [$name, $mail, $user, $phone]) {
    $uid = $accounts->register([
        'name' => $name, 'email' => $mail, 'username' => $user, 'phone' => $phone,
        'address' => '1 Test Road', 'player_type' => 'batsman',
        'password' => 'Cricket2026', 'password_confirm' => 'Cricket2026',
    ]);
    $accounts->decideRegistration($uid, true, $ADMIN);
    $reg = $tournaments->apply($uid, (string) $cupA['secret_code']);
    $ids[$name] = $tournaments->decideApplication(
        (int) $reg['registration_id'], true, $ADMIN, '', ['base_price' => 200000]
    );
}

$arun = (int) $ids['Arun Nair']['player_id'];

is('the pool has both',      count($tournaments->poolPlayers($A)), 2);
is('their base price is what approval set', (float) playerRow($arun)['base_price'], 200000.0);
is('and the lot carries the same figure',
    (float) Database::scalar('SELECT base_price FROM auction_lots WHERE player_id = :p', [':p' => $arun]),
    200000.0);

$saved = $tournaments->updatePlayer($arun, [
    'full_name'      => 'Arun M Nair',
    'display_name'   => 'A Nair',
    'country'        => 'India',
    'role'           => 'bowling_all_rounder',
    'batting_style'  => 'left_hand',
    'bowling_style'  => 'left_arm_orthodox',
    'auction_set'    => 'Marquee',
    'base_price'     => '5000',
    'is_overseas'    => false,
    'is_capped'      => true,
    'career_matches' => '48',
    'career_runs'    => '1620',
    'career_wickets' => '31',
    'strike_rate'    => '138.4',
    'economy'        => '7.25',
]);

$row = playerRow($arun);
is('the name is corrected',        $row['full_name'],     'Arun M Nair');
is('the short name is set',        $row['display_name'],  'A Nair');
is('the type of player changes',   $row['role'],          'bowling_all_rounder');
is('the batting style is set',     $row['batting_style'], 'left_hand');
is('the bowling style is set',     $row['bowling_style'], 'left_arm_orthodox');
is('the auction set is set',       $row['auction_set'],   'Marquee');
is('capped is flagged',            (int) $row['is_capped'], 1);
is('the career figures are kept',  (int) $row['career_runs'], 1620);
is('the strike rate is kept',      (float) $row['strike_rate'], 138.40);
is('the base price is lowered',    (float) $row['base_price'], 5000.0);

// The part a hand-written UPDATE gets wrong.
is('and the LOT is lowered with it',
    (float) Database::scalar('SELECT base_price FROM auction_lots WHERE player_id = :p', [':p' => $arun]),
    5000.0);
is('the service says it moved the money', $saved['base_price'], true);

// Clearing an optional field really clears it.
$tournaments->updatePlayer($arun, ['display_name' => '', 'auction_set' => '']);
is('a blank short name clears it',  playerRow($arun)['display_name'], null);
is('a blank auction set clears it', playerRow($arun)['auction_set'],  null);

// A base price that is left out is not treated as zero.
$tournaments->updatePlayer($arun, ['full_name' => 'Arun Nair']);
is('an omitted base price is left alone', (float) playerRow($arun)['base_price'], 5000.0);

rejects('a base price of zero is refused', AccountException::VALIDATION,
    fn () => $tournaments->updatePlayer($arun, ['base_price' => '0']));

rejects('and so is a negative one', AccountException::VALIDATION,
    fn () => $tournaments->updatePlayer($arun, ['base_price' => '-500']));

rejects('a strike rate off the scale is refused', AccountException::VALIDATION,
    fn () => $tournaments->updatePlayer($arun, ['strike_rate' => '900']));

rejects('no such player', AccountException::NOT_FOUND,
    fn () => $tournaments->updatePlayer(999999, ['full_name' => 'Ghost']));

// An unknown role falls back rather than throwing — the same rule the
// approval form uses, so the two screens cannot disagree.
$tournaments->updatePlayer($arun, ['role' => 'wizard']);
is('an unknown type of player falls back to batsman', playerRow($arun)['role'], 'batsman');

// =====================================================================
section('Money is settled once a lot has been called');

$vivek = (int) $ids['Vivek Raj']['player_id'];
$team  = $tournaments->createTeam(
    $accounts->createStaffAccount('Owner One', 'owner.one', 'owner1@t.test', 'viewer')['user_id'],
    $A,
    ['name' => 'Harbour Hawks', 'short_name' => 'HH']
);
$teamId = (int) $team['team_id'];

// Sell him, the way the auction sheet does.
Database::exec(
    "UPDATE players SET status = 'sold', team_id = :t, sold_price = base_price WHERE id = :p",
    [':t' => $teamId, ':p' => $vivek]
);
Database::exec(
    "UPDATE auction_lots SET status = 'sold', sold_to_team_id = :t, sold_price = base_price,
            closed_at = NOW() WHERE player_id = :p",
    [':t' => $teamId, ':p' => $vivek]
);
Database::exec(
    'UPDATE teams SET purse_spent = 200000, players_bought = 1 WHERE id = :t',
    [':t' => $teamId]
);

rejects('a sold player\'s base price is fixed', AccountException::VALIDATION,
    fn () => $tournaments->updatePlayer($vivek, ['base_price' => '100000']));

rejects('and their overseas flag with it', AccountException::VALIDATION,
    fn () => $tournaments->updatePlayer($vivek, ['is_overseas' => true]));

// Everything that is not money stays editable, because a typo in a name
// should not need the auction to be over.
$tournaments->updatePlayer($vivek, ['full_name' => 'Vivek S Raj', 'auction_set' => 'Set B']);
is('but the name can still be fixed',      playerRow($vivek)['full_name'],   'Vivek S Raj');
is('and the auction set with it',          playerRow($vivek)['auction_set'], 'Set B');

// Re-sending the SAME base price on a sold player is a no-op, not an
// error — otherwise saving the form without touching the money would fail.
$again = $tournaments->updatePlayer($vivek, ['base_price' => '200000', 'full_name' => 'Vivek Raj']);
is('resending the same base price is allowed', $again['base_price'], false);
is('and the rest of the form still saves',     playerRow($vivek)['full_name'], 'Vivek Raj');

is('the sale is untouched throughout',
    (float) Database::scalar('SELECT sold_price FROM players WHERE id = :p', [':p' => $vivek]),
    200000.0);

// =====================================================================
section('Editing a team, purse included');

$edited = $tournaments->renameTeam($teamId, $ADMIN, [
    'name'          => 'Harbour Kings',
    'short_name'    => 'HK',
    'primary_color' => '#ffffff',
    'home_venue'    => 'Marine Drive Ground',
    'purse_total'   => '12000000',
], actorIsAdmin: true, canSetPurse: true);

$teamRow = Database::one('SELECT * FROM teams WHERE id = :t', [':t' => $teamId]);
is('the team is renamed',        $teamRow['name'],          'Harbour Kings');
is('the short name changes',     $teamRow['short_name'],    'HK');
is('the colour changes',         strtolower((string) $teamRow['primary_color']), '#ffffff');
is('the home ground is set',     $teamRow['home_venue'],    'Marine Drive Ground');
is('the purse is raised',        (float) $teamRow['purse_total'], 12000000.0);
is('and what is left follows it',
    (float) $teamRow['purse_remaining'], 12000000.0 - 200000.0);

rejects('the purse cannot go below what is spent', AccountException::VALIDATION,
    fn () => $tournaments->renameTeam($teamId, $ADMIN, ['purse_total' => '100000'],
        actorIsAdmin: true, canSetPurse: true));

// Without the flag the field is ignored rather than obeyed — which is what
// stops a tournament administrator posting a field they were never shown.
$tournaments->renameTeam($teamId, $ADMIN, ['purse_total' => '99000000'], actorIsAdmin: true);
is('and it is ignored without the purse flag',
    (float) Database::scalar('SELECT purse_total FROM teams WHERE id = :t', [':t' => $teamId]),
    12000000.0);

// A blank purse means "leave it", not "zero".
$tournaments->renameTeam($teamId, $ADMIN, ['purse_total' => '', 'home_venue' => 'Fort Kochi'],
    actorIsAdmin: true, canSetPurse: true);
is('a blank purse leaves it alone',
    (float) Database::scalar('SELECT purse_total FROM teams WHERE id = :t', [':t' => $teamId]),
    12000000.0);
is('while the rest of the form saves',
    Database::scalar('SELECT home_venue FROM teams WHERE id = :t', [':t' => $teamId]),
    'Fort Kochi');

// =====================================================================
section('The activity log');

$logged = static function (string $action): array {
    $row = Database::one(
        'SELECT * FROM activity_log WHERE action = :a ORDER BY id DESC LIMIT 1',
        [':a' => $action]
    );

    if ($row === null) {
        return [];
    }

    $row['decoded'] = $row['changes'] === null ? [] : json_decode((string) $row['changes'], true);

    return $row;
};

is('the table is there', ActivityLog::isAvailable(), true);

// Everything above this point ran as $ADMIN, so the trail should name them.
actAs($ADMIN);
$before = (int) Database::scalar('SELECT COUNT(*) FROM activity_log');

$tournaments->updatePlayer($arun, ['base_price' => '7500', 'full_name' => 'Arun Nayar']);

$line = $logged('player.update');
is('an edit is recorded',            $line !== [], true);
is('naming who did it',              $line['actor_name'] ?? null,
    Database::scalar('SELECT name FROM users WHERE id = :i', [':i' => $ADMIN]));
is('and which player',               $line['subject_label'] ?? null, 'Arun Nair');
is('scoped to the tournament',       (int) ($line['tournament_id'] ?? 0), $A);
is('with the OLD base price',        $line['decoded']['base_price']['from'] ?? null, '5000.00');
is('and the new one',                $line['decoded']['base_price']['to'] ?? null, '7500.00');
is('and the name that changed',      $line['decoded']['full_name']['to'] ?? null, 'Arun Nayar');

// A save that changes nothing should not fill the log with empty lines.
$countNow = (int) Database::scalar('SELECT COUNT(*) FROM activity_log');
$tournaments->updatePlayer($arun, ['full_name' => 'Arun Nayar', 'base_price' => '7500']);
is('a save that changes nothing is not recorded',
    (int) Database::scalar('SELECT COUNT(*) FROM activity_log'), $countNow);

// "5000" and "5000.00" are the same number, not a change.
is('the same number in a different shape is not a change',
    ActivityLog::diff(['base_price' => '5000.00'], ['base_price' => '5000'], ['base_price']), []);
is('but a real move is',
    array_keys(ActivityLog::diff(['base_price' => '5000.00'], ['base_price' => '6000'], ['base_price'])),
    ['base_price']);

// The purse edit.
$tournaments->renameTeam($teamId, $ADMIN, ['home_venue' => 'Willingdon Island'],
    actorIsAdmin: true, canSetPurse: true);
$teamLine = $logged('team.update');
is('a team edit is recorded',    $teamLine['subject_label'] ?? null, 'Harbour Kings');
is('with the old ground',        $teamLine['decoded']['home_venue']['from'] ?? null, 'Fort Kochi');

// A sale, which is the line an organiser is most likely to come looking for.
$auction = new App\Services\AuctionService();
$queued  = Database::one(
    "SELECT l.id, l.base_price FROM auction_lots l JOIN players p ON p.id = l.player_id
      WHERE l.tournament_id = :t AND l.status = 'queued' LIMIT 1",
    [':t' => $A]
);

if ($queued !== null) {
    // At the lot's own base price: a sale below it is refused, and this
    // test is about the log, not about the floor.
    $price = (float) $queued['base_price'];
    $auction->recordSale((int) $queued['id'], $teamId, $price, $ADMIN);
    $sale = $logged('auction.sold');
    is('a sale is recorded',        $sale !== [], true);
    is('with the buying team',      $sale['decoded']['sold_to']['to'] ?? null, 'Harbour Kings');
    is('and the price',             $sale['decoded']['sold_price']['to'] ?? null,
        number_format($price, 2, '.', ''));
}

// A missing table must never stop a change from being saved. This is the
// property that matters most: the log is a witness, not a gatekeeper.
Database::exec('DROP TABLE activity_log');
is('the log knows it is gone', ActivityLog::isAvailable(), false);

$survived = true;

try {
    $tournaments->updatePlayer($arun, ['full_name' => 'Arun Nair']);
} catch (Throwable $e) {
    $survived = false;
}

is('an edit still saves with no log table', $survived, true);
is('and the change really landed',
    Database::scalar('SELECT full_name FROM players WHERE id = :p', [':p' => $arun]),
    'Arun Nair');
is('reading an absent log returns nothing rather than throwing',
    ActivityLog::recent(), []);

// ---------------------------------------------------------------------

echo "\n" . str_repeat('─', 60) . "\n";

if ($failed === 0) {
    echo "  \033[32m{$passed} passed\033[0m\n\n";
    exit(0);
}

echo "  \033[32m{$passed} passed\033[0m, \033[31m{$failed} failed\033[0m\n\n";
exit(1);
