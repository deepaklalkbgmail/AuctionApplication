<?php

declare(strict_types=1);

/**
 * Integration tests for ScoringService — run against a real MySQL/MariaDB.
 *
 *     php tests/scoring_test.php
 *
 * Reloads schema.sql + seed.sql + seed_match.sql, so it is destructive to
 * the `cric_auction` database and safe to re-run.
 */

require_once dirname(__DIR__) . '/config/config.php';

use App\Exceptions\ScoringException;
use App\Services\ScoringService;

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
    } catch (ScoringException $e) {
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

    foreach (['schema.sql', 'seed.sql', 'seed_match.sql'] as $file) {
        $pdo->exec((string) file_get_contents(BASE_PATH . '/database/' . $file));
    }
}

// ---------------------------------------------------------------------

echo "\n\033[1mScoringService integration tests\033[0m\n";

resetDatabase();

$s  = new ScoringService();
$IN = 1;                                  // innings 1

// Titan Strikers XI: 11 A Rathore (1), 9 L Carter (2), 14 R Iyer (3) …
// Royal Chargers bowlers: 26 A Sequeira, 29 E Mwangi, 21 G Pillai …
const RATHORE = 11, CARTER = 9, IYER = 14, KAUL = 19;
const SEQUEIRA = 26, MWANGI = 29, PILLAI = 21;

section('Opening the innings');

rejects('first ball needs the opening pair', ScoringException::NEEDS_OPENING,
    fn () => $s->recordBall($IN, ['runs_off_bat' => 1]));

rejects('a batter from the wrong squad is refused', ScoringException::NOT_IN_SQUAD,
    fn () => $s->recordBall($IN, [
        'runs_off_bat' => 1,
        'striker_id' => SEQUEIRA, 'non_striker_id' => CARTER, 'bowler_id' => SEQUEIRA,
    ]));

rejects('one player cannot hold both ends', ScoringException::SAME_BATTER,
    fn () => $s->recordBall($IN, [
        'runs_off_bat' => 1,
        'striker_id' => RATHORE, 'non_striker_id' => RATHORE, 'bowler_id' => SEQUEIRA,
    ]));

$open = ['striker_id' => RATHORE, 'non_striker_id' => CARTER, 'bowler_id' => SEQUEIRA];

$r = $s->recordBall($IN, ['runs_off_bat' => 1] + $open);
is('first ball recorded', $r['innings']['total_runs'], 1);
is('legal ball counted', $r['innings']['legal_balls'], 1);
is('overs shown as 0.1', $r['innings']['overs'], '0.1');
is('single rotated the strike', $r['state']['striker_id'], CARTER);
is('non-striker is the opener', $r['state']['non_striker_id'], RATHORE);

section('Runs, boundaries and the innings cache');

$s->recordBall($IN, ['runs_off_bat' => 4]);
$r = $s->recordBall($IN, ['runs_off_bat' => 6]);

is('runs accumulate', $r['innings']['total_runs'], 11);
is('boundary flagged', $r['balls'][1]['is_four'], true);
is('six flagged', $r['balls'][2]['is_six'], true);
is('even runs keep the strike', $r['state']['striker_id'], CARTER);

$cached = Database::one('SELECT total_runs, legal_balls FROM innings WHERE id = 1');
is('innings cache matches the log', (int) $cached['total_runs'], 11);
is('cache legal balls match', (int) $cached['legal_balls'], 3);

section('Extras');

$r = $s->recordBall($IN, ['extra_type' => 'wide', 'extra_runs' => 1]);
is('a wide adds a run', $r['innings']['total_runs'], 12);
is('a wide is not a legal ball', $r['innings']['legal_balls'], 3);
is('wide extras tracked', $r['innings']['extras_wide'], 1);

$r = $s->recordBall($IN, ['extra_type' => 'no_ball', 'extra_runs' => 1, 'runs_off_bat' => 4]);
is('no-ball adds penalty plus the bat', $r['innings']['total_runs'], 17);
is('no-ball is not a legal ball', $r['innings']['legal_balls'], 3);

$r = $s->recordBall($IN, ['extra_type' => 'leg_bye', 'extra_runs' => 2]);
is('leg byes count as a legal ball', $r['innings']['legal_balls'], 4);
is('leg byes recorded as extras', $r['innings']['extras_leg_bye'], 2);
is('even leg byes keep the strike', $r['state']['striker_id'], CARTER);

rejects('a wide cannot have runs off the bat', ScoringException::BAD_BALL,
    fn () => $s->recordBall($IN, ['extra_type' => 'wide', 'extra_runs' => 1, 'runs_off_bat' => 2]));

rejects('a no-ball must carry its penalty', ScoringException::BAD_BALL,
    fn () => $s->recordBall($IN, ['extra_type' => 'no_ball', 'extra_runs' => 0]));

section('Completing the over');

$s->recordBall($IN, ['runs_off_bat' => 0]);
$r = $s->recordBall($IN, ['runs_off_bat' => 0]);

is('six legal balls bowled', $r['innings']['legal_balls'], 6);
is('overs shown as 1.0', $r['innings']['overs'], '1.0');
is('ends changed at the over', $r['state']['striker_id'], RATHORE);
is('a new bowler is required', $r['state']['needs_bowler'], true);

rejects('no ball can be bowled until a bowler is named', ScoringException::NEEDS_BOWLER,
    fn () => $s->recordBall($IN, ['runs_off_bat' => 1]));

rejects('the same bowler cannot bowl consecutive overs', ScoringException::CONSECUTIVE_OVERS,
    fn () => $s->recordBall($IN, ['runs_off_bat' => 1, 'bowler_id' => SEQUEIRA]));

$r = $s->recordBall($IN, ['runs_off_bat' => 1, 'bowler_id' => MWANGI]);
is('the new over starts', $r['innings']['overs'], '1.1');
is('new bowler took the ball', $r['balls'][count($r['balls']) - 1]['bowler_id'], MWANGI);

section('Wickets');

$r = $s->recordBall($IN, ['is_wicket' => true, 'dismissal_type' => 'bowled']);
is('wicket counted', $r['innings']['total_wickets'], 1);
is('a new batter is required', $r['state']['needs_batter'], true);
is('the vacant end is empty', $r['state']['striker_id'], null);

rejects('no ball until the new batter is named', ScoringException::NEEDS_BATTER,
    fn () => $s->recordBall($IN, ['runs_off_bat' => 1]));

rejects('a dismissed batter cannot come back', ScoringException::ALREADY_OUT,
    fn () => $s->recordBall($IN, ['runs_off_bat' => 1, 'new_batter_id' => CARTER]));

rejects('a batter already at the crease cannot walk in again', ScoringException::SAME_BATTER,
    fn () => $s->recordBall($IN, ['runs_off_bat' => 1, 'new_batter_id' => RATHORE]));

rejects('only a run-out can dismiss the non-striker', ScoringException::BAD_BALL,
    fn () => $s->recordBall($IN, [
        'is_wicket' => true, 'dismissal_type' => 'bowled',
        'dismissed_player_id' => CARTER, 'new_batter_id' => IYER,
    ]));

$r = $s->recordBall($IN, ['runs_off_bat' => 2, 'new_batter_id' => IYER]);
is('the new batter took the vacant end', $r['balls'][count($r['balls']) - 1]['striker_id'], IYER);
is('runs on that ball counted too', $r['innings']['total_runs'], 22);

section('Run out at the non-striker end');

$before = $r['state']['non_striker_id'];
$r = $s->recordBall($IN, [
    'is_wicket' => true, 'dismissal_type' => 'run_out',
    'dismissed_player_id' => $before, 'fielder_id' => PILLAI, 'runs_off_bat' => 1,
]);
is('second wicket recorded', $r['innings']['total_wickets'], 2);
is('the fielder is credited', $r['balls'][count($r['balls']) - 1]['fielder_id'], PILLAI);

$s->recordBall($IN, ['runs_off_bat' => 0, 'new_batter_id' => KAUL]);

section('Undo');

$snapshot = $s->scorecard($IN);
$countBefore = count($snapshot['balls']);
$runsBefore  = $snapshot['innings']['total_runs'];

$s->recordBall($IN, ['runs_off_bat' => 4]);
$after = $s->scorecard($IN);
is('the ball landed', $after['innings']['total_runs'], $runsBefore + 4);

$undone = $s->undoLastBall($IN);
is('undo removed the ball', count($undone['balls']), $countBefore);
is('undo restored the total', $undone['innings']['total_runs'], $runsBefore);
is('undo restored the strike', $undone['state']['striker_id'], $snapshot['state']['striker_id']);
is('undo restored the cache',
    (int) Database::scalar('SELECT total_runs FROM innings WHERE id = 1'), $runsBefore);

section('Scorecard payload');

$card = $s->scorecard($IN);
is('both XIs are returned', count($card['squads']['batting']) . '/' . count($card['squads']['bowling']), '11/11');
is('batting side is the Titan Strikers', $card['innings']['batting_short'], 'TS');
is('every ball is in the log', count($card['balls']) > 10, true);
is('innings still open', $card['innings']['is_completed'], false);

section('Closing the innings');

// Ten wickets ends it regardless of overs left.
Database::exec('UPDATE innings SET total_wickets = 10, is_completed = 1 WHERE id = 1');
rejects('a closed innings takes no more balls', ScoringException::INNINGS_CLOSED,
    fn () => $s->recordBall($IN, ['runs_off_bat' => 1]));
Database::exec('UPDATE innings SET is_completed = 0 WHERE id = 1');

Database::exec("UPDATE matches SET status = 'completed' WHERE id = 1");
rejects('a finished match takes no more balls', ScoringException::MATCH_NOT_LIVE,
    fn () => $s->recordBall($IN, ['runs_off_bat' => 1]));

// ---------------------------------------------------------------------

echo "\n" . str_repeat('─', 60) . "\n";
printf("  \033[32m%d passed\033[0m", $passed);
echo $failed > 0 ? sprintf(", \033[31m%d failed\033[0m\n\n", $failed) : "\n\n";

exit($failed > 0 ? 1 : 0);
