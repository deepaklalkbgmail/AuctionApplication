<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Signing out — every role, over real HTTP
 * =====================================================================
 *
 *     php tests/logout_test.php
 *
 *  The other suites call services directly. Signing out cannot be tested
 *  that way: the bug this exists to catch was not in Auth::logout(), which
 *  was always correct. It was that the header rendered
 *
 *      <a href="logout.php">Sign out</a>
 *
 *  while logout.php — rightly — ignores a GET, so the click did nothing,
 *  and login.php then sent the still-signed-in visitor back to their own
 *  screen. Every part worked; the wiring between them did not. Only a real
 *  request through a real server shows that.
 *
 *  So this boots a PHP development server against the test database,
 *  signs in as one account of each role, and clicks the sign-out control
 *  that role is actually given.
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

/**
 * One HTTP request, sharing a cookie jar so a session persists.
 *
 * @return array{status:int,location:?string,body:string}
 */
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
    $headers    = substr($raw, 0, $headerSize);
    $body       = substr($raw, $headerSize);

    curl_close($ch);

    preg_match('/^Location:\s*(.+)$/mi', $headers, $m);

    return ['status' => $status, 'location' => isset($m[1]) ? trim($m[1]) : null, 'body' => $body];
}

/** The CSRF token from a rendered page. */
function csrf(string $html): string
{
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

// ---------------------------------------------------------------------
//  A clean database with one account per role
// ---------------------------------------------------------------------

function seed(): void
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

    $adminId = (int) Database::scalar("SELECT id FROM users WHERE role = 'admin' LIMIT 1");

    // The administrator reset.sql creates must change its password. Clear
    // that here — a forced change is its own screen, tested elsewhere, and
    // it would stand between this suite and every other page.
    Database::exec(
        'UPDATE users SET password_hash = :h, must_change_password = 0 WHERE id = :id',
        [':h' => password_hash(TEST_PASSWORD, PASSWORD_BCRYPT, ['cost' => 12]), ':id' => $adminId]
    );

    foreach ([['Test Scorer', 'test.scorer', 'scorer@t.test', 'scorer'],
              ['Test Viewer', 'test.viewer', 'viewer@t.test', 'viewer']] as [$name, $user, $mail, $role]) {
        $made = $accounts->createStaffAccount($name, $user, $mail, $role, TEST_PASSWORD);
        Database::exec('UPDATE users SET must_change_password = 0 WHERE id = :id', [':id' => $made['user_id']]);
    }

    $player = $accounts->register([
        'name' => 'Test Player', 'email' => 'player@t.test', 'username' => 'test.player',
        'phone' => '9876543210', 'address' => '1 Test Road', 'player_type' => 'batsman',
        'password' => TEST_PASSWORD, 'password_confirm' => TEST_PASSWORD,
    ]);
    $accounts->decideRegistration($player, true, $adminId);

    $owner = $accounts->register([
        'name' => 'Test Owner', 'email' => 'owner@t.test', 'username' => 'test.owner',
        'phone' => '9876543211', 'address' => '2 Test Road', 'player_type' => 'bowling_all_rounder',
        'password' => TEST_PASSWORD, 'password_confirm' => TEST_PASSWORD,
    ]);
    $accounts->decideRegistration($owner, true, $adminId);

    $tournament = $tournaments->create([
        'name' => 'Logout Test Cup', 'season_year' => (int) date('Y'),
        'auction_date'              => date('Y-m-d', strtotime('+5 days')),
        'start_date'                => date('Y-m-d', strtotime('+20 days')),
        'end_date'                  => date('Y-m-d', strtotime('+60 days')),
        'team_name_change_deadline' => date('Y-m-d', strtotime('+15 days')),
    ]);

    $tournaments->createTeam($owner, (int) $tournament['id'], ['name' => 'Test Titans', 'short_name' => 'TT']);
}

// ---------------------------------------------------------------------

echo "\n\033[1mSigning out — every role, over HTTP\033[0m\n";

seed();

$port = 8099;
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

// Wait for it rather than sleeping blindly.
for ($i = 0; $i < 50; $i++) {
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

    if ($probe !== false) {
        fclose($probe);
        break;
    }

    usleep(100_000);
}

/**
 * Sign in, click the sign-out control on the given screen, and check the
 * session is genuinely gone afterwards.
 */
function check(string $role, string $username, string $landing, string $fullName, bool $gated): void
{
    global $base;

    echo "\n  \033[1m{$role}\033[0m ({$username})\n";

    $jar = tempnam(sys_get_temp_dir(), 'jar');

    $login = http("{$base}/login.php", $jar);
    http("{$base}/login.php", $jar, [
        'csrf_token' => csrf($login['body']),
        'identifier' => $username,
        'password'   => TEST_PASSWORD,
    ]);

    $home = http("{$base}/{$landing}", $jar);
    is("signs in and reaches {$landing}", $home['status'], 200);

    // The control must be a POST form. A plain link is the bug this catches.
    $hasForm = (bool) preg_match(
        '/<form[^>]+method="post"[^>]+action="[^"]*logout\.php"/i',
        $home['body']
    );
    is('the sign-out control is a POST form, not a link', $hasForm, true);

    // Press it, using that page's own token — exactly what a click sends.
    $out = http("{$base}/logout.php", $jar, ['csrf_token' => csrf($home['body'])], follow: false);
    is('pressing it redirects', $out['status'], 302);
    is('to the confirmed sign-out', str_contains((string) $out['location'], 'signedout=1'), true);

    // Is the session actually gone?
    $after = http("{$base}/{$landing}", $jar, follow: false);

    if ($gated) {
        is('the gated screen now turns you away', $after['status'], 302);
    } else {
        $stillIn = (bool) preg_match('/<form[^>]+action="[^"]*logout\.php"/i', $after['body']);
        is('the public screen no longer offers Sign out', $stillIn, false);
        is('and no longer names the person', str_contains($after['body'], $fullName), false);
    }

    // The original symptom: login.php bouncing a still-signed-in visitor
    // straight back into the application.
    $back = http("{$base}/login.php", $jar, follow: false);
    is('login.php shows the form instead of bouncing back', $back['status'], 200);
    is('and confirms the sign-out', str_contains(
        http("{$base}/login.php?signedout=1", $jar)['body'],
        'You have been signed out'
    ), true);

    @unlink($jar);
}

check('Administrator', 'admin',       'admin/index.php', 'Administrator', gated: true);
check('Scorer',        'test.scorer', 'score.php',       'Test Scorer',   gated: false);
check('Viewer',        'test.viewer', 'auction.php',     'Test Viewer',   gated: false);
check('Player',        'test.player', 'profile.php',     'Test Player',   gated: true);
check('Team Owner',    'test.owner',  'team.php',        'Test Owner',    gated: true);

// ---------------------------------------------------------------------
//  A GET on logout.php — an old bookmark, or a link from an older copy
// ---------------------------------------------------------------------
echo "\n  \033[1mA plain GET on logout.php\033[0m\n";

$jar   = tempnam(sys_get_temp_dir(), 'jar');
$login = http("{$base}/login.php", $jar);
http("{$base}/login.php", $jar, [
    'csrf_token' => csrf($login['body']),
    'identifier' => 'admin',
    'password'   => TEST_PASSWORD,
]);

$get = http("{$base}/logout.php", $jar);
is('offers a real button rather than silently bouncing',
    str_contains($get['body'], 'Sign out of'), true);
is('and the session is untouched until it is pressed',
    http("{$base}/admin/index.php", $jar, follow: false)['status'], 200);

$pressed = http("{$base}/logout.php", $jar, ['csrf_token' => csrf($get['body'])], follow: false);
is('pressing it signs you out', str_contains((string) $pressed['location'], 'signedout=1'), true);
is('and the screen is closed afterwards',
    http("{$base}/admin/index.php", $jar, follow: false)['status'], 302);

@unlink($jar);

// A GET when already signed out must not render a confirmation for nobody.
$empty = http("{$base}/logout.php", tempnam(sys_get_temp_dir(), 'jar'), follow: false);
is('signed out already, it just redirects', $empty['status'], 302);

// ---------------------------------------------------------------------

proc_terminate($server);
proc_close($server);

echo "\n" . str_repeat('─', 60) . "\n";
printf("  \033[32m%d passed\033[0m", $passed);
echo $failed > 0 ? sprintf(", \033[31m%d failed\033[0m\n\n", $failed) : "\n\n";

exit($failed > 0 ? 1 : 0);
