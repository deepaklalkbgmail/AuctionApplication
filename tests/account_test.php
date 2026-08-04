<?php

declare(strict_types=1);

/**
 * Integration tests for AccountService and TournamentService — run against a
 * real MySQL/MariaDB.
 *
 *     php tests/account_test.php
 *
 * No PHPUnit dependency: a plain script, so it runs anywhere PHP and the
 * database exist. It reloads schema.sql + seed.sql itself, so it is
 * destructive to the `cric_auction` database and safe to re-run.
 */

require_once dirname(__DIR__) . '/config/config.php';

use App\Core\Auth;
use App\Exceptions\AccountException;
use App\Services\AccountService;
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

/** Assert that $work is rejected with a specific AccountException code. */
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

function resetDatabase(): void
{
    $pdo = Database::pdo();

    foreach (['schema.sql', 'seed.sql'] as $file) {
        $sql = file_get_contents(BASE_PATH . '/database/' . $file);

        if ($sql === false) {
            fwrite(STDERR, "Cannot read database/{$file}\n");
            exit(1);
        }

        $pdo->exec($sql);
    }
}

function userRow(int $id): array
{
    return Database::one('SELECT * FROM users WHERE id = :id', [':id' => $id]) ?? [];
}

/** A registration payload with sensible defaults, overridable per test. */
function registration(array $overrides = []): array
{
    return $overrides + [
        'name'             => 'Nikhil Rao',
        'email'            => 'nikhil@club.test',
        'username'         => 'nikhil.rao',
        'phone'            => '9876543210',
        'address'          => '14 Marine Drive, Kochi',
        'player_type'      => 'all_rounder',
        'password'         => 'Cricket2026',
        'password_confirm' => 'Cricket2026',
    ];
}

// ---------------------------------------------------------------------

echo "\n\033[1mAccountService + TournamentService integration tests\033[0m\n";

resetDatabase();

$accounts    = new AccountService();
$tournaments = new TournamentService();

$ADMIN = (int) Database::scalar("SELECT id FROM users WHERE role = 'admin' LIMIT 1");

// =====================================================================
section('Player self-registration');

$nikhil = $accounts->register(registration());
is('registration returns a new user id', $nikhil > 0, true);

$row = userRow($nikhil);
is('the account is a player',            $row['role'],        'player');
is('and waits for an administrator',     $row['status'],      'pending');
is('name is stored as entered',          $row['name'],        'Nikhil Rao');
is('email is lower-cased',               $row['email'],       'nikhil@club.test');
is('address is kept',                    $row['address'],     '14 Marine Drive, Kochi');
is('kind of player is kept',             $row['player_type'], 'all_rounder');
is('the password is hashed, not stored', str_starts_with((string) $row['password_hash'], '$2y$'), true);
is('no photo is fine',                   $row['photo_path'],  null);

rejects('the same email cannot register twice', AccountException::EMAIL_TAKEN,
    fn () => $accounts->register(registration(['username' => 'other.name'])));

rejects('the same username cannot register twice', AccountException::USERNAME_TAKEN,
    fn () => $accounts->register(registration(['email' => 'other@club.test'])));

rejects('a short password is refused', AccountException::WEAK_PASSWORD,
    fn () => $accounts->register(registration([
        'email' => 'a@club.test', 'username' => 'aaa',
        'password' => 'abc12', 'password_confirm' => 'abc12',
    ])));

rejects('a password with no digit is refused', AccountException::WEAK_PASSWORD,
    fn () => $accounts->register(registration([
        'email' => 'b@club.test', 'username' => 'bbb',
        'password' => 'cricketing', 'password_confirm' => 'cricketing',
    ])));

rejects('mistyped confirmation is refused', AccountException::VALIDATION,
    fn () => $accounts->register(registration([
        'email' => 'c@club.test', 'username' => 'ccc', 'password_confirm' => 'Cricket2027',
    ])));

rejects('a mobile number that is too short is refused', AccountException::VALIDATION,
    fn () => $accounts->register(registration([
        'email' => 'd@club.test', 'username' => 'ddd', 'phone' => '12345',
    ])));

rejects('an address is required', AccountException::VALIDATION,
    fn () => $accounts->register(registration([
        'email' => 'e@club.test', 'username' => 'eee', 'address' => '',
    ])));

rejects('the kind of player is required', AccountException::VALIDATION,
    fn () => $accounts->register(registration([
        'email' => 'f@club.test', 'username' => 'fff', 'player_type' => '',
    ])));

rejects('an invented kind of player is refused', AccountException::VALIDATION,
    fn () => $accounts->register(registration([
        'email' => 'g@club.test', 'username' => 'ggg', 'player_type' => 'twelfth_man',
    ])));

rejects('a username with spaces is refused', AccountException::VALIDATION,
    fn () => $accounts->register(registration([
        'email' => 'h@club.test', 'username' => 'nikhil rao',
    ])));

// =====================================================================
section('Signing in is gated on approval');

$pdo = Database::pdo();

is('a pending account cannot sign in',
    Auth::attempt($pdo, 'nikhil.rao', 'Cricket2026'), false);
is('and is told why, rather than "wrong password"',
    Auth::failureReason(), 'pending');

is('a wrong password is generic',
    Auth::attempt($pdo, 'nikhil.rao', 'not-the-password'), false);
is('with no hint that the account exists',
    Auth::failureReason(), 'credentials');

is('an unknown username is generic too',
    Auth::attempt($pdo, 'nobody.here', 'whatever12'), false);
is('and reports the same reason',
    Auth::failureReason(), 'credentials');

$decision = $accounts->decideRegistration($nikhil, true, $ADMIN);
is('the administrator approves',    $decision['status'],           'approved');
is('the approval is attributed',    (int) userRow($nikhil)['approved_by'], $ADMIN);
is('and time-stamped',              userRow($nikhil)['approved_at'] !== null, true);

is('an approved account signs in with its username',
    Auth::attempt($pdo, 'nikhil.rao', 'Cricket2026'), true);
is('the session carries the role', Auth::role(), 'player');

is('the same account signs in with its email',
    Auth::attempt($pdo, 'nikhil@club.test', 'Cricket2026'), true);

rejects('a decision cannot be made twice', AccountException::ALREADY_DECIDED,
    fn () => $accounts->decideRegistration($nikhil, false, $ADMIN));

// A suspended account is turned away.
Database::exec("UPDATE users SET status = 'suspended' WHERE id = :id", [':id' => $nikhil]);
is('a suspended account cannot sign in', Auth::attempt($pdo, 'nikhil.rao', 'Cricket2026'), false);
is('and is told so',                     Auth::failureReason(), 'suspended');
Database::exec("UPDATE users SET status = 'approved' WHERE id = :id", [':id' => $nikhil]);

// =====================================================================
section('A player edits their own details — but not their identity');

$accounts->updateOwnProfile($nikhil, [
    'phone'       => '9000011111',
    'address'     => '22 Fort Road, Kochi',
    'player_type' => 'wicket_keeper',
    // Sent by a tampered form. The method does not take them, so they are
    // not merely ignored by the UI — they cannot reach the UPDATE at all.
    'name'        => 'Someone Else',
    'email'       => 'someone.else@club.test',
]);

$row = userRow($nikhil);
is('the mobile number is updated',   $row['phone'],       '9000011111');
is('the address is updated',         $row['address'],     '22 Fort Road, Kochi');
is('the kind of player is updated',  $row['player_type'], 'wicket_keeper');
is('the name is untouched',          $row['name'],        'Nikhil Rao');
is('the email is untouched',         $row['email'],       'nikhil@club.test');

$accounts->adminUpdateUser($nikhil, ['name' => 'Nikhil S Rao', 'email' => 'nikhil.rao@club.test']);
$row = userRow($nikhil);
is('an administrator can fix the name',  $row['name'],  'Nikhil S Rao');
is('and the email',                      $row['email'], 'nikhil.rao@club.test');
is('without disturbing the address',     $row['address'], '22 Fort Road, Kochi');

$other = $accounts->register(registration([
    'name' => 'Vikram Das', 'email' => 'vikram@club.test', 'username' => 'vikram.das',
]));

rejects('an administrator cannot move an email onto a taken one', AccountException::EMAIL_TAKEN,
    fn () => $accounts->adminUpdateUser($other, ['email' => 'nikhil.rao@club.test']));

// =====================================================================
section('Staff accounts and passwords');

$scorer = $accounts->createStaffAccount('Divya Menon', 'divya.scorer', 'divya@club.test', 'scorer');
is('a scorer account is created',        $scorer['user_id'] > 0, true);
is('with a password to hand over',       strlen($scorer['password']) >= 8, true);
is('the account is approved at once',    userRow($scorer['user_id'])['status'], 'approved');
is('but must change its password',       (int) userRow($scorer['user_id'])['must_change_password'], 1);

is('the issued password works',
    Auth::attempt($pdo, 'divya.scorer', $scorer['password']), true);
is('and the session says so',
    Auth::mustChangePassword(), true);

rejects('staff roles are limited', AccountException::VALIDATION,
    fn () => $accounts->createStaffAccount('X', 'xx.yy', 'x@club.test', 'superuser'));

rejects('changing a password needs the current one', AccountException::WRONG_PASSWORD,
    fn () => $accounts->changeOwnPassword($scorer['user_id'], 'wrong-one', 'Scoring2026', 'Scoring2026'));

rejects('the new password cannot repeat the old', AccountException::VALIDATION,
    fn () => $accounts->changeOwnPassword(
        $scorer['user_id'], $scorer['password'], $scorer['password'], $scorer['password']
    ));

$accounts->changeOwnPassword($scorer['user_id'], $scorer['password'], 'Scoring2026', 'Scoring2026');
is('the password is changed',              Auth::attempt($pdo, 'divya.scorer', 'Scoring2026'), true);
is('and the forced change is cleared',     Auth::mustChangePassword(), false);
is('the old password no longer works',     Auth::attempt($pdo, 'divya.scorer', $scorer['password']), false);

$reset = $accounts->adminResetPassword($scorer['user_id']);
is('an administrator can reset it',        Auth::attempt($pdo, 'divya.scorer', $reset), true);
is('and it must be changed again',         Auth::mustChangePassword(), true);

// The admin's own password is changed the same way as anybody's.
$accounts->changeOwnPassword($ADMIN, 'Passw0rd!', 'Ashes2026Win', 'Ashes2026Win');
is('the administrator can change their own password',
    Auth::attempt($pdo, 'admin@cricauction.test', 'Ashes2026Win'), true);

// =====================================================================
section('Secret codes');

$banned = ['0', 'o', 'O', 'i', 'I', 'L', 'l', '1'];
$clean  = true;

for ($i = 0; $i < 400; $i++) {
    $code = AccountService::generateCode(8);

    if (strlen($code) !== 8) {
        $clean = false;
        break;
    }

    foreach ($banned as $char) {
        if (str_contains($code, $char)) {
            $clean = false;
            break 2;
        }
    }
}

is('400 generated codes contain none of 0 o O i I L l 1', $clean, true);

// =====================================================================
section('Creating a tournament');

$t = $tournaments->create([
    'name'                      => 'Alappuzha Premier League',
    'season_year'               => 2026,
    'auction_date'              => '2026-09-01',
    'start_date'                => '2026-09-15',
    'end_date'                  => '2026-10-20',
    'team_name_change_deadline' => '2026-09-10',
    'purse_per_team'            => '5000000',
    'min_squad_size'            => 11,
    'max_squad_size'            => 15,
    'bid_increment'             => '50000',
]);

$TID = (int) $t['id'];

is('the tournament is created',        $t['name'],                      'Alappuzha Premier League');
is('with a secret code',               strlen((string) $t['secret_code']), 8);
is('the auction date is stored',       $t['auction_date'],              '2026-09-01');
is('the start date is stored',         $t['start_date'],                '2026-09-15');
is('the end date is stored',           $t['end_date'],                  '2026-10-20');
is('the rename deadline is stored',    $t['team_name_change_deadline'], '2026-09-10');
is('registration opens immediately',   (int) $t['registration_open'],   1);
is('the purse is per the form',        $t['purse_per_team'],            '5000000.00');

rejects('the same name and season cannot repeat', AccountException::NAME_TAKEN,
    fn () => $tournaments->create(['name' => 'Alappuzha Premier League', 'season_year' => 2026]));

rejects('the end date cannot precede the start', AccountException::VALIDATION,
    fn () => $tournaments->create([
        'name' => 'Backwards Cup', 'season_year' => 2026,
        'start_date' => '2026-09-15', 'end_date' => '2026-09-01',
    ]));

rejects('the auction cannot be after the first ball', AccountException::VALIDATION,
    fn () => $tournaments->create([
        'name' => 'Late Auction Cup', 'season_year' => 2026,
        'start_date' => '2026-09-15', 'auction_date' => '2026-09-20',
    ]));

rejects('the rename deadline cannot outlast the season', AccountException::VALIDATION,
    fn () => $tournaments->create([
        'name' => 'Loose Ends Cup', 'season_year' => 2026,
        'end_date' => '2026-10-20', 'team_name_change_deadline' => '2026-11-01',
    ]));

rejects('a date that is not a date is refused', AccountException::VALIDATION,
    fn () => $tournaments->create([
        'name' => 'Nonsense Cup', 'season_year' => 2026, 'start_date' => '2026-02-31',
    ]));

$edited = $tournaments->update($TID, ['end_date' => '2026-10-25']);
is('an administrator can move a date',   $edited['end_date'], '2026-10-25');
is('without disturbing the others',      $edited['start_date'], '2026-09-15');

rejects('and cannot move one past another', AccountException::VALIDATION,
    fn () => $tournaments->update($TID, ['start_date' => '2026-11-01']));

$newCode = $tournaments->regenerateSecretCode($TID);
is('the code can be re-issued', $newCode !== $t['secret_code'], true);

// =====================================================================
section('Applying with the secret code');

$code = $newCode;

rejects('a wrong code matches nothing', AccountException::BAD_SECRET_CODE,
    fn () => $tournaments->apply($nikhil, 'ZZZZZZZZ'));

rejects('an empty code is refused', AccountException::BAD_SECRET_CODE,
    fn () => $tournaments->apply($nikhil, '   '));

is('the code is read case- and space-insensitively',
    (int) $tournaments->findByCode(strtolower(substr($code, 0, 4) . ' ' . substr($code, 4)))['id'],
    $TID);

rejects('an unapproved player cannot apply', AccountException::NOT_APPROVED,
    fn () => $tournaments->apply($other, $code));

$application = $tournaments->apply($nikhil, $code);
is('an approved player can apply',  $application['status'], 'pending');
is('to the right tournament',       $application['tournament_id'], $TID);

rejects('and cannot apply twice', AccountException::ALREADY_APPLIED,
    fn () => $tournaments->apply($nikhil, $code));

is('the application is in nobody\'s auction yet',
    (int) Database::scalar('SELECT COUNT(*) FROM players WHERE tournament_id = :t', [':t' => $TID]), 0);

$queue = $tournaments->applications($TID);
is('the administrator sees one application', count($queue), 1);
is('with the details needed to judge it',    $queue[0]['player_type'], 'wicket_keeper');

// =====================================================================
section('Approval is what puts a player in the auction');

$approved = $tournaments->decideApplication((int) $queue[0]['id'], true, $ADMIN, 'Known to the club');

is('the application is approved', $approved['status'], 'approved');
is('a player record now exists',  $approved['player_id'] > 0, true);
is('and a lot to bid on',         $approved['lot_id'] > 0, true);

$player = Database::one('SELECT * FROM players WHERE id = :id', [':id' => $approved['player_id']]);
is('the player carries the registered name',  $player['full_name'],   'Nikhil S Rao');
is('linked back to the account',              (int) $player['user_id'], $nikhil);
is('with the kind of player they registered', $player['role'],        'wicket_keeper');
is('and is available for auction',            $player['status'],      'available');

$lot = Database::one('SELECT * FROM auction_lots WHERE id = :id', [':id' => $approved['lot_id']]);
is('the lot is queued',                 $lot['status'], 'queued');
is('at the back of the queue',          (int) $lot['lot_order'], 1);
is('with the base price of the player', $lot['base_price'], $player['base_price']);

rejects('the same application cannot be decided twice', AccountException::ALREADY_DECIDED,
    fn () => $tournaments->decideApplication((int) $queue[0]['id'], true, $ADMIN));

// A rejected applicant gets nothing, and may try again.
$accounts->decideRegistration($other, true, $ADMIN);
$vikramApp = $tournaments->apply($other, $code);
$rejected  = $tournaments->decideApplication((int) $vikramApp['registration_id'], false, $ADMIN, 'Unverified');

is('a rejected application creates no player', $rejected['status'], 'rejected');
is('so the auction pool is unchanged',
    (int) Database::scalar('SELECT COUNT(*) FROM players WHERE tournament_id = :t', [':t' => $TID]), 1);

$again = $tournaments->apply($other, $code);
is('a rejected player may apply again', $again['status'], 'pending');

$approvedNow = $tournaments->decideApplication((int) $again['registration_id'], true, $ADMIN);
is('and can then be let in',            $approvedNow['status'], 'approved');
is('taking the next lot in the queue',
    (int) Database::scalar('SELECT lot_order FROM auction_lots WHERE id = :id', [':id' => $approvedNow['lot_id']]), 2);

is('their application history is visible to them',
    count($tournaments->myApplications($other)), 1);

// A player who is in the pool is out of the queue.
is('the pending queue is now empty', count($tournaments->applications($TID)), 0);
is('but the full list still shows both', count($tournaments->applications($TID, 'all')), 2);

// =====================================================================
section('Registration windows');

$tournaments->update($TID, ['registration_open' => false]);

$third = $accounts->register(registration([
    'name' => 'Anand Pillai', 'email' => 'anand@club.test', 'username' => 'anand.p',
]));
$accounts->decideRegistration($third, true, $ADMIN);

rejects('nobody may apply once registration is closed', AccountException::REGISTRATION_SHUT,
    fn () => $tournaments->apply($third, $code));

$tournaments->update($TID, ['registration_open' => true]);

// Move the whole calendar into the past.
Database::exec(
    "UPDATE tournaments
        SET auction_date = DATE_SUB(CURDATE(), INTERVAL 5 DAY),
            start_date   = DATE_SUB(CURDATE(), INTERVAL 4 DAY),
            end_date     = DATE_ADD(CURDATE(), INTERVAL 20 DAY),
            team_name_change_deadline = DATE_SUB(CURDATE(), INTERVAL 3 DAY)
      WHERE id = :t",
    [':t' => $TID]
);

rejects('nobody may apply after the auction has been held', AccountException::DEADLINE_PASSED,
    fn () => $tournaments->apply($third, $code));

// Put the auction back in the future for the team tests.
Database::exec(
    "UPDATE tournaments SET auction_date = DATE_ADD(CURDATE(), INTERVAL 5 DAY) WHERE id = :t",
    [':t' => $TID]
);

// =====================================================================
section('Teams — one owner each');

$owner = $accounts->register(registration([
    'name' => 'Sarita Balan', 'email' => 'sarita@club.test', 'username' => 'sarita.b',
]));

rejects('an unapproved account cannot create a team', AccountException::NOT_APPROVED,
    fn () => $tournaments->createTeam($owner, $TID, ['name' => 'Backwater Blasters', 'short_name' => 'BWB']));

$accounts->decideRegistration($owner, true, $ADMIN);

$team = $tournaments->createTeam($owner, $TID, [
    'name' => 'Backwater Blasters', 'short_name' => 'bwb', 'primary_color' => '#0ea5e9',
]);

is('the owner names their team',   $team['name'],       'Backwater Blasters');
is('the short name is upper-cased', $team['short_name'], 'BWB');

$ownerRow = userRow($owner);
is('the account becomes a team owner', $ownerRow['role'],           'team_owner');
is('bound to that team',               (int) $ownerRow['team_id'],  $team['team_id']);
is('the purse comes from the tournament',
    Database::scalar('SELECT purse_total FROM teams WHERE id = :id', [':id' => $team['team_id']]),
    '5000000.00');

rejects('one person cannot own two teams', AccountException::ALREADY_APPLIED,
    fn () => $tournaments->createTeam($owner, $TID, ['name' => 'Second Team', 'short_name' => 'ST']));

rejects('two teams cannot share a name', AccountException::NAME_TAKEN,
    fn () => $tournaments->createTeam($third, $TID, ['name' => 'Backwater Blasters', 'short_name' => 'BB2']));

rejects('two teams cannot share a short name', AccountException::NAME_TAKEN,
    fn () => $tournaments->createTeam($third, $TID, ['name' => 'Another Team', 'short_name' => 'BWB']));

rejects('a short name must look like one', AccountException::VALIDATION,
    fn () => $tournaments->createTeam($third, $TID, ['name' => 'Another Team', 'short_name' => 'TOO LONG']));

// The database is the real guard on one-owner-per-team, not just the service.
$duplicate = false;

try {
    Database::exec(
        "UPDATE users SET role = 'team_owner', team_id = :t WHERE id = :u",
        [':t' => $team['team_id'], ':u' => $third]
    );
} catch (Throwable $e) {
    $duplicate = str_contains($e->getMessage(), 'uq_users_owner_team');
}

is('the database itself refuses a second owner for one team', $duplicate, true);

// An owner may also be a player: they applied and were approved like anyone.
$ownerApp = $tournaments->apply($owner, $code);
$ownerLet = $tournaments->decideApplication((int) $ownerApp['registration_id'], true, $ADMIN);
is('a team owner may also enter the auction as a player', $ownerLet['status'], 'approved');
is('and appears in the pool',
    (int) Database::scalar(
        'SELECT COUNT(*) FROM players WHERE tournament_id = :t AND user_id = :u',
        [':t' => $TID, ':u' => $owner]
    ), 1);

// =====================================================================
section('Renaming a team, up to the deadline');

Database::exec(
    "UPDATE tournaments SET team_name_change_deadline = DATE_ADD(CURDATE(), INTERVAL 3 DAY) WHERE id = :t",
    [':t' => $TID]
);

is('the window is open', $tournaments->canRenameTeam($TID), true);

$renamed = $tournaments->renameTeam($team['team_id'], $owner, ['name' => 'Backwater Warriors']);
is('the owner renames their team', $renamed['name'], 'Backwater Warriors');

$renamed = $tournaments->renameTeam($team['team_id'], $owner, ['short_name' => 'BWW']);
is('and can change the short name too', $renamed['short_name'], 'BWW');

// A second team in the *same* tournament — names only have to be unique
// within one, so a clash with another season's team is not a clash.
Database::exec(
    "INSERT INTO teams (tournament_id, name, short_name, purse_total)
     VALUES (:t, 'Harbour Hawks', 'HRH', 5000000.00)",
    [':t' => $TID]
);

$renamedAgain = $tournaments->renameTeam($team['team_id'], $owner, ['name' => 'Titan Strikers']);
is('a name used in another tournament is free here', $renamedAgain['name'], 'Titan Strikers');

rejects('but not one held in this tournament', AccountException::NAME_TAKEN,
    fn () => $tournaments->renameTeam($team['team_id'], $owner, ['name' => 'Harbour Hawks']));

rejects('and nobody else may rename it', AccountException::NOT_YOUR_TEAM,
    fn () => $tournaments->renameTeam($team['team_id'], $third, ['name' => 'Hijacked XI']));

// Today is the deadline: still allowed.
Database::exec(
    'UPDATE tournaments SET team_name_change_deadline = CURDATE() WHERE id = :t',
    [':t' => $TID]
);
$sameDay = $tournaments->renameTeam($team['team_id'], $owner, ['name' => 'Backwater Kings']);
is('the deadline day itself still counts', $sameDay['name'], 'Backwater Kings');

// Yesterday: closed.
Database::exec(
    'UPDATE tournaments SET team_name_change_deadline = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = :t',
    [':t' => $TID]
);

is('the window has closed', $tournaments->canRenameTeam($TID), false);

rejects('the owner can no longer rename', AccountException::DEADLINE_PASSED,
    fn () => $tournaments->renameTeam($team['team_id'], $owner, ['name' => 'Too Late XI']));

$byAdmin = $tournaments->renameTeam($team['team_id'], $ADMIN, ['name' => 'Backwater Royals'], actorIsAdmin: true);
is('an administrator still can', $byAdmin['name'], 'Backwater Royals');

// =====================================================================
section('Handing a team to a different owner');

$handover = $tournaments->assignOwner($team['team_id'], $third);
is('the new owner holds the team', (int) userRow($third)['team_id'], $team['team_id']);
is('with the owner role',          userRow($third)['role'],          'team_owner');
is('the previous owner is released', userRow($owner)['team_id'],     null);
is('and is no longer an owner',      userRow($owner)['role'],        'viewer');
is('the handover names both',        $handover['owner_id'],          $third);

$listed = $tournaments->teams($TID);
$mine   = array_values(array_filter($listed, static fn ($r) => (int) $r['id'] === $team['team_id']))[0];
is('the team list carries its owner', (int) $mine['owner_id'], $third);
is('and shows a team with no owner as such',
    array_values(array_filter($listed, static fn ($r) => $r['name'] === 'Harbour Hawks'))[0]['owner_id'], null);

// =====================================================================
section('The tournament list an administrator sees');

$all = $tournaments->listTournaments();
$apl = array_values(array_filter($all, static fn ($r) => (int) $r['id'] === $TID))[0];

is('it counts teams',    (int) $apl['team_count'],   2);
is('it counts players',  (int) $apl['player_count'], 3);
is('and pending applications', (int) $apl['pending_count'], 0);

// ---------------------------------------------------------------------

echo "\n" . str_repeat('─', 60) . "\n";
printf("  \033[32m%d passed\033[0m", $passed);
echo $failed > 0 ? sprintf(", \033[31m%d failed\033[0m\n\n", $failed) : "\n\n";

exit($failed > 0 ? 1 : 0);
