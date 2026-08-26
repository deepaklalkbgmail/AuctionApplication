<?php

declare(strict_types=1);

/**
 * Put something in every queue, so the guide screenshots are not pictures
 * of empty screens.
 *
 *     mysql -u root cric_auction < database/schema.sql
 *     mysql -u root cric_auction < database/reset.sql
 *     mysql -u root cric_auction < database/demo_apl.sql
 *     php docs/seed-guide-data.php
 *
 * Adds one registration waiting for approval and two tournament
 * applications waiting for a decision, then gives every account the same
 * password so docs/capture-screens.mjs can sign in as each role in turn.
 *
 * Only ever run this against a scratch database. It rewrites every
 * password in the users table.
 */

require_once dirname(__DIR__) . '/config/config.php';

use App\Services\AccountService;
use App\Services\TournamentService;

const GUIDE_PASSWORD = 'Guide2026x';

$accounts    = new AccountService();
$tournaments = new TournamentService();

$adminId = (int) Database::scalar("SELECT id FROM users WHERE role = 'admin' ORDER BY id DESC LIMIT 1");

if ($adminId === 0) {
    fwrite(STDERR, "No administrator found. Load database/demo_apl.sql first.\n");
    exit(1);
}

$code = (string) Database::scalar('SELECT secret_code FROM tournaments ORDER BY id LIMIT 1');

if ($code === '') {
    fwrite(STDERR, "The tournament has no secret code. Load database/demo_apl.sql first.\n");
    exit(1);
}

/** @param array<string,string> $in */
function person(array $in): array
{
    return $in + ['password' => GUIDE_PASSWORD, 'password_confirm' => GUIDE_PASSWORD];
}

// One registration still waiting — the People queue.
$accounts->register(person([
    'name' => 'Arun Prasad', 'email' => 'arun.prasad@club.test', 'username' => 'arun.prasad',
    'phone' => '9847012345', 'address' => '18 Beach Road, Alappuzha', 'player_type' => 'bowler',
]));

// Two approved players whose applications are waiting — the Applications queue.
foreach ([
    ['Nikhil Rao',   'nikhil.rao@club.test',   'nikhil.rao',   '9876543210', '22 Fort Road, Kochi',      'batting_all_rounder'],
    ['Sarita Balan', 'sarita.balan@club.test', 'sarita.balan', '9847098765', '7 Lake View, Kumarakom',   'wicket_keeper'],
] as [$name, $email, $username, $phone, $address, $type]) {
    $id = $accounts->register(person([
        'name' => $name, 'email' => $email, 'username' => $username,
        'phone' => $phone, 'address' => $address, 'player_type' => $type,
    ]));

    $accounts->decideRegistration($id, true, $adminId);
    $tournaments->apply($id, $code);
}

// One password for every account, so the capture script stays simple, and
// no forced change standing between it and the screen it wants to photograph.
Database::exec(
    'UPDATE users SET password_hash = :hash, must_change_password = 0',
    [':hash' => password_hash(GUIDE_PASSWORD, PASSWORD_BCRYPT, ['cost' => 12])]
);

printf(
    "Ready. %d waiting to be approved, %d applications waiting. Code %s, password %s\n",
    (int) Database::scalar("SELECT COUNT(*) FROM users WHERE status = 'pending'"),
    (int) Database::scalar("SELECT COUNT(*) FROM tournament_registrations WHERE status = 'pending'"),
    $code,
    GUIDE_PASSWORD
);
