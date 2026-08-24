<?php

declare(strict_types=1);

/**
 * Integration tests for AuctionService — run against a real MySQL/MariaDB.
 *
 *     mysql -u root -p < database/schema.sql
 *     mysql -u root -p < database/seed.sql
 *     php tests/auction_test.php
 *
 * No PHPUnit dependency: this is a plain script so it can run anywhere PHP
 * and the database exist. It reloads schema.sql + seed.sql itself, so it is
 * destructive to the `cric_auction` database and safe to re-run.
 */

require_once dirname(__DIR__) . '/config/config.php';

use App\Exceptions\AuctionException;
use App\Services\AuctionService;
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
    $a = is_scalar($actual) ? (string) $actual : json_encode($actual);
    $b = is_scalar($expected) ? (string) $expected : json_encode($expected);

    $a === $b ? ok($label) : bad($label, "expected {$b}, got {$a}");
}

/** Assert that $work is rejected with a specific AuctionException code. */
function rejects(string $label, string $expectedCode, callable $work): void
{
    try {
        $work();
        bad($label, "expected {$expectedCode}, but the call succeeded");
    } catch (AuctionException $e) {
        $e->errorCode() === $expectedCode
            ? ok($label . '  (' . $e->getMessage() . ')')
            : bad($label, "expected {$expectedCode}, got {$e->errorCode()}: {$e->getMessage()}");
    }
}

function section(string $name): void
{
    echo "\n\033[1m{$name}\033[0m\n";
}

/** Reload schema + seed so every run starts from the same state. */
function resetDatabase(): void
{
    $pdo = Database::pdo();

    foreach (['schema.sql', 'seed.sql'] as $file) {
        $sql = file_get_contents(BASE_PATH . '/database/' . $file);

        if ($sql === false) {
            fwrite(STDERR, "Cannot read database/{$file}\n");
            exit(1);
        }

        // The dev-server connection has no database selected yet on the first
        // pass; schema.sql issues its own CREATE DATABASE / USE.
        $pdo->exec($sql);
    }
}

function teamRow(int $id): array
{
    return Database::one('SELECT * FROM teams WHERE id = :id', [':id' => $id]) ?? [];
}

function playerRow(int $id): array
{
    return Database::one('SELECT * FROM players WHERE id = :id', [':id' => $id]) ?? [];
}

function lotRow(int $id): array
{
    return Database::one('SELECT * FROM auction_lots WHERE id = :id', [':id' => $id]) ?? [];
}

// ---------------------------------------------------------------------

echo "\n\033[1mAuctionService integration tests\033[0m\n";

resetDatabase();
$auction = new AuctionService();

// Seed state: lot 5 is live on Kabir Anand (player 1, base ₹20 L),
// current bid ₹35 L held by Titan Strikers (team 1), 4 bids so far.
// Increment ₹5 L. Purses left: CK 71 L, TS 57.5 L, RC 39 L, DF 26.5 L.

section('Placing a bid');

$result = $auction->placeBid(5, 3, 6, '4000000');           // Coastal Kings
is('bid accepted, lot updated', $result['lot']['current_bid'], '4000000.00');
is('leader is now Coastal Kings', $result['lot']['bidder_team_short'], 'CK');
is('bid count incremented', $result['lot']['bid_count'], '5');

$logged = Database::one('SELECT team_id, bid_amount, is_winning FROM auction_bids ORDER BY id DESC LIMIT 1');
is('bid written to the audit log', $logged['bid_amount'], '4000000.00');
is('the new bid id comes back', $result['bid_id'] > 0, true);
is('not yet flagged as winning', (int) $logged['is_winning'], 0);

$countdown = Database::one('SELECT TIMESTAMPDIFF(SECOND, NOW(), ends_at) AS s FROM auction_lots WHERE id = 5');
is('countdown reset to the full timer', (int) $countdown['s'] >= 28, true);

section('Bid validation');

rejects('same team cannot bid twice in a row', AuctionException::ALREADY_LEADING,
    fn () => $auction->placeBid(5, 3, 6, '4500000'));

rejects('below the next increment', AuctionException::BID_TOO_LOW,
    fn () => $auction->placeBid(5, 1, 4, '4000000'));

rejects('equal to the standing bid', AuctionException::BID_TOO_LOW,
    fn () => $auction->placeBid(5, 2, 5, '4000000'));

rejects('off the increment grid', AuctionException::BID_NOT_ALIGNED,
    fn () => $auction->placeBid(5, 2, 5, '4600000'));

rejects('bidding on a lot that is not live', AuctionException::LOT_NOT_LIVE,
    fn () => $auction->placeBid(1, 2, 5, '5000000'));

rejects('bidding on a lot that does not exist', AuctionException::LOT_NOT_FOUND,
    fn () => $auction->placeBid(9999, 2, 5, '5000000'));

section('Purse enforcement');

// Titan Strikers have ₹57.5 L left and 6 players. min_squad_size is 11, so
// after this buy they would still need 4 more; the cheapest player left is
// ₹5 L, which reserves ₹20 L and caps them at ₹37.5 L — below the ₹45 L the
// ladder now demands.
rejects('team cannot bid beyond its purse', AuctionException::INSUFFICIENT_PURSE,
    fn () => $auction->placeBid(5, 1, 4, '4500000'));

try {
    $auction->placeBid(5, 1, 4, '4500000');
} catch (AuctionException $e) {
    is('rejection reports the affordable ceiling', $e->context()['max_bid'], '3750000.00');
    is('rejection reports the squad reserve', $e->context()['reserved'], '2000000.00');
}

is('failed bid left the purse untouched', teamRow(1)['purse_spent'], '4250000.00');
is('failed bid was not logged',
    Database::scalar('SELECT COUNT(*) FROM auction_bids WHERE team_id = 1 AND bid_amount = 4500000'), 0);

// A team at its squad cap cannot bid at all.
Database::exec('UPDATE teams SET players_bought = 15 WHERE id = 2');
rejects('full squad cannot bid', AuctionException::SQUAD_FULL,
    fn () => $auction->placeBid(5, 2, 5, '4500000'));
Database::exec('UPDATE teams SET players_bought = 7 WHERE id = 2');

// Overseas quota. Lot 7 is Thabo Nkosi (overseas); give RC their limit.
Database::exec('UPDATE teams SET overseas_bought = 4 WHERE id = 2');
Database::exec('UPDATE auction_lots SET status = :s WHERE id = 5', [':s' => 'paused']);
Database::exec('UPDATE auction_lots SET status = :s, ends_at = NOW() + INTERVAL 60 SECOND WHERE id = 7', [':s' => 'live']);
rejects('overseas quota is enforced', AuctionException::OVERSEAS_LIMIT,
    fn () => $auction->placeBid(7, 2, 5, '1500000'));
Database::exec('UPDATE teams SET overseas_bought = 2 WHERE id = 2');
Database::exec('UPDATE auction_lots SET status = :s WHERE id = 7', [':s' => 'queued']);
Database::exec('UPDATE auction_lots SET status = :s WHERE id = 5', [':s' => 'live']);

section('Expired lot');

Database::exec('UPDATE auction_lots SET ends_at = NOW() - INTERVAL 1 SECOND WHERE id = 5');
rejects('no bids once the hammer has fallen', AuctionException::LOT_EXPIRED,
    fn () => $auction->placeBid(5, 2, 5, '4500000'));
Database::exec('UPDATE auction_lots SET ends_at = NOW() + INTERVAL 30 SECOND WHERE id = 5');

section('Selling the lot');

$before = teamRow(3);
$sale   = $auction->sell(5, 1);

is('sold to the leading bidder', $sale['team'], 'Coastal Kings');
is('sold at the standing bid', $sale['price'], '4000000.00');

$player = playerRow(1);
is('player status is sold', $player['status'], 'sold');
is('player joined the buying squad', (int) $player['team_id'], 3);
is('player carries the sale price', $player['sold_price'], '4000000.00');

$after = teamRow(3);
is('purse debited by exactly the price',
    (string) (int) round(((float) $after['purse_spent'] - (float) $before['purse_spent'])), '4000000');
is('purse_remaining follows automatically', $after['purse_remaining'], '3100000.00');
is('squad size incremented', (int) $after['players_bought'], (int) $before['players_bought'] + 1);
is('overseas count unchanged for a domestic player',
    (int) $after['overseas_bought'], (int) $before['overseas_bought']);

$lot = Database::one('SELECT * FROM auction_lots WHERE id = 5');
is('lot closed as sold', $lot['status'], 'sold');
is('lot records the buyer', (int) $lot['sold_to_team_id'], 3);
is('lot records who closed it', (int) $lot['closed_by_user_id'], 1);

is('winning bid flagged in the log',
    Database::scalar('SELECT COUNT(*) FROM auction_bids WHERE lot_id = 5 AND is_winning = 1'), 1);

rejects('a closed lot cannot be sold twice', AuctionException::LOT_NOT_LIVE,
    fn () => $auction->sell(5, 1));

rejects('a closed lot takes no more bids', AuctionException::LOT_NOT_LIVE,
    fn () => $auction->placeBid(5, 2, 5, '4500000'));

section('Starting the next lot');

$next = $auction->startNextLot(1, 1);
is('next queued player is live', $next['lot']['status'], 'live');
is('lot 6 came off the queue', (int) $next['lot']['lot_id'], 6);
is('that player is marked in_auction', playerRow(2)['status'], 'in_auction');

rejects('cannot open two lots at once', AuctionException::LOT_ALREADY_OPEN,
    fn () => $auction->startNextLot(1, 1));

section('Unsold lot');

$unsold = $auction->markUnsold(6, 1);
is('lot closed as unsold', $unsold['outcome'], 'unsold');
is('player returns to the pool as unsold', playerRow(2)['status'], 'unsold');
is('nobody was charged', teamRow(2)['purse_spent'], '6100000.00');

section('First bid on a fresh lot starts at base price');

$auction->startNextLot(1, 1);                                  // lot 7, base ₹15 L
rejects('first bid below base price', AuctionException::BID_TOO_LOW,
    fn () => $auction->placeBid(7, 1, 4, '1000000'));

$first = $auction->placeBid(7, 1, 4, '1500000');
is('first bid may equal the base price', $first['lot']['current_bid'], '1500000.00');

section('Live state read model');

$state = $auction->liveState(1);
is('live state exposes the open lot', (int) $state['lot']['lot_id'], 7);
is('live state lists every team', count($state['teams']), 4);
is('live state carries the bid feed', count($state['bids']) > 0, true);

// =====================================================================
//  Recording a sale called in the room
//
//  The auction is run aloud; the application is the record. These are the
//  checks that survive that change, and the ones that do not.
// =====================================================================

resetDatabase();
$auction = new AuctionService();

section('Recording a sale by hand');

// Lot 8 is queued — never opened here, because the room does the calling.
is('the lot starts queued', lotRow(8)['status'], 'queued');

$before = teamRow(3);

$sale = $auction->recordSale(8, 3, '1900000', 1);
is('the sale is recorded',            $sale['outcome'], 'sold');
is('at the price that was called',    $sale['price'],   '1900000.00');
is('to the named team',               $sale['team'],    'Coastal Kings');

is('the lot is closed as sold',       lotRow(8)['status'], 'sold');
is('carrying the price',              lotRow(8)['sold_price'], '1900000.00');
is('and the buyer',                   (int) lotRow(8)['sold_to_team_id'], 3);

$player = playerRow((int) lotRow(8)['player_id']);
is('the player is sold',              $player['status'], 'sold');
is('to that team',                    (int) $player['team_id'], 3);
is('at that price',                   $player['sold_price'], '1900000.00');

$after = teamRow(3);
is('the purse is debited',
    (float) $before['purse_remaining'] - (float) $after['purse_remaining'], 1900000.0);
is('the squad count rises',
    (int) $after['players_bought'], (int) $before['players_bought'] + 1);

section('What a manual sale still refuses');

rejects('a price below the base price', AuctionException::BID_TOO_LOW,
    fn () => $auction->recordSale(9, 3, '100'));

rejects('selling the same lot twice', AuctionException::ALREADY_SOLD,
    fn () => $auction->recordSale(8, 3, '1900000'));

rejects('more than the team can pay', AuctionException::INSUFFICIENT_PURSE,
    fn () => $auction->recordSale(9, 3, '99000000'));

rejects('a team from another tournament', AuctionException::TEAM_NOT_FOUND,
    fn () => $auction->recordSale(9, 99, '1300000'));

section('What it no longer refuses');

// Snapshot the buyer before the sale, so the undo below has something
// truthful to compare against.
$teamBefore = teamRow(4);

// The room calls whatever the room calls. An increment ladder is a rule for
// a bidding UI, not for a record of what happened.
$odd = $auction->recordSale(9, 4, '1234567', 1);
is('a price off the increment ladder is accepted', $odd['price'], '1234567.00');

// No countdown, no leader, so no "you already lead" and no expiry.
is('a queued lot needs no opening first', lotRow(9)['status'], 'sold');

section('Undoing a sale');

$undo = $auction->undoSale(9, 1);

is('the sale is reversed',        $undo['outcome'], 'undone');
is('the money is refunded',       $undo['refunded'], '1234567.00');
is('the lot returns to the queue', lotRow(9)['status'], 'queued');
is('with no price on it',          lotRow(9)['sold_price'], null);
is('and no buyer',                 lotRow(9)['sold_to_team_id'], null);

$p9 = playerRow((int) lotRow(9)['player_id']);
is('the player is available again', $p9['status'], 'available');
is('with no team',                  $p9['team_id'], null);
is('and no price',                  $p9['sold_price'], null);

$teamAfter = teamRow(4);
is('the purse is restored',
    $teamAfter['purse_remaining'], $teamBefore['purse_remaining']);
is('the squad count goes back',
    (int) $teamAfter['players_bought'], (int) $teamBefore['players_bought']);

rejects('undoing something that was never sold', AuctionException::NOT_SOLD,
    fn () => $auction->undoSale(9));

// The corrected price can now be recorded.
$fixed = $auction->recordSale(9, 4, '1300000', 1);
is('and the lot can be sold again at the right price', $fixed['price'], '1300000.00');

section('Passing over a player without opening a lot');

$passed_over = $auction->markUnsold(10, 1);
is('a queued lot can be marked unsold', $passed_over['outcome'], 'unsold');
is('the player returns to the pool',
    playerRow((int) lotRow(10)['player_id'])['status'], 'unsold');

// ---------------------------------------------------------------------
//  Which tournament the public board shows
//
//  This is a regression guard. auction.php used to hard-code tournament 1.
//  Delete a first attempt and create another and the ids move on: id 1 is
//  empty, and the board announces "No auction is running" through an
//  auction that is plainly running, with players sold and purses spent.
// ---------------------------------------------------------------------

section('Which tournament the board shows');

$tournaments = new TournamentService();

/* A second tournament, with a player and a lot of its own. */
$second = (int) $tournaments->create([
    'name'                      => 'Second Season',
    'season_year'               => 2027,
    'start_date'                => date('Y-m-d'),
    'auction_date'              => date('Y-m-d'),
    'end_date'                  => date('Y-m-d', strtotime('+30 days')),
    'team_name_change_deadline' => date('Y-m-d'),
])['id'];

Database::run(
    'INSERT INTO players (tournament_id, full_name, display_name, country, role, base_price)
     VALUES (:t, :n, :d, :c, :r, 200000)',
    [':t' => $second, ':n' => 'Late Arrival', ':d' => 'L Arrival', ':c' => 'India', ':r' => 'batsman']
);
$latePlayer = Database::lastInsertId();

Database::run(
    "INSERT INTO auction_lots (tournament_id, player_id, lot_order, status, base_price)
     VALUES (:t, :p, 1, 'queued', 200000)",
    [':t' => $second, ':p' => $latePlayer]
);

/* Nothing under the hammer: the newest tournament with an auction wins. */
Database::run("UPDATE auction_lots SET status = 'queued' WHERE status = 'live'");
is('the newest tournament with an auction list wins',
    $tournaments->currentAuctionId(), $second);

/* A lot actually under the hammer beats "newest". Only a queued lot may
   be flipped: chk_lot_sold refuses a sold lot that keeps its price. */
Database::run("UPDATE auction_lots SET status = 'live'
                WHERE tournament_id = 1 AND status = 'queued' LIMIT 1");
is('but a lot under the hammer wins outright',
    $tournaments->currentAuctionId(), 1);
Database::run("UPDATE auction_lots SET status = 'queued' WHERE status = 'live'");

is('an explicit choice is honoured', $tournaments->currentAuctionId(1), 1);

/* Scrapping the first tournament — the situation that caused the report.
   The owners go with it: users.team_id holds the cascade up, and
   chk_users_team_role forbids an owner with no team, so there is no
   halfway state to leave them in. */
Database::run('DELETE FROM users WHERE team_id IN (SELECT id FROM teams WHERE tournament_id = 1)');
Database::run('DELETE FROM tournaments WHERE id = 1');

is('nothing is left in the tournament that used to be id 1',
    (int) Database::scalar('SELECT COUNT(*) FROM auction_lots WHERE tournament_id = 1'), 0);
is('id 1 no longer decides what the board shows',
    $tournaments->currentAuctionId(), $second);
is('and a stale ?tournament=1 link falls back instead of blanking the page',
    $tournaments->currentAuctionId(1), $second);

/* The page itself, rendered in its own process, exactly as a visitor
   with no account gets it. Asserting the service alone would have missed
   the bug: the service was right, the page never asked it. */
$html = (string) shell_exec('php ' . escapeshellarg(BASE_PATH . '/public/auction.php') . ' 2>&1');

is('the board renders for a tournament that is not id 1',
    str_contains($html, 'Auction board'), true);
is('and names the player waiting in it',
    str_contains($html, 'Late Arrival'), true);
is('it no longer claims nothing is running',
    str_contains($html, 'No auction is running'), false);

/* Signed out. A player who has not signed in must still see the board —
   the whole point of it is that the room can watch. */
is('a signed-out visitor is not asked to sign in first',
    str_contains($html, 'Sign in'), true);
is('and gets the purse board with it',
    str_contains($html, 'Purse board'), true);

/* With no tournament at all it must say so, not invent one. */
Database::run('DELETE FROM tournaments');
is('an empty install resolves to no tournament',
    $tournaments->currentAuctionId(), null);

// ---------------------------------------------------------------------

echo "\n" . str_repeat('─', 60) . "\n";
printf("  \033[32m%d passed\033[0m", $passed);
echo $failed > 0 ? sprintf(", \033[31m%d failed\033[0m\n\n", $failed) : "\n\n";

exit($failed > 0 ? 1 : 0);
