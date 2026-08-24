<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  CricAuction — landing page
 * =====================================================================
 *  The front door. Explains what the application does and sends each
 *  visitor to the screen meant for them.
 *
 *  Deliberately server-rendered with NO JavaScript. Two reasons:
 *
 *    1. This is the first page anyone sees. It must render even if a
 *       script is blocked, a CDN is unreachable, or a Content-Security-
 *       Policy refuses to let Alpine evaluate expressions — which is
 *       exactly the failure that made the auction dashboard look broken
 *       on shared hosting.
 *    2. Nothing here needs interactivity. It is links and status.
 *
 *  The live snapshot below reads the database directly; if that fails,
 *  the page still renders, minus the status strip.
 */

require_once dirname(__DIR__) . '/config/config.php';

use App\Core\Auth;
use App\Services\TournamentService;

$user = Auth::user();
$role = $user['role'] ?? null;

/** Where a signed-in user should land. */
function homeFor(?string $role): string
{
    return match ($role) {
        Auth::ROLE_SCORER => 'score.php',
        Auth::ROLE_ADMIN  => 'admin/index.php',
        Auth::ROLE_PLAYER => 'profile.php',
        Auth::ROLE_OWNER  => 'team.php',
        default           => 'auction.php',
    };
}

// ---------------------------------------------------------------------
//  Live snapshot — best effort. A dead database must not break the page.
// ---------------------------------------------------------------------
$tournament = null;
$liveLot    = null;
$liveMatch  = null;
$counts     = ['players' => 0, 'teams' => 0, 'sold' => 0];

try {
    if (Database::isAvailable()) {
        // The same tournament auction.php shows, so the counts on this page
        // and the board behind the button can never describe different
        // seasons. "The first tournament ever created" was not it.
        $tid = (new TournamentService())->currentAuctionId();

        $tournament = $tid === null ? null : Database::one(
            'SELECT id, name, season_year, status FROM tournaments WHERE id = :t',
            [':t' => $tid]
        );

        if ($tournament !== null) {

            $liveLot = Database::one(
                'SELECT full_name, role, current_bid, base_price, bidder_team_name
                   FROM v_live_auction WHERE tournament_id = :t LIMIT 1',
                [':t' => $tid]
            );

            $liveMatch = Database::one(
                'SELECT i.total_runs, i.total_wickets, i.legal_balls,
                        bat.short_name AS batting, bwl.short_name AS bowling,
                        t.balls_per_over
                   FROM innings i
                   JOIN matches m     ON m.id = i.match_id
                   JOIN tournaments t ON t.id = m.tournament_id
                   JOIN teams bat     ON bat.id = i.batting_team_id
                   JOIN teams bwl     ON bwl.id = i.bowling_team_id
                  WHERE m.status = :live AND i.is_completed = 0
               ORDER BY i.id DESC LIMIT 1',
                [':live' => 'live']
            );

            $counts = [
                'players' => (int) Database::scalar('SELECT COUNT(*) FROM players WHERE tournament_id = :t', [':t' => $tid]),
                'teams'   => (int) Database::scalar('SELECT COUNT(*) FROM teams WHERE tournament_id = :t', [':t' => $tid]),
                'sold'    => (int) Database::scalar(
                    'SELECT COUNT(*) FROM players WHERE tournament_id = :t AND status = :s',
                    [':t' => $tid, ':s' => 'sold']
                ),
            ];
        }
    }
} catch (Throwable $e) {
    error_log('[landing] snapshot unavailable: ' . $e->getMessage());
}

$roleLabels = [
    'batsman' => 'Batter', 'bowler' => 'Bowler',
    'all_rounder' => 'All-rounder', 'wicket_keeper' => 'Wicket-keeper',
];

/** The five roles, in the order a visitor is most likely to need them. */
$roles = [
    [
        'key'   => Auth::ROLE_PLAYER,
        'name'  => 'Player',
        'lead'  => 'You want to be picked',
        'blurb' => 'Register yourself, then join a tournament with the code the organisers give you. Once an administrator approves you, your name goes into the auction.',
        'does'  => ['Register in two minutes', 'Join with a tournament code', 'See what you sold for'],
        'href'  => 'register.php',
        'cta'   => 'Register as a player',
        'open'  => true,
        'accent' => 'emerald',
    ],
    [
        'key'   => Auth::ROLE_VIEWER,
        'name'  => 'Viewer',
        'lead'  => 'Just watching',
        'blurb' => 'Follow the auction board and the live scorecard. Nothing to install, nothing to sign in for.',
        'does'  => ['Live auction board', 'Ball-by-ball scorecard', 'Squads and purses'],
        'href'  => 'auction.php?role=viewer',
        'cta'   => 'Watch live',
        'open'  => true,
        'accent' => 'sky',
    ],
    [
        'key'   => Auth::ROLE_OWNER,
        'name'  => 'Team Owner',
        'lead'  => 'You own a franchise',
        'blurb' => 'Name your team, then bid for players in the live auction. Your purse and squad limits are enforced as you go.',
        'does'  => ['Name and rename your team', 'Place bids in real time', 'Watch your remaining purse'],
        'href'  => 'team.php',
        'cta'   => 'Sign in to bid',
        'open'  => false,
        'accent' => 'emerald',
    ],
    [
        'key'   => Auth::ROLE_SCORER,
        'name'  => 'Scorer',
        'lead'  => 'You are at the ground',
        'blurb' => 'A thumb-sized keypad for ball-by-ball entry, built to be used one-handed on a phone in sunlight.',
        'does'  => ['Runs, extras and wickets', 'Undo the last ball', 'Scorecard updates as you tap'],
        'href'  => 'score.php',
        'cta'   => 'Sign in to score',
        'open'  => false,
        'accent' => 'amber',
    ],
    [
        'key'   => Auth::ROLE_ADMIN,
        'name'  => 'Admin',
        'lead'  => 'You run the tournament',
        'blurb' => 'Create the tournament, approve who gets in, and control the hammer: open a lot, sell or pass, and move the auction along.',
        'does'  => ['Approve players and teams', 'Create tournaments and codes', 'Sold / unsold decisions'],
        'href'  => 'admin/index.php',
        'cta'   => 'Sign in as admin',
        'open'  => false,
        'accent' => 'violet',
    ],
];

$accents = [
    'sky'     => ['ring' => 'hover:border-sky-400/40',     'chip' => 'bg-sky-400/15 text-sky-300',         'btn' => 'bg-sky-400 text-ink-900 hover:brightness-110'],
    'emerald' => ['ring' => 'hover:border-emerald-400/40', 'chip' => 'bg-emerald-400/15 text-emerald-300', 'btn' => 'bg-emerald-400 text-ink-900 hover:brightness-110'],
    'amber'   => ['ring' => 'hover:border-amber-400/40',   'chip' => 'bg-amber-400/15 text-amber-300',     'btn' => 'bg-amber-400 text-ink-900 hover:brightness-110'],
    'violet'  => ['ring' => 'hover:border-violet-400/40',  'chip' => 'bg-violet-400/15 text-violet-300',   'btn' => 'bg-violet-400 text-ink-900 hover:brightness-110'],
];

?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#020617">
    <meta name="description" content="Run a cricket player auction and score matches ball by ball.">
    <title><?= e(APP_NAME) ?> — Cricket auction &amp; live scoring</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2032%2032%22%3E%3Crect%20width%3D%2232%22%20height%3D%2232%22%20rx%3D%227%22%20fill%3D%22%2322c55e%22%2F%3E%3Cpath%20d%3D%22M8.5%2024%2019%2013.5M17.5%206.5%2025.5%2014.5%2021.5%2018.5%2013.5%2010.5z%22%20stroke%3D%22%23020617%22%20stroke-width%3D%222.6%22%20fill%3D%22none%22%20stroke-linecap%3D%22round%22%2F%3E%3Ccircle%20cx%3D%228%22%20cy%3D%2224.5%22%20r%3D%222.3%22%20fill%3D%22%23020617%22%2F%3E%3C%2Fsvg%3E">
    <link rel="stylesheet" href="assets/css/app.css">
</head>

<body class="bg-arena min-h-screen font-sans text-slate-200 antialiased">

<!-- ============================== HEADER ============================== -->
<header class="border-b border-white/10">
    <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-4 sm:px-6">
        <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-emerald-400 to-emerald-600 shadow-lg shadow-emerald-500/25">
            <svg viewBox="0 0 24 24" class="h-6 w-6 text-ink-900" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                <path d="M4.5 19.5 14 10"/><path d="M13 3.8 20.2 11l-3.4 3.4L9.6 7.2z"/><circle cx="5" cy="19" r="1.6" fill="currentColor" stroke="none"/>
            </svg>
        </div>
        <div class="min-w-0 leading-tight">
            <p class="text-[15px] font-extrabold tracking-tight text-white"><?= e(APP_NAME) ?></p>
            <?php if ($tournament !== null): ?>
                <p class="truncate text-[11px] font-medium text-slate-400">
                    <?= e((string) $tournament['name']) ?> · <?= e((string) $tournament['season_year']) ?>
                </p>
            <?php endif; ?>
        </div>

        <nav class="ml-auto flex items-center gap-2">
            <?php if ($user !== null): ?>
                <span class="hidden rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-[12px] font-semibold text-slate-300 sm:inline-block">
                    <?= e((string) $user['name']) ?>
                </span>
                <a href="<?= e(homeFor($role)) ?>"
                   class="rounded-lg bg-emerald-400 px-3.5 py-2 text-[12px] font-black uppercase tracking-wide text-ink-900 transition hover:brightness-110">
                    My screen
                </a>
                <form method="post" action="logout.php" class="inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="rounded-lg border border-white/10 px-3 py-2 text-[12px] font-semibold text-slate-400 transition hover:bg-white/5 hover:text-slate-200">
                        Sign out
                    </button>
                </form>
            <?php else: ?>
                <a href="auction.php?role=viewer" class="rounded-lg px-3 py-2 text-[12px] font-semibold text-slate-300 transition hover:bg-white/5">
                    Watch live
                </a>
                <a href="login.php" class="rounded-lg px-3 py-2 text-[12px] font-semibold text-slate-300 transition hover:bg-white/5">
                    Sign in
                </a>
                <a href="register.php" class="rounded-lg bg-emerald-400 px-3.5 py-2 text-[12px] font-black uppercase tracking-wide text-ink-900 transition hover:brightness-110">
                    Register
                </a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="mx-auto max-w-6xl px-4 sm:px-6">

    <!-- =============================== HERO =============================== -->
    <section class="py-12 sm:py-16">
        <p class="mb-3 inline-flex items-center gap-2 rounded-full border border-emerald-400/25 bg-emerald-400/10 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-emerald-300">
            Auction &amp; live scoring
        </p>

        <h1 class="max-w-3xl text-4xl font-black leading-[1.05] tracking-tight text-white sm:text-5xl lg:text-6xl">
            Run your player auction,<br class="hidden sm:block">
            then score every ball.
        </h1>

        <p class="mt-5 max-w-2xl text-[15px] leading-relaxed text-slate-400 sm:text-base">
            One place for the whole tournament — bid for players against a live purse, then
            record the cricket ball by ball while everyone follows the scorecard as it happens.
        </p>

        <div class="mt-8 flex flex-wrap gap-3">
            <?php if ($user !== null): ?>
                <a href="<?= e(homeFor($role)) ?>"
                   class="rounded-xl bg-gradient-to-r from-emerald-400 to-emerald-500 px-6 py-3.5 text-sm font-black uppercase tracking-wide text-ink-900 shadow-lg shadow-emerald-500/25 transition hover:brightness-110">
                    Continue as <?= e(str_replace('_', ' ', (string) $role)) ?>
                </a>
            <?php else: ?>
                <a href="register.php"
                   class="rounded-xl bg-gradient-to-r from-emerald-400 to-emerald-500 px-6 py-3.5 text-sm font-black uppercase tracking-wide text-ink-900 shadow-lg shadow-emerald-500/25 transition hover:brightness-110">
                    Register as a player
                </a>
                <a href="auction.php?role=viewer"
                   class="rounded-xl border border-white/15 px-6 py-3.5 text-sm font-black uppercase tracking-wide text-slate-200 transition hover:bg-white/5">
                    Watch the live board
                </a>
                <a href="login.php"
                   class="rounded-xl border border-white/15 px-6 py-3.5 text-sm font-black uppercase tracking-wide text-slate-200 transition hover:bg-white/5">
                    Sign in
                </a>
            <?php endif; ?>
        </div>
    </section>

    <!-- ========================== LIVE RIGHT NOW ========================== -->
    <?php if ($liveLot !== null || $liveMatch !== null): ?>
        <section class="panel mb-14 rounded-2xl p-5 sm:p-6">
            <p class="mb-4 inline-flex items-center gap-2 text-[11px] font-black uppercase tracking-wider text-rose-300">
                <span class="h-1.5 w-1.5 rounded-full bg-rose-400"></span> Happening now
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                <?php if ($liveLot !== null): ?>
                    <a href="auction.php?role=viewer" class="group rounded-xl border border-white/10 bg-white/[0.03] p-4 transition hover:border-emerald-400/40">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Under the hammer</p>
                        <p class="mt-1 truncate text-lg font-black text-white"><?= e((string) $liveLot['full_name']) ?></p>
                        <p class="mt-0.5 text-[12px] font-medium text-emerald-300">
                            <?= e($roleLabels[$liveLot['role']] ?? (string) $liveLot['role']) ?>
                        </p>
                        <p class="mt-3 font-mono text-2xl font-black text-gold">
                            <?= e(money($liveLot['current_bid'] ?? $liveLot['base_price'])) ?>
                        </p>
                        <?php if (!empty($liveLot['bidder_team_name'])): ?>
                            <p class="mt-1 text-[12px] text-slate-400">leading: <?= e((string) $liveLot['bidder_team_name']) ?></p>
                        <?php endif; ?>
                        <p class="mt-3 text-[12px] font-bold text-emerald-400 group-hover:underline">Open the auction board →</p>
                    </a>
                <?php endif; ?>

                <?php if ($liveMatch !== null): ?>
                    <?php
                    $bpo   = max(1, (int) $liveMatch['balls_per_over']);
                    $balls = (int) $liveMatch['legal_balls'];
                    ?>
                    <a href="score.php" class="group rounded-xl border border-white/10 bg-white/[0.03] p-4 transition hover:border-sky-400/40">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Live match</p>
                        <p class="mt-1 text-lg font-black text-white">
                            <?= e((string) $liveMatch['batting']) ?> v <?= e((string) $liveMatch['bowling']) ?>
                        </p>
                        <p class="mt-3 font-mono text-2xl font-black text-white">
                            <?= e((string) $liveMatch['total_runs']) ?>/<?= e((string) $liveMatch['total_wickets']) ?>
                            <span class="ml-1 text-base font-bold text-emerald-300">
                                (<?= e((string) intdiv($balls, $bpo)) ?>.<?= e((string) ($balls % $bpo)) ?>)
                            </span>
                        </p>
                        <p class="mt-3 text-[12px] font-bold text-sky-400 group-hover:underline">Open the scorecard →</p>
                    </a>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- ============================== ROLES ============================== -->
    <section class="mb-14">
        <h2 class="text-2xl font-black tracking-tight text-white sm:text-3xl">Where should you go?</h2>
        <p class="mt-2 max-w-2xl text-[14px] text-slate-400">
            Five kinds of people use this. Pick the one that describes you.
        </p>

        <div class="mt-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($roles as $r): ?>
                <?php
                $a       = $accents[$r['accent']];
                $isMine  = $role === $r['key'];
                $canOpen = $r['open'] || $isMine || ($role === Auth::ROLE_ADMIN);
                ?>
                <article class="flex flex-col rounded-2xl border p-5 transition
                                <?= $isMine ? 'border-emerald-400/50 bg-emerald-400/[0.07]' : 'border-white/10 bg-white/[0.03] ' . $a['ring'] ?>">

                    <span class="inline-flex w-fit rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider <?= e($a['chip']) ?>">
                        <?= e($r['name']) ?>
                    </span>

                    <p class="mt-3 text-[15px] font-bold text-white"><?= e($r['lead']) ?></p>
                    <p class="mt-2 text-[13px] leading-relaxed text-slate-400"><?= e($r['blurb']) ?></p>

                    <ul class="mt-4 space-y-1.5">
                        <?php foreach ($r['does'] as $item): ?>
                            <li class="flex items-start gap-2 text-[12px] text-slate-400">
                                <svg viewBox="0 0 20 20" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-400" fill="currentColor">
                                    <path d="M8 13.2 4.8 10l-1.1 1.1L8 15.4l8.4-8.4-1.1-1.1z"/>
                                </svg>
                                <?= e($item) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <a href="<?= e($canOpen ? $r['href'] : 'login.php') ?>"
                       class="key mt-5 block rounded-xl px-4 py-3 text-center text-[12px] font-black uppercase tracking-wide transition
                              <?= $canOpen ? e($a['btn']) : 'border border-white/15 text-slate-300 hover:bg-white/5' ?>">
                        <?= e($isMine ? 'Open my screen' : ($canOpen ? $r['cta'] : $r['cta'])) ?>
                    </a>

                    <?php if (!$canOpen): ?>
                        <p class="mt-2 text-center text-[10px] font-medium uppercase tracking-wider text-slate-600">
                            Account required
                        </p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ============================= FEATURES ============================= -->
    <section class="mb-14 grid gap-4 lg:grid-cols-2">

        <div class="panel rounded-2xl p-6">
            <h3 class="text-lg font-black text-white">The auction</h3>
            <p class="mt-2 text-[13px] leading-relaxed text-slate-400">
                Players go under the hammer one at a time. Owners bid against a countdown that
                restarts with every bid, so nobody wins by clicking last.
            </p>
            <dl class="mt-5 space-y-3.5">
                <?php
                $auctionFeatures = [
                    ['Player pool', 'Batters, bowlers, all-rounders and keepers, each with a base price and career record.'],
                    ['Live board', 'Current player, current bid, who is leading, and the clock — updating for everyone at once.'],
                    ['Purse control', 'A bid that a team cannot afford is refused, and the screen says how much they can actually spend.'],
                    ['Squad limits', 'Squad size and overseas quotas are enforced by the database, not just the page.'],
                ];
                foreach ($auctionFeatures as [$title, $desc]): ?>
                    <div>
                        <dt class="text-[13px] font-bold text-emerald-300"><?= e($title) ?></dt>
                        <dd class="mt-0.5 text-[12.5px] leading-relaxed text-slate-400"><?= e($desc) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </div>

        <div class="panel rounded-2xl p-6">
            <h3 class="text-lg font-black text-white">The scoring</h3>
            <p class="mt-2 text-[13px] leading-relaxed text-slate-400">
                Built for someone standing at the boundary with a phone in one hand. Big keys,
                no hunting, and an undo that actually undoes.
            </p>
            <dl class="mt-5 space-y-3.5">
                <?php
                $scoringFeatures = [
                    ['Match setup', 'Two teams, the toss and the decision, then the playing elevens.'],
                    ['Ball by ball', 'Runs, wides, no-balls, byes, leg byes and every kind of dismissal.'],
                    ['Live scorecard', 'Score, overs, run rate, both batters and the bowler\'s figures, recalculated on every ball.'],
                    ['Cricket\'s rules', 'Strike rotation, over changes and extras are worked out for you, so the scorer just records what happened.'],
                ];
                foreach ($scoringFeatures as [$title, $desc]): ?>
                    <div>
                        <dt class="text-[13px] font-bold text-sky-300"><?= e($title) ?></dt>
                        <dd class="mt-0.5 text-[12.5px] leading-relaxed text-slate-400"><?= e($desc) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </div>
    </section>

    <!-- ============================ AT A GLANCE ============================ -->
    <?php if ($tournament !== null): ?>
        <section class="mb-14">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <?php
                $stats = [
                    ['Teams',   (string) $counts['teams']],
                    ['Players', (string) $counts['players']],
                    ['Sold',    (string) $counts['sold']],
                    ['Season',  (string) $tournament['season_year']],
                ];
                foreach ($stats as [$label, $value]): ?>
                    <div class="rounded-2xl border border-white/8 bg-white/[0.03] px-4 py-5 text-center">
                        <p class="font-mono text-3xl font-black text-white"><?= e($value) ?></p>
                        <p class="mt-1 text-[10px] font-bold uppercase tracking-wider text-slate-500"><?= e($label) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</main>

<footer class="border-t border-white/10">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-5 gap-y-2 px-4 py-6 text-[12px] text-slate-500 sm:px-6">
        <span class="font-semibold text-slate-400"><?= e(APP_NAME) ?></span>
        <a href="auction.php?role=viewer" class="transition hover:text-slate-300">Auction board</a>
        <a href="score.php" class="transition hover:text-slate-300">Scorecard</a>
        <?php if ($user === null): ?>
            <a href="login.php" class="transition hover:text-slate-300">Sign in</a>
        <?php endif; ?>
    </div>
</footer>

</body>
</html>
