<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  CricAuction — Scorer's pad
 * =====================================================================
 *  Ball-by-ball entry, built thumb-first: the scorer is standing at a
 *  boundary rope holding a phone in one hand, often in sunlight.
 *
 *  Layout rules that follow from that
 *    • Every scoring control is at least 64px tall — comfortably above the
 *      44px minimum tap target, because a mis-tap here corrupts the match.
 *    • The six run buttons sit in the bottom half of the screen, inside
 *      thumb reach, and never move or reflow between balls.
 *    • Destructive and rare actions (wicket, undo) are visually separated
 *      from the run pad so they cannot be hit by accident.
 *    • The live score stays pinned to the top; the scorer must never have
 *      to scroll to confirm what they just entered.
 *    • Nothing depends on hover, and every control has a visible label —
 *      colour alone never carries meaning.
 *
 *  State
 *    The innings is an append-only array of balls, exactly like the
 *    `ball_by_ball` table. Every number on screen — totals, the batting
 *    card, bowling figures, the over chips — is derived from that array,
 *    never stored separately. That is what makes Undo a one-line pop, and
 *    it is the same shape the server-side aggregation will use.
 *
 *  Phase 3 wires recordBall() to POST /api/scoring.php; the derivation
 *  below stays as the optimistic local view.
 */

require_once dirname(__DIR__) . '/config/config.php';

use App\Core\Auth;
use App\Core\Security;
use App\Services\ScoringService;

$demo = require dirname(__DIR__) . '/database/demo_match.php';

$match   = $demo['match'];
$innings = $demo['innings'];

// Signed out, this is a spectator's scorecard — so name nobody. It used to
// fall back to a demonstration scorer, which meant a signed-out visitor on a
// clean installation was shown a stranger's name as if they were scoring.
$scorer = Auth::user() ?? ['name' => 'Not signed in', 'role' => Auth::ROLE_VIEWER];

// ---------------------------------------------------------------------
//  Live mode
//
//  Needs three things: a signed-in scorer or admin, a reachable database,
//  and an open innings. Missing any of them, the pad runs on the demo match
//  and keeps the innings in the browser — so the interface is still usable
//  and reviewable, it just isn't persisting.
// ---------------------------------------------------------------------
$INNINGS_ID = filter_input(INPUT_GET, 'innings', FILTER_VALIDATE_INT) ?: 1;

$live    = false;
$initial = null;

$canScore = Auth::check() && Auth::is(Auth::ROLE_SCORER, Auth::ROLE_ADMIN);

if (Database::isAvailable()) {
    try {
        // Read the card for anyone — a viewer follows the same match, they
        // simply cannot save. Only a scorer or admin gets a writable pad.
        $initial = (new ScoringService())->scorecard($INNINGS_ID);
        $live    = $canScore;

        $match['overs_per_innings'] = $initial['innings']['overs_limit'];
        $match['balls_per_over']    = $initial['innings']['balls_per_over'];
        $innings['batting_team']    = ['name' => $initial['innings']['batting_team'],
                                       'short_name' => $initial['innings']['batting_short']];
        $innings['bowling_team']    = ['name' => $initial['innings']['bowling_team'],
                                       'short_name' => $initial['innings']['bowling_short']];
        $innings['target']          = $initial['innings']['target'];
    } catch (Throwable $e) {
        error_log('[scorer] no live innings: ' . $e->getMessage());
    }
}

// Same rule as the auction board: no invented match on a production site.
if ($initial === null && IS_PRODUCTION) {
    $emptyTitle = 'No match is being scored';
    $emptyBody  = 'When a fixture goes live and its first innings is opened, the scoring pad and the live scorecard appear here.';
    $emptyHint  = Auth::is(Auth::ROLE_ADMIN, Auth::ROLE_SCORER)
        ? 'A match needs status "live", both playing elevens recorded, and an open innings. Section 4.3 of the User Guide has the steps.'
        : null;

    require dirname(__DIR__) . '/app/Views/partials/empty_state.php';
    exit;
}

$bootstrap = Security::json([
    'csrf'         => Security::csrfToken(),
    'live'         => $live,
    'apiUrl'       => 'api/scoring.php',
    'matchId'      => (int) $match['id'],
    'inningsId'    => $live ? $INNINGS_ID : (int) $innings['id'],
    'oversLimit'   => (int) $match['overs_per_innings'],
    'ballsPerOver' => (int) $match['balls_per_over'],
    'target'       => $innings['target'],
    'battingXi'    => $live ? $initial['squads']['batting'] : $demo['batting_xi'],
    'bowlingXi'    => $live ? $initial['squads']['bowling'] : $demo['bowling_xi'],
    'opening'      => $demo['opening'],
    // Server-rendered first payload, so a live pad never flashes demo data.
    'initial'      => $initial,
]);

?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
    <meta name="theme-color" content="#020617">
    <title>Scorer — <?= e((string) $innings['batting_team']['short_name']) ?> v <?= e((string) $innings['bowling_team']['short_name']) ?></title>

    <!-- Self-hosted. Tailwind is built ahead of time (see tailwind.config.js)
         and Alpine is vendored, so the page needs no external origin and
         satisfies a `script-src 'self'` / `style-src 'self'` policy. -->
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2032%2032%22%3E%3Crect%20width%3D%2232%22%20height%3D%2232%22%20rx%3D%227%22%20fill%3D%22%2322c55e%22%2F%3E%3Cpath%20d%3D%22M8.5%2024%2019%2013.5M17.5%206.5%2025.5%2014.5%2021.5%2018.5%2013.5%2010.5z%22%20stroke%3D%22%23020617%22%20stroke-width%3D%222.6%22%20fill%3D%22none%22%20stroke-linecap%3D%22round%22%2F%3E%3Ccircle%20cx%3D%228%22%20cy%3D%2224.5%22%20r%3D%222.3%22%20fill%3D%22%23020617%22%2F%3E%3C%2Fsvg%3E">
    <link rel="stylesheet" href="assets/css/app.css">

    <!-- Order matters: Alpine calls start() as soon as it runs, so the
         component that x-data references must already be defined. Deferred
         scripts execute in document order, which guarantees that. -->
    <script defer src="assets/js/scorer.js"></script>
    <script defer src="assets/js/alpine.js"></script>

</head>

<body class="bg-pitch min-h-screen font-sans text-slate-200 antialiased">

<div x-data="scorer(<?= e($bootstrap) ?>)" x-cloak
     @keydown.window="hotkey($event)"
     class="mx-auto max-w-[1500px] px-3 pb-6 sm:px-5">

    <!-- ======================= LIVE SCOREBOARD (pinned) ======================= -->
    <header class="sticky top-0 z-30 -mx-3 mb-3 border-b border-white/10 bg-ink-900/90 px-3 pb-3 pt-3 backdrop-blur-xl sm:-mx-5 sm:px-5">

        <div class="mb-2.5 flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-rose-400/30 bg-rose-500/10 px-2.5 py-1 text-[10px] font-black uppercase tracking-wider text-rose-300">
                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-rose-400"></span> Live
            </span>
            <p class="truncate text-[11px] font-semibold text-slate-400">
                <?= e((string) $match['toss_text']) ?> · <?= e((string) $match['venue']) ?>
            </p>
            <a href="index.php" class="ml-auto shrink-0 rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-400 transition hover:text-slate-200">Home</a>
            <span class="shrink-0 rounded-lg border px-2 py-1 text-[10px] font-bold uppercase tracking-wider
                         <?= $live ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-300' : 'border-white/10 bg-white/5 text-slate-400' ?>">
                <?= $live ? 'Saving' : 'Demo' ?>
            </span>
            <span class="shrink-0 rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <?= e((string) $scorer['name']) ?>
            </span>
            <?php // A scorer spends the whole match here. POST, because
                  // logout.php refuses a GET. ?>
            <?php if (Auth::check()): ?>
                <form method="post" action="logout.php" class="inline shrink-0">
                    <?= csrf_field() ?>
                    <button type="submit" title="Sign out"
                            class="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-400 transition hover:border-rose-400/30 hover:bg-rose-500/10 hover:text-rose-300">
                        Out
                    </button>
                </form>
            <?php else: ?>
                <a href="login.php" class="shrink-0 rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-400 transition hover:text-slate-200">
                    Sign in
                </a>
            <?php endif; ?>
        </div>

        <div class="flex items-end gap-4">
            <!-- Score -->
            <div class="min-w-0">
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">
                    <?= e((string) $innings['batting_team']['name']) ?>
                </p>
                <p class="font-mono text-[40px] font-black leading-none tracking-tight text-white sm:text-5xl">
                    <span x-text="totals.runs"></span><span class="text-slate-500">/</span><span
                          :class="totals.wickets >= 8 ? 'text-rose-400' : 'text-white'" x-text="totals.wickets"></span>
                </p>
            </div>

            <!-- Overs -->
            <div class="pb-1">
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">Overs</p>
                <p class="font-mono text-2xl font-bold leading-none text-emerald-300">
                    <span x-text="oversText"></span><span class="text-sm text-slate-500">/<?= e((string) $match['overs_per_innings']) ?></span>
                </p>
            </div>

            <!-- Rates -->
            <dl class="ml-auto flex shrink-0 gap-2">
                <div class="rounded-xl border border-white/5 bg-white/[0.04] px-2.5 py-1.5 text-center">
                    <dt class="text-[9px] font-bold uppercase tracking-wider text-slate-500">CRR</dt>
                    <dd class="font-mono text-base font-bold text-white" x-text="crr"></dd>
                </div>
                <template x-if="target">
                    <div class="rounded-xl border border-gold/25 bg-gold/10 px-2.5 py-1.5 text-center">
                        <dt class="text-[9px] font-bold uppercase tracking-wider text-gold/70">RRR</dt>
                        <dd class="font-mono text-base font-bold text-gold" x-text="rrr"></dd>
                    </div>
                </template>
            </dl>
        </div>

        <!-- This over -->
        <div class="mt-2.5 flex items-center gap-2">
            <span class="shrink-0 text-[10px] font-black uppercase tracking-wider text-slate-500">This over</span>
            <div class="no-bar flex flex-1 gap-1.5 overflow-x-auto">
                <template x-for="(chip, i) in thisOver" :key="i">
                    <span class="grid h-8 min-w-[2rem] shrink-0 animate-pop-in place-items-center rounded-lg px-1.5 font-mono text-[13px] font-black"
                          :class="chip.tone"
                          x-text="chip.label"></span>
                </template>
                <span x-show="thisOver.length === 0" class="text-[12px] font-medium text-slate-600">—</span>
            </div>
        </div>
    </header>

    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_360px]">

        <!-- ============================ LEFT COLUMN ============================ -->
        <div class="space-y-3">

            <!-- ---------- Batters + bowler ---------- -->
            <section class="panel rounded-2xl p-3 sm:p-4">
                <div class="mb-2 grid grid-cols-[1fr_auto_auto_auto_auto] gap-2 px-1 text-[9px] font-black uppercase tracking-wider text-slate-500">
                    <span>Batter</span><span class="w-9 text-right">R</span><span class="w-9 text-right">B</span>
                    <span class="w-8 text-right">4s</span><span class="w-12 text-right">SR</span>
                </div>

                <template x-for="who in ['striker', 'nonStriker']" :key="who">
                    <div class="grid grid-cols-[1fr_auto_auto_auto_auto] items-center gap-2 rounded-xl px-1 py-2"
                         :class="who === 'striker' && 'bg-emerald-400/[0.09] ring-1 ring-emerald-400/25'">
                        <span class="flex min-w-0 items-center gap-1.5">
                            <!-- me() is null between a wicket and the next batter walking in -->
                            <span class="truncate text-[14px] font-bold"
                                  :class="me(who) ? 'text-white' : 'text-slate-600'"
                                  x-text="me(who) ? me(who).name : 'batter to come'"></span>
                            <span x-show="who === 'striker' && me(who)" class="shrink-0 text-emerald-400" aria-label="on strike">*</span>
                        </span>
                        <span class="w-9 text-right font-mono text-[15px] font-bold text-white" x-text="card(me(who)?.id).runs"></span>
                        <span class="w-9 text-right font-mono text-[13px] text-slate-400" x-text="card(me(who)?.id).faced"></span>
                        <span class="w-8 text-right font-mono text-[13px] text-slate-400" x-text="card(me(who)?.id).fours"></span>
                        <span class="w-12 text-right font-mono text-[13px] text-slate-400" x-text="card(me(who)?.id).sr"></span>
                    </div>
                </template>

                <div class="mt-2 flex items-center gap-2 border-t border-white/8 pt-2.5">
                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-amber-400/15 text-[10px] font-black text-amber-300">B</span>
                    <!-- bowler is null until the opening bowler is named -->
                    <span class="truncate text-[14px] font-bold"
                          :class="bowler ? 'text-white' : 'text-slate-600'"
                          x-text="bowler ? bowler.name : 'bowler to come'"></span>
                    <span class="ml-auto shrink-0 font-mono text-[13px] font-bold text-slate-300">
                        <span x-text="bowlerCard(bowler?.id).overs"></span>-<span x-text="bowlerCard(bowler?.id).maidens"></span>-<span
                              x-text="bowlerCard(bowler?.id).conceded"></span>-<span class="text-emerald-300" x-text="bowlerCard(bowler?.id).wickets"></span>
                    </span>
                    <span class="shrink-0 rounded-lg bg-white/5 px-2 py-1 font-mono text-[11px] text-slate-400">
                        econ <span x-text="bowlerCard(bowler?.id).econ"></span>
                    </span>
                </div>
            </section>

            <!-- ================================================================
                 THE PAD — everything below is sized for a thumb
                 ================================================================ -->
            <!-- max-w keeps the keys a thumb's width on a tablet or laptop
                 instead of stretching to 450px each; mobile is unaffected. -->
            <section class="panel mx-auto w-full max-w-3xl rounded-2xl p-3 sm:p-4" aria-label="Scoring controls">

                <!-- Blocking prompt: no scoring until the gap is filled -->
                <div x-show="needsBatter || needsBowler"
                     class="mb-3 flex items-center gap-2 rounded-xl border border-gold/30 bg-gold/10 px-3 py-2.5">
                    <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 text-gold" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
                    </svg>
                    <p class="text-[13px] font-bold text-gold" x-text="needsBatter ? 'Select the next batter' : 'Select the next bowler'"></p>
                    <button type="button" @click="needsBatter ? (modal = 'batter') : (modal = 'bowler')"
                            class="key ml-auto rounded-lg bg-gold px-3 py-2 text-[12px] font-black uppercase tracking-wide text-ink-900">
                        Choose
                    </button>
                </div>

                <!-- ---------- Runs: the six keys used on ~95% of balls ---------- -->
                <p class="mb-2 px-0.5 text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">Runs off the bat</p>

                <div class="grid grid-cols-3 gap-2 sm:gap-2.5">
                    <template x-for="r in [0, 1, 2, 3, 4, 6]" :key="r">
                        <button type="button"
                                @click="scoreRuns(r)"
                                :disabled="locked"
                                :aria-label="r + ' runs'"
                                class="key grid h-[76px] place-items-center rounded-2xl border font-mono text-4xl font-black sm:h-[88px] sm:text-5xl"
                                :class="{
                                    'border-sky-400/40 bg-sky-500/15 text-sky-300':        r === 4,
                                    'border-violet-400/40 bg-violet-500/15 text-violet-300': r === 6,
                                    'border-white/10 bg-white/[0.06] text-white':          r !== 4 && r !== 6
                                }">
                            <span x-text="r"></span>
                        </button>
                    </template>
                </div>

                <!-- ---------- Extras ---------- -->
                <p class="mb-2 mt-4 px-0.5 text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">Extras</p>

                <div class="grid grid-cols-4 gap-2">
                    <template x-for="x in extraKeys" :key="x.type">
                        <button type="button"
                                @click="openExtra(x.type)"
                                :disabled="locked"
                                :aria-label="x.aria"
                                class="key grid h-[64px] place-items-center rounded-xl border border-amber-400/25 bg-amber-400/10 px-1 text-amber-200">
                            <span class="text-lg font-black" x-text="x.short"></span>
                            <span class="text-[9px] font-bold uppercase tracking-wider opacity-70" x-text="x.label"></span>
                        </button>
                    </template>
                </div>

                <!-- ---------- Wicket: deliberately isolated ---------- -->
                <button type="button"
                        @click="openWicket()"
                        :disabled="locked"
                        class="key mt-4 grid h-[72px] w-full place-items-center rounded-2xl border-2 border-rose-500/50 bg-rose-600/25 text-rose-200">
                    <span class="text-2xl font-black uppercase tracking-[0.2em]">Wicket</span>
                </button>

                <!-- ---------- Secondary actions ---------- -->
                <div class="mt-3 grid grid-cols-3 gap-2">
                    <button type="button" @click="undo()" :disabled="balls.length === 0"
                            class="key flex h-[56px] items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 text-[13px] font-black uppercase tracking-wide text-slate-200">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
                            <path d="M3 7v6h6"/><path d="M3.5 13a9 9 0 1 0 2.1-6.4L3 9"/>
                        </svg>
                        Undo
                    </button>
                    <!-- In live mode the server derives the strike from the
                         ball log, so a manual swap would be overwritten by
                         the next response. Correct a mistake with Undo. -->
                    <button type="button" @click="swapStrike()" :disabled="locked || live"
                            :title="live ? 'Strike is derived from the ball log — use Undo to correct a mistake' : 'Swap the strike'"
                            class="key flex h-[56px] items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 text-[13px] font-black uppercase tracking-wide text-slate-200">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
                            <path d="M7 4 3 8l4 4"/><path d="M3 8h13"/><path d="m17 20 4-4-4-4"/><path d="M21 16H8"/>
                        </svg>
                        Swap
                    </button>
                    <button type="button" @click="modal = 'bowler'"
                            class="key flex h-[56px] items-center justify-center rounded-xl border border-white/10 bg-white/5 text-[13px] font-black uppercase tracking-wide text-slate-200">
                        Bowler
                    </button>
                </div>

                <p class="mt-3 text-center text-[10px] font-medium text-slate-600">
                    Keyboard: <span class="font-mono text-slate-500">0–6</span> runs ·
                    <span class="font-mono text-slate-500">W</span> wicket ·
                    <span class="font-mono text-slate-500">D</span> wide ·
                    <span class="font-mono text-slate-500">N</span> no-ball ·
                    <span class="font-mono text-slate-500">U</span> undo
                </p>
            </section>
        </div>

        <!-- ============================ RIGHT COLUMN ============================ -->
        <aside class="space-y-3">

            <!-- Extras breakdown -->
            <section class="panel rounded-2xl p-3 sm:p-4">
                <h2 class="mb-2.5 text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">Extras</h2>
                <div class="grid grid-cols-5 gap-1.5 text-center">
                    <template x-for="e in extrasBreakdown" :key="e.label">
                        <div class="rounded-lg border border-white/5 bg-white/[0.03] px-1 py-2">
                            <p class="text-[9px] font-bold uppercase tracking-wider text-slate-500" x-text="e.label"></p>
                            <p class="mt-0.5 font-mono text-base font-bold text-white" x-text="e.value"></p>
                        </div>
                    </template>
                </div>
            </section>

            <!-- Commentary -->
            <section class="panel rounded-2xl p-3 sm:p-4">
                <div class="mb-2.5 flex items-center justify-between">
                    <h2 class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">Commentary</h2>
                    <span class="rounded-full bg-white/5 px-2 py-0.5 font-mono text-[10px] font-bold text-slate-400"
                          x-text="balls.length + ' balls'"></span>
                </div>

                <ul class="max-h-[420px] space-y-1.5 overflow-y-auto">
                    <template x-for="b in [...balls].reverse().slice(0, 30)" :key="b.seq">
                        <li class="flex items-start gap-2 rounded-lg border border-white/5 bg-white/[0.03] px-2.5 py-2 animate-slide-up">
                            <span class="mt-0.5 shrink-0 font-mono text-[11px] font-bold text-slate-500" x-text="b.overLabel"></span>
                            <span class="flex-1 text-[12px] leading-snug text-slate-300" x-text="b.text"></span>
                        </li>
                    </template>
                    <li x-show="balls.length === 0"
                        class="rounded-lg border border-dashed border-white/10 px-3 py-6 text-center text-[12px] text-slate-600">
                        First ball not bowled yet
                    </li>
                </ul>
            </section>
        </aside>
    </div>

    <!-- ============================== MODALS ============================== -->

    <!-- Extra runs -->
    <div x-show="modal === 'extra'" @click.self="modal = null"
         class="fixed inset-0 z-50 grid place-items-end bg-black/70 p-3 backdrop-blur-sm sm:place-items-center">
        <div class="panel w-full max-w-md rounded-2xl p-4 animate-slide-up">
            <h3 class="text-lg font-black text-white" x-text="extraTitle"></h3>
            <p class="mt-1 text-[12px] text-slate-400" x-text="extraHint"></p>

            <p class="mb-2 mt-4 text-[10px] font-black uppercase tracking-wider text-slate-500" x-text="extraRunsLabel"></p>
            <div class="grid grid-cols-5 gap-2">
                <template x-for="n in [0, 1, 2, 3, 4]" :key="n">
                    <button type="button" @click="confirmExtra(n)"
                            class="key grid h-[64px] place-items-center rounded-xl border border-amber-400/25 bg-amber-400/10 font-mono text-2xl font-black text-amber-200"
                            x-text="n"></button>
                </template>
            </div>

            <button type="button" @click="modal = null"
                    class="key mt-3 h-12 w-full rounded-xl border border-white/10 bg-white/5 text-[13px] font-bold uppercase tracking-wide text-slate-300">
                Cancel
            </button>
        </div>
    </div>

    <!-- Wicket -->
    <div x-show="modal === 'wicket'" @click.self="modal = null"
         class="fixed inset-0 z-50 grid place-items-end bg-black/70 p-3 backdrop-blur-sm sm:place-items-center">
        <div class="panel max-h-[92vh] w-full max-w-md overflow-y-auto rounded-2xl p-4 animate-slide-up">
            <h3 class="text-lg font-black text-rose-300">How was the batter out?</h3>

            <div class="mt-3 grid grid-cols-2 gap-2">
                <template x-for="d in dismissals" :key="d.type">
                    <button type="button" @click="wicket.type = d.type"
                            class="key h-[58px] rounded-xl border px-2 text-[13px] font-bold"
                            :class="wicket.type === d.type
                                ? 'border-rose-400/60 bg-rose-500/25 text-white'
                                : 'border-white/10 bg-white/5 text-slate-300'"
                            x-text="d.label"></button>
                </template>
            </div>

            <!-- Run-outs need to know who, and how many were completed -->
            <template x-if="wicket.type === 'run_out'">
                <div>
                    <p class="mb-2 mt-4 text-[10px] font-black uppercase tracking-wider text-slate-500">Who is out?</p>
                    <div class="grid grid-cols-2 gap-2">
                        <template x-for="who in ['striker', 'nonStriker']" :key="who">
                            <button type="button" @click="wicket.who = who"
                                    class="key h-[54px] truncate rounded-xl border px-2 text-[13px] font-bold"
                                    :class="wicket.who === who
                                        ? 'border-rose-400/60 bg-rose-500/25 text-white'
                                        : 'border-white/10 bg-white/5 text-slate-300'"
                                    x-text="me(who).name"></button>
                        </template>
                    </div>

                    <p class="mb-2 mt-4 text-[10px] font-black uppercase tracking-wider text-slate-500">Runs completed</p>
                    <div class="grid grid-cols-4 gap-2">
                        <template x-for="n in [0, 1, 2, 3]" :key="n">
                            <button type="button" @click="wicket.runs = n"
                                    class="key h-[54px] rounded-xl border font-mono text-xl font-black"
                                    :class="wicket.runs === n
                                        ? 'border-rose-400/60 bg-rose-500/25 text-white'
                                        : 'border-white/10 bg-white/5 text-slate-300'"
                                    x-text="n"></button>
                        </template>
                    </div>
                </div>
            </template>

            <template x-if="['caught', 'run_out', 'stumped'].includes(wicket.type)">
                <div>
                    <label for="fielder" class="mb-1.5 mt-4 block text-[10px] font-black uppercase tracking-wider text-slate-500">Fielder</label>
                    <select id="fielder" x-model="wicket.fielderId"
                            class="h-12 w-full rounded-xl border border-white/10 bg-ink-900 px-3 text-[14px] font-semibold text-white">
                        <option value="">— not recorded —</option>
                        <template x-for="f in bowlingXi" :key="f.id">
                            <option :value="f.id" x-text="f.name"></option>
                        </template>
                    </select>
                </div>
            </template>

            <div class="mt-4 grid grid-cols-2 gap-2">
                <button type="button" @click="modal = null"
                        class="key h-14 rounded-xl border border-white/10 bg-white/5 text-[13px] font-bold uppercase tracking-wide text-slate-300">
                    Cancel
                </button>
                <button type="button" @click="confirmWicket()"
                        class="key h-14 rounded-xl bg-rose-600 text-[13px] font-black uppercase tracking-wide text-white">
                    Confirm out
                </button>
            </div>
        </div>
    </div>

    <!-- Next batter -->
    <div x-show="modal === 'batter'" @click.self="if (!needsBatter) modal = null"
         class="fixed inset-0 z-50 grid place-items-end bg-black/70 p-3 backdrop-blur-sm sm:place-items-center">
        <div class="panel max-h-[92vh] w-full max-w-md overflow-y-auto rounded-2xl p-4 animate-slide-up">
            <h3 class="text-lg font-black text-white">Next batter in</h3>
            <div class="mt-3 space-y-2">
                <template x-for="p in availableBatters" :key="p.id">
                    <button type="button" @click="sendInBatter(p)"
                            class="key flex h-[58px] w-full items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-3 text-left">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-emerald-400/15 font-mono text-[12px] font-bold text-emerald-300"
                              x-text="p.order"></span>
                        <span class="truncate text-[15px] font-bold text-white" x-text="p.name"></span>
                    </button>
                </template>
                <p x-show="availableBatters.length === 0" class="py-6 text-center text-[13px] text-slate-500">
                    All out — innings complete.
                </p>
            </div>
        </div>
    </div>

    <!-- Next bowler -->
    <div x-show="modal === 'bowler'" @click.self="if (!needsBowler) modal = null"
         class="fixed inset-0 z-50 grid place-items-end bg-black/70 p-3 backdrop-blur-sm sm:place-items-center">
        <div class="panel max-h-[92vh] w-full max-w-md overflow-y-auto rounded-2xl p-4 animate-slide-up">
            <h3 class="text-lg font-black text-white">Bowler for the next over</h3>
            <p class="mt-1 text-[12px] text-slate-400">A bowler cannot bowl consecutive overs.</p>
            <div class="mt-3 space-y-2">
                <template x-for="p in bowlingXi" :key="p.id">
                    <button type="button" @click="setBowler(p)" :disabled="p.id === lastOverBowlerId"
                            class="key flex h-[58px] w-full items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-3 text-left">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[15px] font-bold text-white" x-text="p.name"></span>
                            <span class="block truncate text-[10px] font-medium uppercase tracking-wider text-slate-500" x-text="p.style"></span>
                        </span>
                        <span class="shrink-0 font-mono text-[12px] text-slate-400"
                              x-text="bowlerCard(p.id).overs + '-' + bowlerCard(p.id).conceded + '-' + bowlerCard(p.id).wickets"></span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    <!-- Toasts. Only ever one: stacked messages would sit on top of the
         Undo / Swap row, which is exactly what a scorer reaches for next. -->
    <div class="safe-bottom-pin pointer-events-none fixed left-1/2 z-[60] w-[min(24rem,calc(100vw-2rem))] -translate-x-1/2">
        <template x-for="t in toasts.slice(-1)" :key="t.id">
            <div class="animate-slide-up rounded-xl border px-4 py-3 text-center text-[13px] font-bold shadow-2xl backdrop-blur-xl"
                 :class="{
                    'border-emerald-400/30 bg-emerald-500/20 text-emerald-100': t.kind === 'success',
                    'border-rose-400/30 bg-rose-500/20 text-rose-100':          t.kind === 'error',
                    'border-white/10 bg-ink-800/95 text-slate-200':             t.kind === 'muted'
                 }"
                 x-text="t.message"></div>
        </template>
    </div>
</div>

</body>
</html>
