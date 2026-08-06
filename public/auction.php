<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  CricAuction — Unified Dashboard  (Phase 1 UI)
 * =====================================================================
 *  The live "Auction Screen": current player under the hammer, current
 *  bid, bidding team, countdown, purse board and bid feed.
 *
 *  Data source
 *    • If MySQL is reachable and seeded, everything below is read through
 *      prepared statements (see $state assembly).
 *    • Otherwise it falls back to an equivalent demo dataset so the UI is
 *      reviewable before the database exists. The array shapes are
 *      identical, so Phase 2 only has to delete the fallback.
 *
 *  Role preview
 *    ?role=admin | team_owner | scorer | viewer  — swaps the control rail.
 *    In Phase 2 this comes from Auth::role() instead of the query string.
 */

require_once dirname(__DIR__) . '/config/config.php';

use App\Core\Auth;
use App\Core\Security;

$ALLOWED_ROLES = [Auth::ROLE_ADMIN, Auth::ROLE_OWNER, Auth::ROLE_SCORER, Auth::ROLE_VIEWER];

$role = Auth::check()
    ? Auth::role()
    : (in_array($_GET['role'] ?? '', $ALLOWED_ROLES, true) ? $_GET['role'] : Auth::ROLE_OWNER);

// Signed out, nobody is named. The demonstration names that used to sit here
// made a signed-out visitor look like a signed-in owner, which is exactly the
// confusion a sign-out button exists to avoid. Locally, where the role
// preview switcher is useful, keep the placeholder names.
$viewer = Auth::user() ?? [
    'name'    => IS_PRODUCTION ? 'Not signed in' : match ($role) {
        Auth::ROLE_ADMIN  => 'Arjun Mehta',
        Auth::ROLE_SCORER => 'Priya Nair',
        Auth::ROLE_VIEWER => 'Guest Viewer',
        default           => 'Imran Qureshi',
    },
    'role'    => $role,
    // Coastal Kings — a team that isn't currently leading, so the bid rail
    // demonstrates both the enabled and the purse-blocked button states.
    'team_id' => $role === Auth::ROLE_OWNER ? 3 : null,
];

$TOURNAMENT_ID = 1;

// ---------------------------------------------------------------------
//  Live data (prepared statements only) with a demo fallback
// ---------------------------------------------------------------------
$state    = null;
$liveData = false;

if (Database::isAvailable()) {
    try {
        $tournament = Database::one(
            'SELECT id, name, season_year, bid_increment, bid_timer_seconds, max_squad_size
               FROM tournaments WHERE id = :id',
            [':id' => $TOURNAMENT_ID]
        );

        $lot = Database::one(
            'SELECT * FROM v_live_auction WHERE tournament_id = :t LIMIT 1',
            [':t' => $TOURNAMENT_ID]
        );

        if ($tournament && $lot) {
            $state = [
                'tournament' => $tournament,
                'lot'        => $lot,
                'teams'      => Database::all(
                    'SELECT id, name, short_name, primary_color, purse_total, purse_spent,
                            purse_remaining, players_bought, overseas_bought
                       FROM teams WHERE tournament_id = :t ORDER BY purse_remaining DESC',
                    [':t' => $TOURNAMENT_ID]
                ),
                'bids'       => Database::all(
                    'SELECT b.bid_amount, b.placed_at, t.short_name, t.name AS team_name, t.primary_color
                       FROM auction_bids b
                       JOIN teams t ON t.id = b.team_id
                      WHERE b.lot_id = :lot
                   ORDER BY b.placed_at DESC
                      LIMIT 8',
                    [':lot' => (int) $lot['lot_id']]
                ),
                'queue'      => Database::all(
                    'SELECT l.lot_order, l.base_price, p.display_name, p.role, p.country, p.is_overseas
                       FROM auction_lots l
                       JOIN players p ON p.id = l.player_id
                      WHERE l.tournament_id = :t AND l.status = :s
                   ORDER BY l.lot_order
                      LIMIT 6',
                    [':t' => $TOURNAMENT_ID, ':s' => 'queued']
                ),
                'sold'       => Database::all(
                    'SELECT l.sold_price, p.display_name, p.role, t.short_name, t.primary_color
                       FROM auction_lots l
                       JOIN players p ON p.id = l.player_id
                       JOIN teams   t ON t.id = l.sold_to_team_id
                      WHERE l.tournament_id = :t AND l.status = :s
                   ORDER BY l.closed_at DESC
                      LIMIT 5',
                    [':t' => $TOURNAMENT_ID, ':s' => 'sold']
                ),
            ];
            $liveData = true;
        }
    } catch (Throwable $e) {
        error_log('[dashboard] falling back to demo data: ' . $e->getMessage());
    }
}

// A clean installation has no auction yet. Show that honestly rather than
// falling back to the bundled fixtures — invented players on a production
// site look exactly like a bug, and cannot be told apart from one. The
// fallback stays for local development, where it is useful for UI work.
if ($state === null) {
    if (IS_PRODUCTION) {
        $emptyTitle = 'No auction is running';
        $emptyBody  = 'When the tournament director puts the first player under the hammer, the live board appears here.';
        $emptyHint  = Auth::is(Auth::ROLE_ADMIN)
            ? 'As administrator: load a tournament, teams, players and their auction lots, then open the first lot. Section 4 of the User Guide has the steps.'
            : null;

        require dirname(__DIR__) . '/app/Views/partials/empty_state.php';
        exit;
    }

    $state = require dirname(__DIR__) . '/database/demo_state.php';
}

$t     = $state['tournament'];
$lot   = $state['lot'];
$teams = $state['teams'];

// Seconds left on the hammer.
$secondsLeft = (int) ($t['bid_timer_seconds'] ?? 30);

if (!empty($lot['ends_at'])) {
    $secondsLeft = max(0, strtotime((string) $lot['ends_at']) - time());
}

$roleLabels = [
    'batsman'       => 'Batter',
    'bowler'        => 'Bowler',
    'all_rounder'   => 'All-rounder',
    'wicket_keeper' => 'Wicket-keeper',
];

// Payload handed to Alpine. json() hex-escapes <, &, ' and " so nothing in a
// player or team name can break out of the <script> block.
$bootstrap = Security::json([
    'role'        => $role,
    'myTeamId'    => $viewer['team_id'],
    // Live mode posts real bids to the API. It needs both a database behind
    // the page and a session to post with — on demo data, or signed out, the
    // screen stays in local preview mode instead of failing every poll.
    'live'        => $liveData && Auth::check(),
    'apiUrl'      => 'api/auction.php',
    'tournamentId' => $TOURNAMENT_ID,
    'lotId'       => (int) $lot['lot_id'],
    'playerName'  => $lot['full_name'],
    'basePrice'   => (float) $lot['base_price'],
    'currentBid'  => (float) ($lot['current_bid'] ?? $lot['base_price']),
    'leaderId'    => $lot['bidder_team_id'] !== null ? (int) $lot['bidder_team_id'] : null,
    'bidCount'    => (int) $lot['bid_count'],
    'increment'   => (float) $t['bid_increment'],
    'timer'       => (int) $t['bid_timer_seconds'],
    'secondsLeft' => $secondsLeft,
    'maxSquad'    => (int) $t['max_squad_size'],
    // players.base_price default — the cheapest a remaining slot can cost, so
    // the client knows how much purse to hold back.
    'minBase'     => 200000.0,
    'csrf'        => Security::csrfToken(),
    'teams'       => array_map(static fn (array $team): array => [
        'id'        => (int) $team['id'],
        'name'      => $team['name'],
        'short'     => $team['short_name'],
        'color'     => $team['primary_color'],
        'total'     => (float) $team['purse_total'],
        'spent'     => (float) $team['purse_spent'],
        'remaining' => (float) $team['purse_remaining'],
        'bought'    => (int) $team['players_bought'],
    ], $teams),
    'bids'        => array_map(static fn (array $bid): array => [
        'amount' => (float) $bid['bid_amount'],
        'short'  => $bid['short_name'],
        'team'   => $bid['team_name'],
        'color'  => $bid['primary_color'],
        'at'     => date('H:i:s', strtotime((string) $bid['placed_at'])),
    ], $state['bids']),
]);

?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#020617">
    <title><?= e(APP_NAME) ?> — Live Auction</title>

    <!-- Self-hosted. Tailwind is built ahead of time (see tailwind.config.js)
         and Alpine is vendored, so the page needs no external origin and
         satisfies a `script-src 'self'` / `style-src 'self'` policy. -->
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2032%2032%22%3E%3Crect%20width%3D%2232%22%20height%3D%2232%22%20rx%3D%227%22%20fill%3D%22%2322c55e%22%2F%3E%3Cpath%20d%3D%22M8.5%2024%2019%2013.5M17.5%206.5%2025.5%2014.5%2021.5%2018.5%2013.5%2010.5z%22%20stroke%3D%22%23020617%22%20stroke-width%3D%222.6%22%20fill%3D%22none%22%20stroke-linecap%3D%22round%22%2F%3E%3Ccircle%20cx%3D%228%22%20cy%3D%2224.5%22%20r%3D%222.3%22%20fill%3D%22%23020617%22%2F%3E%3C%2Fsvg%3E">
    <link rel="stylesheet" href="assets/css/app.css">

    <!-- Order matters: Alpine calls start() as soon as it runs, so the
         component that x-data references must already be defined. Deferred
         scripts execute in document order, which guarantees that. -->
    <script defer src="assets/js/auction.js"></script>
    <script defer src="assets/js/alpine.js"></script>

</head>

<body class="bg-arena min-h-screen font-sans text-slate-200 antialiased selection:bg-emerald-400/30">

<div x-data="auctionScreen(<?= e($bootstrap) ?>)" x-cloak class="min-h-screen pb-28 lg:pb-8">

    <!-- ============================ TOP BAR ============================ -->
    <header class="sticky top-0 z-40 border-b border-white/10 bg-ink-900/80 backdrop-blur-xl">
        <div class="mx-auto flex max-w-[1600px] items-center gap-3 px-4 py-3 sm:px-6">

            <!-- Brand -->
            <a href="index.php" class="flex items-center gap-3" title="Back to the home page">
                <div class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-emerald-400 to-emerald-600 shadow-lg shadow-emerald-500/25">
                    <svg viewBox="0 0 24 24" class="h-6 w-6 text-ink-900" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                        <path d="M4.5 19.5 14 10"/><path d="M13 3.8 20.2 11l-3.4 3.4L9.6 7.2z"/><circle cx="5" cy="19" r="1.6" fill="currentColor" stroke="none"/>
                    </svg>
                </div>
                <div class="leading-tight">
                    <p class="text-[15px] font-extrabold tracking-tight text-white"><?= e(APP_NAME) ?></p>
                    <p class="hidden text-[11px] font-medium text-slate-400 sm:block">
                        <?= e($t['name']) ?> · <?= e((string) $t['season_year']) ?>
                    </p>
                </div>
            </a>

            <!-- Module tabs -->
            <nav class="ml-4 hidden items-center gap-1 rounded-xl bg-white/5 p-1 lg:flex">
                <a href="auction.php" class="rounded-lg bg-emerald-400/15 px-3 py-1.5 text-[13px] font-semibold text-emerald-300">Auction</a>
                <a href="score.php" class="rounded-lg px-3 py-1.5 text-[13px] font-medium text-slate-400 transition hover:bg-white/5 hover:text-slate-200">Live Scoring</a>
                <a href="#" class="rounded-lg px-3 py-1.5 text-[13px] font-medium text-slate-400 transition hover:bg-white/5 hover:text-slate-200">Fixtures</a>
                <a href="#" class="rounded-lg px-3 py-1.5 text-[13px] font-medium text-slate-400 transition hover:bg-white/5 hover:text-slate-200">Squads</a>
            </nav>

            <div class="ml-auto flex items-center gap-2 sm:gap-3">

                <!-- Live pill -->
                <span class="hidden items-center gap-2 rounded-full border border-rose-400/30 bg-rose-500/10 px-3 py-1.5 text-[11px] font-bold uppercase tracking-wider text-rose-300 sm:inline-flex">
                    <span class="relative flex h-2 w-2">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-rose-400 opacity-75"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-rose-500"></span>
                    </span>
                    Auction Live
                </span>

                <!-- Data source badge -->
                <span class="hidden rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400 xl:inline-block">
                    <?= $liveData ? 'MySQL' : 'Demo data' ?>
                </span>

                <!-- Role preview switcher -->
                <form method="get" class="hidden sm:block">
                    <label for="role" class="sr-only">Preview as role</label>
                    <select id="role" name="role" onchange="this.form.submit()"
                            class="rounded-lg border border-white/10 bg-white/5 px-2.5 py-1.5 text-[12px] font-semibold text-slate-300 outline-none transition focus:border-emerald-400/50 focus:ring-2 focus:ring-emerald-400/20">
                        <?php foreach ($ALLOWED_ROLES as $r): ?>
                            <option value="<?= e($r) ?>" <?= $r === $role ? 'selected' : '' ?>>
                                <?= e(ucwords(str_replace('_', ' ', $r))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <!-- Purse chip (team owners only) -->
                <template x-if="myTeam">
                    <div class="flex items-center gap-2 rounded-xl border border-emerald-400/25 bg-emerald-400/10 px-3 py-1.5">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-300/70">Purse</span>
                        <span class="font-mono text-sm font-bold text-emerald-300" x-text="fmt(myTeam.remaining)"></span>
                    </div>
                </template>

                <!-- Avatar -->
                <div class="flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 py-1 pl-1 pr-3">
                    <div class="grid h-7 w-7 place-items-center rounded-lg bg-gradient-to-br from-sky-400 to-indigo-500 text-[11px] font-bold text-white">
                        <?= e(strtoupper(substr((string) $viewer['name'], 0, 2))) ?>
                    </div>
                    <div class="hidden leading-none md:block">
                        <p class="text-[12px] font-semibold text-white"><?= e((string) $viewer['name']) ?></p>
                        <p class="mt-0.5 text-[10px] font-medium uppercase tracking-wider text-slate-400">
                            <?= e(str_replace('_', ' ', $role)) ?>
                        </p>
                    </div>
                </div>

                <?php // An owner lives on this screen; without this there is no
                      // way off it. POST, because logout.php refuses a GET. ?>
                <?php if (Auth::check()): ?>
                    <form method="post" action="logout.php" class="inline">
                        <?= csrf_field() ?>
                        <button type="submit" title="Sign out"
                                class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-[12px] font-semibold text-slate-400 transition hover:border-rose-400/30 hover:bg-rose-500/10 hover:text-rose-300">
                            <span class="hidden sm:inline">Sign out</span>
                            <svg viewBox="0 0 24 24" class="h-4 w-4 sm:hidden" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>
                            </svg>
                        </button>
                    </form>
                <?php else: ?>
                    <a href="login.php"
                       class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-[12px] font-semibold text-slate-300 transition hover:bg-white/10">
                        Sign in
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-[1600px] px-4 py-5 sm:px-6 lg:py-7">
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-12">

            <!-- =================== LEFT: THE HAMMER =================== -->
            <section class="space-y-5 xl:col-span-8">

                <!-- ---------- Player under the hammer ---------- -->
                <article class="panel relative overflow-hidden rounded-3xl animate-slide-up">
                    <div class="stitch absolute inset-x-0 top-0 h-[3px] opacity-60"></div>

                    <div class="grid gap-6 p-5 sm:p-7 lg:grid-cols-[minmax(0,1fr)_320px]">

                        <!-- Player identity -->
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-emerald-400/15 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.14em] text-emerald-300">
                                    Under the hammer
                                </span>
                                <span class="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-300">
                                    Lot #<?= e((string) $lot['lot_order']) ?> · <?= e((string) ($lot['auction_set'] ?? 'Set A')) ?>
                                </span>
                                <?php if (!empty($lot['is_overseas'])): ?>
                                    <span class="rounded-full border border-sky-400/25 bg-sky-400/10 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-sky-300">
                                        Overseas
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="mt-4 flex items-start gap-4">
                                <!-- Avatar / photo slot -->
                                <div class="relative shrink-0">
                                    <div class="grid h-20 w-20 place-items-center rounded-2xl bg-gradient-to-br from-emerald-400/25 to-sky-500/20 text-2xl font-black text-white ring-1 ring-white/15 sm:h-24 sm:w-24">
                                        <?= e(strtoupper(substr((string) $lot['full_name'], 0, 1))) ?>
                                    </div>
                                    <span class="absolute -bottom-2 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-ink-900 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider text-slate-400 ring-1 ring-white/10">
                                        <?= e((string) $lot['country']) ?>
                                    </span>
                                </div>

                                <div class="min-w-0 pt-1">
                                    <h1 class="truncate text-3xl font-black tracking-tight text-white sm:text-4xl">
                                        <?= e((string) $lot['full_name']) ?>
                                    </h1>
                                    <p class="mt-1.5 text-sm font-semibold text-emerald-300">
                                        <?= e($roleLabels[$lot['role']] ?? (string) $lot['role']) ?>
                                    </p>
                                    <p class="mt-2 text-[12px] font-medium text-slate-400">
                                        Base price
                                        <span class="ml-1 font-mono font-bold text-slate-200"><?= e(money($lot['base_price'])) ?></span>
                                    </p>
                                </div>
                            </div>

                            <!-- Career strip -->
                            <dl class="mt-6 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                <?php
                                $stats = [
                                    ['Matches', (string) $lot['career_matches']],
                                    ['Runs',    number_format((float) $lot['career_runs'])],
                                    ['Wickets', (string) $lot['career_wickets']],
                                    ['SR',      number_format((float) $lot['strike_rate'], 1)],
                                ];
                                foreach ($stats as [$label, $value]): ?>
                                    <div class="rounded-xl border border-white/5 bg-white/[0.03] px-3 py-2.5">
                                        <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-500"><?= e($label) ?></dt>
                                        <dd class="mt-0.5 font-mono text-lg font-bold text-white"><?= e($value) ?></dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                        </div>

                        <!-- Bid + timer -->
                        <div class="flex flex-col items-center justify-center gap-4 rounded-2xl border border-white/10 bg-ink-900/50 p-5">

                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Current bid</p>

                            <p class="font-mono text-[42px] font-extrabold leading-none tracking-tight text-gold sm:text-5xl"
                               :class="flash && 'scale-105 transition-transform duration-200'"
                               x-text="fmt(currentBid)"></p>

                            <!-- Leading team -->
                            <template x-if="leader">
                                <div class="flex items-center gap-2 rounded-xl px-3 py-2 ring-1"
                                     :style="`background:${leader.color}1f; --tw-ring-color:${leader.color}59`">
                                    <span class="grid h-7 w-7 place-items-center rounded-lg text-[10px] font-black text-ink-900"
                                          :style="`background:${leader.color}`" x-text="leader.short"></span>
                                    <span class="text-[13px] font-bold text-white" x-text="leader.name"></span>
                                </div>
                            </template>
                            <template x-if="!leader">
                                <p class="text-[13px] font-semibold text-slate-500">No bids yet</p>
                            </template>

                            <!-- Countdown ring -->
                            <div class="relative mt-1 grid h-28 w-28 place-items-center">
                                <svg viewBox="0 0 100 100" class="absolute inset-0 -rotate-90">
                                    <circle cx="50" cy="50" r="44" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="7"/>
                                    <circle cx="50" cy="50" r="44" fill="none" stroke-width="7" stroke-linecap="round"
                                            :stroke="secondsLeft <= 5 ? '#f43f5e' : (secondsLeft <= 10 ? '#fbbf24' : '#22c55e')"
                                            :stroke-dasharray="276.5"
                                            :stroke-dashoffset="276.5 - (276.5 * Math.max(0, secondsLeft) / timer)"
                                            class="ring-sweep"/>
                                </svg>
                                <div class="text-center">
                                    <p class="font-mono text-3xl font-black leading-none"
                                       :class="secondsLeft <= 5 ? 'text-rose-400' : 'text-white'"
                                       x-text="secondsLeft"></p>
                                    <p class="mt-1 text-[9px] font-bold uppercase tracking-wider text-slate-500">seconds</p>
                                </div>
                            </div>

                            <p class="text-[11px] font-medium text-slate-500">
                                <span class="font-mono font-bold text-slate-300" x-text="bidCount"></span> bids ·
                                increment <span class="font-mono font-bold text-slate-300" x-text="fmt(increment)"></span>
                            </p>
                        </div>
                    </div>

                    <!-- ---------- Control rail (role-aware) ---------- -->
                    <div class="border-t border-white/10 bg-ink-900/40 p-5 sm:p-6">

                        <!-- TEAM OWNER -->
                        <?php if ($role === Auth::ROLE_OWNER): ?>
                            <div class="space-y-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Place your bid</p>
                                    <p class="text-[11px] font-medium text-slate-500">
                                        Slots left
                                        <span class="font-mono font-bold text-slate-200"
                                              x-text="myTeam ? (maxSquad - myTeam.bought) : '—'"></span>
                                    </p>
                                </div>

                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    <template x-for="step in [1, 2, 5, 10]" :key="step">
                                        <button type="button"
                                                @click="placeBid(step)"
                                                :disabled="!canAfford(nextBid(step)) || isLeading"
                                                class="group relative overflow-hidden rounded-xl border border-white/10 bg-white/5 px-3 py-4 text-center transition
                                                       enabled:hover:-translate-y-0.5 enabled:hover:border-emerald-400/40 enabled:hover:bg-emerald-400/10
                                                       enabled:active:translate-y-0 disabled:cursor-not-allowed disabled:opacity-35">
                                            <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 group-enabled:group-hover:text-emerald-300"
                                                  x-text="'+ ' + fmt(increment * step)"></span>
                                            <span class="mt-1 block font-mono text-lg font-black text-white" x-text="fmt(nextBid(step))"></span>
                                        </button>
                                    </template>
                                </div>

                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <button type="button" @click="placeBid(1)"
                                            :disabled="!canAfford(nextBid(1)) || isLeading"
                                            class="flex-1 rounded-xl bg-gradient-to-r from-emerald-400 to-emerald-500 px-6 py-4 text-base font-black uppercase tracking-wide text-ink-900
                                                   shadow-lg shadow-emerald-500/25 transition enabled:animate-pulse-ring enabled:hover:brightness-110
                                                   disabled:cursor-not-allowed disabled:from-slate-700 disabled:to-slate-700 disabled:text-slate-500 disabled:shadow-none">
                                        <span x-show="!isLeading && canAfford(nextBid(1))">Bid <span class="font-mono" x-text="fmt(nextBid(1))"></span></span>
                                        <span x-show="isLeading">You're leading</span>
                                        <span x-show="!isLeading && !canAfford(nextBid(1))">Insufficient purse</span>
                                    </button>
                                    <button type="button" @click="toast('Passed on this lot', 'muted')"
                                            class="rounded-xl border border-white/10 bg-white/5 px-6 py-4 text-sm font-bold uppercase tracking-wide text-slate-300 transition hover:bg-white/10">
                                        Pass
                                    </button>
                                </div>

                                <p class="text-[11px] leading-relaxed text-slate-500">
                                    Bids are validated server-side against
                                    <span class="font-semibold text-slate-400">purse_remaining</span> and
                                    <span class="font-semibold text-slate-400">max_squad_size</span> inside a
                                    <span class="font-mono text-slate-400">SELECT … FOR UPDATE</span> transaction, so two
                                    owners clicking in the same millisecond can never both win the lot.
                                </p>
                            </div>

                        <!-- ADMIN -->
                        <?php elseif ($role === Auth::ROLE_ADMIN): ?>
                            <div class="space-y-4">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Auctioneer controls</p>
                                <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                                    <button type="button" @click="hammer('sold')"
                                            :disabled="!leader"
                                            class="rounded-xl bg-gradient-to-r from-emerald-400 to-emerald-500 px-4 py-4 text-sm font-black uppercase tracking-wide text-ink-900 shadow-lg shadow-emerald-500/25 transition enabled:hover:brightness-110 disabled:cursor-not-allowed disabled:from-slate-700 disabled:to-slate-700 disabled:text-slate-500 disabled:shadow-none">
                                        Sold
                                    </button>
                                    <button type="button" @click="hammer('unsold')"
                                            class="rounded-xl border border-rose-400/30 bg-rose-500/10 px-4 py-4 text-sm font-black uppercase tracking-wide text-rose-300 transition hover:bg-rose-500/20">
                                        Unsold
                                    </button>
                                    <button type="button" @click="paused = !paused; toast(paused ? 'Auction paused' : 'Auction resumed', 'muted')"
                                            class="rounded-xl border border-white/10 bg-white/5 px-4 py-4 text-sm font-black uppercase tracking-wide text-slate-200 transition hover:bg-white/10">
                                        <span x-text="paused ? 'Resume' : 'Pause'"></span>
                                    </button>
                                    <button type="button" @click="secondsLeft = timer; toast('Timer reset', 'muted')"
                                            class="rounded-xl border border-white/10 bg-white/5 px-4 py-4 text-sm font-black uppercase tracking-wide text-slate-200 transition hover:bg-white/10">
                                        Reset timer
                                    </button>
                                </div>
                                <p class="text-[11px] text-slate-500">
                                    Closing a lot writes <span class="font-mono text-slate-400">auction_lots.sold_price</span>,
                                    flips <span class="font-mono text-slate-400">players.status</span> to
                                    <span class="font-mono text-slate-400">sold</span> and increments
                                    <span class="font-mono text-slate-400">teams.purse_spent</span> — one transaction, all or nothing.
                                </p>
                            </div>

                        <!-- SCORER -->
                        <?php elseif ($role === Auth::ROLE_SCORER): ?>
                            <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-bold text-white">No live match right now</p>
                                    <p class="mt-1 text-[12px] text-slate-400">
                                        Next fixture: Titan Strikers vs Royal Chargers · Chinnaswamy
                                    </p>
                                </div>
                                <a href="score.php" class="rounded-xl bg-gradient-to-r from-sky-400 to-indigo-500 px-5 py-3 text-sm font-black uppercase tracking-wide text-white shadow-lg shadow-sky-500/20 transition hover:brightness-110">
                                    Open scoring pad
                                </a>
                            </div>

                        <!-- VIEWER -->
                        <?php else: ?>
                            <div class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3">
                                <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
                                </svg>
                                <p class="text-[13px] font-medium text-slate-400">
                                    You're watching in read-only mode. Bids update live.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>

                <!-- ---------- Bid feed ---------- -->
                <article class="panel rounded-3xl p-5 sm:p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">Bid feed</h2>
                        <span class="rounded-full bg-white/5 px-2.5 py-1 font-mono text-[11px] font-bold text-slate-400"
                              x-text="bids.length + ' events'"></span>
                    </div>

                    <ul class="space-y-2">
                        <template x-for="(bid, i) in bids.slice(0, 6)" :key="bid.id ?? i">
                            <li class="flex items-center gap-3 rounded-xl border border-white/5 bg-white/[0.03] px-3 py-2.5 animate-ticker-in"
                                :class="i === 0 && 'border-emerald-400/25 bg-emerald-400/[0.07]'">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-[11px] font-black text-ink-900"
                                      :style="`background:${bid.color}`" x-text="bid.short"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13px] font-semibold text-white" x-text="bid.team"></p>
                                    <p class="text-[11px] text-slate-500" x-text="bid.at"></p>
                                </div>
                                <span class="font-mono text-sm font-bold"
                                      :class="i === 0 ? 'text-gold' : 'text-slate-300'"
                                      x-text="fmt(bid.amount)"></span>
                            </li>
                        </template>
                        <li x-show="bids.length === 0" class="rounded-xl border border-dashed border-white/10 px-3 py-6 text-center text-[13px] text-slate-500">
                            Waiting for the first bid…
                        </li>
                    </ul>
                </article>

                <!-- ---------- Up next ---------- -->
                <article class="panel rounded-3xl p-5 sm:p-6">
                    <h2 class="mb-4 text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">Up next</h2>
                    <div class="no-bar -mx-1 flex gap-3 overflow-x-auto px-1 pb-1">
                        <?php foreach ($state['queue'] as $q): ?>
                            <div class="w-44 shrink-0 rounded-2xl border border-white/8 bg-white/[0.03] p-3.5 transition hover:border-emerald-400/30 hover:bg-emerald-400/[0.06]">
                                <div class="flex items-center justify-between">
                                    <span class="font-mono text-[10px] font-bold text-slate-500">#<?= e((string) $q['lot_order']) ?></span>
                                    <?php if (!empty($q['is_overseas'])): ?>
                                        <span class="rounded bg-sky-400/15 px-1.5 py-0.5 text-[9px] font-bold uppercase text-sky-300">OS</span>
                                    <?php endif; ?>
                                </div>
                                <p class="mt-2 truncate text-[13px] font-bold text-white"><?= e((string) $q['display_name']) ?></p>
                                <p class="mt-0.5 text-[11px] font-medium text-slate-400"><?= e($roleLabels[$q['role']] ?? (string) $q['role']) ?></p>
                                <p class="mt-2.5 font-mono text-[12px] font-bold text-emerald-300"><?= e(money($q['base_price'])) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            </section>

            <!-- =================== RIGHT: PURSE + SALES =================== -->
            <aside class="space-y-5 self-start xl:sticky xl:top-[84px] xl:col-span-4">

                <!-- Purse board -->
                <section class="panel rounded-3xl p-5 sm:p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">Purse board</h2>
                        <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">
                            Squad cap <?= e((string) $t['max_squad_size']) ?>
                        </span>
                    </div>

                    <ul class="space-y-3">
                        <template x-for="team in sortedTeams" :key="team.id">
                            <li class="rounded-2xl border border-white/5 bg-white/[0.03] p-3.5 transition"
                                :class="{
                                    'border-emerald-400/40 bg-emerald-400/[0.08]': leader && team.id === leader.id,
                                    'ring-1 ring-white/15': team.id === myTeamId
                                }">
                                <div class="flex items-center gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl text-[11px] font-black text-ink-900"
                                          :style="`background:${team.color}`" x-text="team.short"></span>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-baseline justify-between gap-2">
                                            <p class="truncate text-[13px] font-bold text-white" x-text="team.name"></p>
                                            <span class="shrink-0 font-mono text-[13px] font-bold text-emerald-300" x-text="fmt(team.remaining)"></span>
                                        </div>
                                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-white/10">
                                            <div class="h-full rounded-full transition-all duration-500"
                                                 :style="`width:${(team.spent / team.total * 100).toFixed(1)}%; background:${team.color}`"></div>
                                        </div>
                                        <div class="mt-1.5 flex items-center justify-between text-[10px] font-medium text-slate-500">
                                            <span><span class="font-mono font-bold text-slate-400" x-text="team.bought"></span> players</span>
                                            <span x-text="'spent ' + fmt(team.spent)"></span>
                                        </div>
                                    </div>
                                </div>

                                <p x-show="leader && team.id === leader.id"
                                   class="mt-2.5 flex items-center gap-1.5 text-[10px] font-black uppercase tracking-wider text-emerald-300">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span> Leading bid
                                </p>
                            </li>
                        </template>
                    </ul>
                </section>

                <!-- Recent sales -->
                <section class="panel rounded-3xl p-5 sm:p-6">
                    <h2 class="mb-4 text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">Recent sales</h2>
                    <ul class="divide-y divide-white/5">
                        <?php foreach ($state['sold'] as $s): ?>
                            <li class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-[10px] font-black text-ink-900"
                                      style="background: <?= e((string) $s['primary_color']) ?>">
                                    <?= e((string) $s['short_name']) ?>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13px] font-semibold text-white"><?= e((string) $s['display_name']) ?></p>
                                    <p class="text-[11px] text-slate-500"><?= e($roleLabels[$s['role']] ?? (string) $s['role']) ?></p>
                                </div>
                                <span class="font-mono text-[13px] font-bold text-slate-300"><?= e(money($s['sold_price'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <!-- Auction progress -->
                <section class="panel rounded-3xl p-5 sm:p-6">
                    <h2 class="mb-4 text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">Session</h2>
                    <dl class="grid grid-cols-3 gap-2 text-center">
                        <?php
                        $totalSpend = array_sum(array_map(static fn ($x) => (float) $x['purse_spent'], $teams));
                        $session = [
                            ['Sold',   (string) count($state['sold'])],
                            ['Queued', (string) count($state['queue'])],
                            ['Spend',  money($totalSpend)],
                        ];
                        foreach ($session as [$label, $value]): ?>
                            <div class="rounded-xl border border-white/5 bg-white/[0.03] px-2 py-3">
                                <dt class="text-[9px] font-bold uppercase tracking-wider text-slate-500"><?= e($label) ?></dt>
                                <dd class="mt-1 font-mono text-base font-black text-white"><?= e($value) ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                </section>
            </aside>
        </div>
    </main>

    <!-- ===================== MOBILE BOTTOM BAR ===================== -->
    <?php if ($role === Auth::ROLE_OWNER): ?>
        <div class="safe-bottom fixed inset-x-0 bottom-0 z-40 border-t border-white/10 bg-ink-900/95 px-4 py-3 backdrop-blur-xl lg:hidden">
            <div class="flex items-center gap-3">
                <div class="min-w-0">
                    <p class="text-[9px] font-bold uppercase tracking-wider text-slate-500">Current</p>
                    <p class="font-mono text-lg font-black leading-none text-gold" x-text="fmt(currentBid)"></p>
                </div>
                <button type="button" @click="placeBid(1)"
                        :disabled="!canAfford(nextBid(1)) || isLeading"
                        class="ml-auto flex-1 rounded-xl bg-gradient-to-r from-emerald-400 to-emerald-500 px-4 py-3.5 text-sm font-black uppercase tracking-wide text-ink-900
                               disabled:from-slate-700 disabled:to-slate-700 disabled:text-slate-500">
                    <span x-show="!isLeading && canAfford(nextBid(1))">Bid <span class="font-mono" x-text="fmt(nextBid(1))"></span></span>
                    <span x-show="isLeading">Leading</span>
                    <span x-show="!isLeading && !canAfford(nextBid(1))">No funds</span>
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================== TOASTS ========================== -->
    <div class="pointer-events-none fixed bottom-24 right-4 z-50 flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-2 lg:bottom-6">
        <template x-for="t in toasts" :key="t.id">
            <div class="animate-slide-up rounded-xl border px-4 py-3 text-[13px] font-semibold shadow-2xl backdrop-blur-xl"
                 :class="{
                    'border-emerald-400/30 bg-emerald-500/15 text-emerald-200': t.kind === 'success',
                    'border-rose-400/30 bg-rose-500/15 text-rose-200':          t.kind === 'error',
                    'border-white/10 bg-ink-800/90 text-slate-300':             t.kind === 'muted'
                 }"
                 x-text="t.message"></div>
        </template>
    </div>
</div>

</body>
</html>
