<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  The auctioneer's sheet — the player card and the running order
 * =====================================================================
 *
 *     php tests/auction_sheet_test.php
 *
 *  Over real HTTP, signed in as an administrator, because none of what
 *  this covers lives in a service. The card is markup, the sort is a
 *  query string, and both are only true if the rendered page says so.
 *
 *  What it holds to:
 *    • every name in the pool opens a card, and the card carries the
 *      photograph the player registered with
 *    • the photograph is never cropped — the rule that guarantees that
 *      is asserted directly, because it is the one thing a face can be
 *      lost to
 *    • Marquee first really does put Marquee first, whatever order the
 *      lots were numbered in
 *    • the chosen order survives recording a sale, so an auctioneer is
 *      not thrown back to lot order between players
 *
 *  Destructive to the `cric_auction` database, like the other suites.
 */

require_once dirname(__DIR__) . '/config/config.php';

use App\Services\AccountService;
use App\Services\TournamentService;

const SHEET_PASSWORD = 'Testing2026';

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

function section(string $name): void
{
    echo "\n  \033[1m{$name}\033[0m\n";
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

    $size    = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = substr($raw, 0, $size);
    $body    = substr($raw, $size);

    curl_close($ch);

    preg_match('/^Location:\s*(.+)$/mi', $headers, $m);

    return ['status' => $status, 'location' => isset($m[1]) ? trim($m[1]) : null, 'body' => $body];
}

function csrf(string $html): string
{
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

/** The names, in the order the page lists them under Still to call. */
function poolOrder(string $html): array
{
    $pool = explode('Still to call', $html, 2)[1] ?? '';
    $pool = explode('<!-- ============================================================== SOLD', $pool, 2)[0];

    preg_match_all('/class="pc-name"[^>]*>([^<]+)</', $pool, $m);

    return $m[1];
}

/** A one-pixel JPEG on disk, standing in for a registration photograph. */
function fakePhoto(): string
{
    $dir = BASE_PATH . '/public/' . AccountService::PHOTO_DIR;
    @mkdir($dir, 0755, true);

    $name = 'test-' . bin2hex(random_bytes(6)) . '.jpg';
    $im   = imagecreatetruecolor(300, 400);
    imagejpeg($im, $dir . '/' . $name, 80);
    imagedestroy($im);

    return AccountService::PHOTO_DIR . '/' . $name;
}

// ---------------------------------------------------------------------
//  A tournament whose lot order is deliberately not its set order
// ---------------------------------------------------------------------

/** @return array{tournament:int,photo:string} */
function seed(): array
{
    $pdo = Database::pdo();

    foreach (['schema.sql', 'reset.sql'] as $file) {
        $sql = file_get_contents(BASE_PATH . '/database/' . $file);

        if ($sql === false) {
            fwrite(STDERR, "Cannot read database/{$file}\n");
            exit(1);
        }

        $pdo->exec($sql);
    }

    $accounts    = new AccountService();
    $tournaments = new TournamentService();

    $adminId = (int) Database::scalar("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");

    Database::run(
        'UPDATE users SET password_hash = :h, must_change_password = 0 WHERE id = :id',
        [':h' => password_hash(SHEET_PASSWORD, PASSWORD_DEFAULT), ':id' => $adminId]
    );

    $today = date('Y-m-d');

    $made = $tournaments->create([
        'name' => 'Sheet Cup', 'season_year' => 2026,
        'start_date' => $today, 'auction_date' => $today,
        'end_date' => date('Y-m-d', strtotime('+30 days')),
        'team_name_change_deadline' => $today,
    ]);

    $tid  = (int) $made['id'];
    $code = (string) Database::scalar('SELECT secret_code FROM tournaments WHERE id = :id', [':id' => $tid]);

    Database::run(
        "INSERT INTO teams (tournament_id, name, short_name, primary_color, purse_total)
         VALUES (:t, 'Sheet Titans', 'STT', '#22c55e', 10000000.00)",
        [':t' => $tid]
    );

    $photo = fakePhoto();

    /* Approved in this order; the sets are deliberately jumbled against it,
       so ordering by set can only pass if it is really sorting. */
    $people = [
        ['Bottom Of Set B', 'sheet.b1', 'Set B',   'bowler',              null],
        ['Marquee Man',     'sheet.m1', 'Marquee', 'batsman',             $photo],
        ['Middle Of Set A', 'sheet.a1', 'Set A',   'bowling_all_rounder', null],
        ['Second Marquee',  'sheet.m2', 'Marquee', 'batting_all_rounder', null],
    ];

    foreach ($people as $i => [$name, $username, $set, $role, $photoPath]) {
        $uid = $accounts->register([
            'name' => $name, 'username' => $username, 'email' => $username . '@t.test',
            'phone' => '90000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'address' => 'A Test Road', 'player_type' => $role,
            'password' => SHEET_PASSWORD, 'password_confirm' => SHEET_PASSWORD,
        ]);

        if ($photoPath !== null) {
            Database::run('UPDATE users SET photo_path = :p WHERE id = :id', [':p' => $photoPath, ':id' => $uid]);
        }

        $accounts->decideRegistration($uid, true, $adminId);
        $tournaments->apply($uid, $code);

        $appId = (int) Database::scalar(
            'SELECT id FROM tournament_registrations WHERE user_id = :u AND tournament_id = :t',
            [':u' => $uid, ':t' => $tid]
        );

        $tournaments->decideApplication($appId, true, $adminId, null, [
            'base_price'  => 200000 * ($i + 1),
            'auction_set' => $set,
            'role'        => $role,
        ]);
    }

    return ['tournament' => $tid, 'photo' => $photo];
}

// ---------------------------------------------------------------------

echo "\n\033[1mThe auctioneer's sheet\033[0m\n";

$seeded = seed();
$tid    = $seeded['tournament'];

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

$jar   = tempnam(sys_get_temp_dir(), 'jar');
$login = http("{$base}/login.php", $jar);

http("{$base}/login.php", $jar, [
    'csrf_token' => csrf($login['body']),
    'identifier' => 'admin',
    'password'   => SHEET_PASSWORD,
]);

$sheet = http("{$base}/admin/auction.php?tournament={$tid}", $jar);

section('The sheet itself');

is('the administrator reaches it', $sheet['status'], 200);
is('and it is the right tournament', str_contains($sheet['body'], 'Sheet Cup'), true);

section('Clicking a name opens a card');

is('every name in the pool is a link to a card',
    substr_count($sheet['body'], 'class="pc-name"') >= 4, true);
is('each card exists on the page',
    substr_count($sheet['body'], 'class="pc-modal"') >= 4, true);
is('a card is hidden until its name is clicked',
    str_contains($sheet['body'], '.pc-modal{display:none}'), true);
is('and shown when it is',
    str_contains($sheet['body'], '.pc-modal:target{display:grid'), true);
is('the backdrop closes it again',
    str_contains($sheet['body'], 'class="pc-backdrop" href="#closed"'), true);
is('it needs no JavaScript at all',
    preg_match('/<script/i', $sheet['body']), 0);

section('The photograph');

is('the registered photograph is on the card',
    str_contains($sheet['body'], '../' . $seeded['photo']), true);
is('and is reachable — the server serves it',
    http("{$base}/" . $seeded['photo'], $jar)['status'], 200);
is('it is never cropped: the whole picture is fitted',
    str_contains($sheet['body'], '.pc-photo{max-height:17rem;max-width:100%'), true);
is('with a link to the original for a closer look',
    str_contains($sheet['body'], 'Open the full-size photo'), true);
is('a player with no photograph says so rather than showing a gap',
    str_contains($sheet['body'], 'No photograph was supplied'), true);

section('Details on the card');

is('the mobile number is there — it is how you identify someone in a hall',
    str_contains($sheet['body'], '9000000001'), true);
is('so is the base price', str_contains($sheet['body'], 'base ₹4,00,000'), true);
is('and the career figures', str_contains($sheet['body'], '>Wickets</dt>'), true);

section('Ordering the pool');

$byLot = poolOrder($sheet['body']);

is('by default the pool is in lot order',
    $byLot, ['Bottom Of Set B', 'Marquee Man', 'Middle Of Set A', 'Second Marquee']);

$bySet = poolOrder(http("{$base}/admin/auction.php?tournament={$tid}&sort=set", $jar)['body']);

is('Marquee first puts both Marquee players at the top',
    array_slice($bySet, 0, 2), ['Marquee Man', 'Second Marquee']);
is('then Set A', $bySet[2], 'Middle Of Set A');
is('then Set B', $bySet[3], 'Bottom Of Set B');

$byKind = poolOrder(http("{$base}/admin/auction.php?tournament={$tid}&sort=kind", $jar)['body']);

is('by type of player runs batting to bowling',
    $byKind, ['Marquee Man', 'Second Marquee', 'Middle Of Set A', 'Bottom Of Set B']);

$kindSheet = http("{$base}/admin/auction.php?tournament={$tid}&sort=kind", $jar)['body'];

is('and the two all-rounders are named apart',
    str_contains($kindSheet, 'Batting all-rounder')
    && str_contains($kindSheet, 'Bowling all-rounder'), true);
is('never as a bare "all rounder"',
    str_contains($kindSheet, 'all rounder'), false);

$byPrice = poolOrder(http("{$base}/admin/auction.php?tournament={$tid}&sort=price", $jar)['body']);

is('highest base price first', $byPrice[0], 'Second Marquee');
is('and cheapest last', $byPrice[3], 'Bottom Of Set B');

$byName = poolOrder(http("{$base}/admin/auction.php?tournament={$tid}&sort=name", $jar)['body']);

is('by name is alphabetical', $byName[0], 'Bottom Of Set B');

$nonsense = poolOrder(http("{$base}/admin/auction.php?tournament={$tid}&sort=;DROP", $jar)['body']);

is('an order nobody offered falls back to lot order rather than reaching the query',
    $nonsense, $byLot);

section('The order survives recording a sale');

$marquee = http("{$base}/admin/auction.php?tournament={$tid}&sort=set", $jar);

preg_match('/name="lot_id" value="(\d+)"/', $marquee['body'], $m);
$lotId = (int) ($m[1] ?? 0);
$teamId = (int) Database::scalar('SELECT id FROM teams WHERE tournament_id = :t', [':t' => $tid]);

$sale = http("{$base}/admin/auction.php", $jar, [
    'csrf_token'    => csrf($marquee['body']),
    'action'        => 'sell',
    'lot_id'        => $lotId,
    'team_id'       => $teamId,
    'amount'        => '900000',
    'tournament_id' => $tid,
    'sort'          => 'set',
], follow: false);

is('the sale redirects back', $sale['status'], 302);
is('to the same running order',
    str_contains((string) $sale['location'], 'sort=set'), true);

$after = http("{$base}/admin/auction.php?tournament={$tid}&sort=set", $jar);

is('the player who was sold has left the pool',
    in_array('Marquee Man', poolOrder($after['body']), true), false);
is('and their card is now on the sold row',
    str_contains($after['body'], 'Sold · Sheet Titans'), true);
is('the pool is still Marquee first',
    poolOrder($after['body'])[0], 'Second Marquee');

// ---------------------------------------------------------------------

proc_terminate($server);
proc_close($server);
@unlink($jar);
@unlink(BASE_PATH . '/public/' . $seeded['photo']);

echo "\n" . str_repeat('─', 60) . "\n";
printf("  \033[32m%d passed\033[0m", $passed);
echo $failed > 0 ? sprintf(", \033[31m%d failed\033[0m\n\n", $failed) : "\n\n";

exit($failed > 0 ? 1 : 0);
