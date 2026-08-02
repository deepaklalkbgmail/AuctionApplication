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

$scorer = Auth::user() ?? ['name' => 'Priya Nair', 'role' => Auth::ROLE_SCORER];

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

if (Auth::check() && Auth::is(Auth::ROLE_SCORER, Auth::ROLE_ADMIN) && Database::isAvailable()) {
    try {
        $initial = (new ScoringService())->scorecard($INNINGS_ID);
        $live    = true;

        $match['overs_per_innings'] = $initial['innings']['overs_limit'];
        $match['balls_per_over']    = $initial['innings']['balls_per_over'];
        $innings['batting_team']    = ['name' => $initial['innings']['batting_team'],
                                       'short_name' => $initial['innings']['batting_short']];
        $innings['bowling_team']    = ['name' => $initial['innings']['bowling_team'],
                                       'short_name' => $initial['innings']['bowling_short']];
        $innings['target']          = $initial['innings']['target'];
    } catch (Throwable $e) {
        error_log('[scorer] falling back to the demo match: ' . $e->getMessage());
    }
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

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        ink:  { 900: '#020617', 800: '#0b1220', 700: '#111a2e' },
                        gold: '#fbbf24',
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'ui-monospace', 'SFMono-Regular', 'monospace'],
                    },
                    keyframes: {
                        popIn:   { '0%': { transform: 'scale(.85)', opacity: 0 }, '100%': { transform: 'scale(1)', opacity: 1 } },
                        slideUp: { '0%': { opacity: 0, transform: 'translateY(10px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
                    },
                    animation: { 'pop-in': 'popIn .18s ease-out both', 'slide-up': 'slideUp .25s ease-out both' },
                },
            },
        };
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">

    <style>
        [x-cloak] { display: none !important; }

        body {
            background:
                radial-gradient(900px 420px at 12% -8%, rgba(34,197,94,.13), transparent 60%),
                radial-gradient(800px 400px at 92% 2%, rgba(56,189,248,.10), transparent 62%),
                #020617;
            -webkit-tap-highlight-color: transparent;
        }

        .panel {
            background: linear-gradient(160deg, rgba(255,255,255,.055), rgba(255,255,255,.015));
            border: 1px solid rgba(255,255,255,.08);
        }

        /* Every scoring control shares one press behaviour, so the whole pad
           feels like one instrument rather than a page of links. */
        .key {
            -webkit-user-select: none; user-select: none;
            touch-action: manipulation;
            transition: transform .06s ease, filter .12s ease, background-color .12s ease;
        }
        .key:active:not(:disabled) { transform: scale(.95); filter: brightness(1.15); }
        .key:disabled { opacity: .3; }
        .key:focus-visible { outline: 3px solid #38bdf8; outline-offset: 3px; }

        .no-bar { scrollbar-width: none; }
        .no-bar::-webkit-scrollbar { display: none; }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: .001ms !important; transition-duration: .001ms !important; }
        }
    </style>
</head>

<body class="min-h-screen font-sans text-slate-200 antialiased">

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
            <span class="ml-auto shrink-0 rounded-lg border px-2 py-1 text-[10px] font-bold uppercase tracking-wider
                         <?= $live ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-300' : 'border-white/10 bg-white/5 text-slate-400' ?>">
                <?= $live ? 'Saving' : 'Demo' ?>
            </span>
            <span class="shrink-0 rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <?= e((string) $scorer['name']) ?>
            </span>
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
    <div class="pointer-events-none fixed bottom-3 left-1/2 z-[60] w-[min(24rem,calc(100vw-2rem))] -translate-x-1/2"
         style="bottom: max(0.75rem, env(safe-area-inset-bottom))">
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

<script>
/**
 * Scorer state.
 *
 * `balls` is the single source of truth — an append-only log shaped exactly
 * like a `ball_by_ball` row. Totals, both cards, the over chips and the
 * commentary are all derived from it on read, so they cannot drift apart,
 * and Undo is just a pop plus restoring the snapshot taken before the ball.
 */
function scorer(init) {
    return {
        ...init,
        balls: [],
        striker: null,
        nonStriker: null,
        bowler: null,
        needsBatter: false,
        needsBowler: false,
        modal: null,
        pendingExtra: null,
        wicket: { type: 'bowled', fielderId: '', runs: 0, who: 'striker' },
        out: [],                 // ids of dismissed batters
        toasts: [],
        _seq: 0,

        // Live mode only. The server owns strike rotation, so the client
        // sends it just the facts it alone knows: the opening pair, a new
        // batter after a wicket, and the bowler for a new over. These hold
        // that selection until the next ball carries it.
        isOpening: false,
        pendingBatter: null,
        pendingBowler: null,
        busy: false,             // one ball in flight; blocks a double-tap

        extraKeys: [
            { type: 'wide',    short: 'WD', label: 'Wide',     aria: 'Wide' },
            { type: 'no_ball', short: 'NB', label: 'No ball',  aria: 'No ball' },
            { type: 'bye',     short: 'B',  label: 'Bye',      aria: 'Bye' },
            { type: 'leg_bye', short: 'LB', label: 'Leg bye',  aria: 'Leg bye' },
        ],

        dismissals: [
            { type: 'bowled',     label: 'Bowled' },
            { type: 'caught',     label: 'Caught' },
            { type: 'lbw',        label: 'LBW' },
            { type: 'run_out',    label: 'Run out' },
            { type: 'stumped',    label: 'Stumped' },
            { type: 'hit_wicket', label: 'Hit wicket' },
        ],

        init() {
            if (this.live) {
                this.hydrate(this.initial);
                return;
            }

            this.striker    = this.batter(this.opening.striker_id);
            this.nonStriker = this.batter(this.opening.non_striker_id);
            this.bowler     = this.bowlingXi.find(p => p.id === this.opening.bowler_id);
        },

        // ------------------------------------------------------------ lookups
        batter(id)  { return this.battingXi.find(p => p.id === id); },
        me(which)   { return which === 'striker' ? this.striker : this.nonStriker; },

        get locked() { return this.needsBatter || this.needsBowler || this.busy; },

        get availableBatters() {
            const crease = [this.striker?.id, this.nonStriker?.id];
            return this.battingXi
                .filter(p => !this.out.includes(p.id) && !crease.includes(p.id))
                .sort((a, b) => a.order - b.order);
        },

        get lastOverBowlerId() {
            // Only blocks while an over is complete and the next has not started.
            const legal = this.totals.legal;
            if (legal === 0 || legal % this.ballsPerOver !== 0) return null;
            return this.balls.filter(b => b.isLegal).slice(-1)[0]?.bowlerId ?? null;
        },

        // ------------------------------------------------------------ derived
        get totals() {
            const t = { runs: 0, wickets: 0, legal: 0, wide: 0, noBall: 0, bye: 0, legBye: 0 };

            for (const b of this.balls) {
                t.runs += b.runsOffBat + b.extraRuns;
                if (b.isLegal) t.legal++;
                if (b.isWicket) t.wickets++;
                if (b.extraType === 'wide')    t.wide   += b.extraRuns;
                if (b.extraType === 'no_ball') t.noBall += b.extraRuns;
                if (b.extraType === 'bye')     t.bye    += b.extraRuns;
                if (b.extraType === 'leg_bye') t.legBye += b.extraRuns;
            }

            t.extras = t.wide + t.noBall + t.bye + t.legBye;
            return t;
        },

        get extrasBreakdown() {
            const t = this.totals;
            return [
                { label: 'Wd', value: t.wide }, { label: 'Nb', value: t.noBall },
                { label: 'B', value: t.bye },   { label: 'Lb', value: t.legBye },
                { label: 'Total', value: t.extras },
            ];
        },

        get oversText() {
            const l = this.totals.legal;
            return `${Math.floor(l / this.ballsPerOver)}.${l % this.ballsPerOver}`;
        },

        get crr() {
            const l = this.totals.legal;
            return l === 0 ? '0.00' : (this.totals.runs / (l / this.ballsPerOver)).toFixed(2);
        },

        get rrr() {
            if (!this.target) return '—';
            const ballsLeft = this.oversLimit * this.ballsPerOver - this.totals.legal;
            if (ballsLeft <= 0) return '—';
            return (((this.target - this.totals.runs) / ballsLeft) * this.ballsPerOver).toFixed(2);
        },

        /**
         * Chips for the over on screen. The last ball recorded already knows
         * which over it belongs to, so a completed over stays visible until
         * the first ball of the next one replaces it — which is what a scorer
         * expects to see between overs.
         */
        get thisOver() {
            if (this.balls.length === 0) return [];

            const current = this.balls[this.balls.length - 1].over;

            return this.balls
                .filter(b => b.over === current)
                .map(b => ({ label: b.chip, tone: b.tone }));
        },

        card(id) {
            let runs = 0, faced = 0, fours = 0, sixes = 0;

            for (const b of this.balls) {
                if (b.strikerId !== id) continue;
                runs += b.runsOffBat;
                if (b.isLegal) faced++;
                if (b.isFour) fours++;
                if (b.isSix) sixes++;
            }

            return { runs, faced, fours, sixes, sr: faced ? (runs / faced * 100).toFixed(1) : '0.0' };
        },

        bowlerCard(id) {
            let legal = 0, conceded = 0, wickets = 0;
            const overRuns = {};

            for (const b of this.balls) {
                if (b.bowlerId !== id) continue;
                if (b.isLegal) legal++;

                // Byes and leg-byes are not charged to the bowler.
                const charged = b.runsOffBat
                    + (b.extraType === 'wide' || b.extraType === 'no_ball' ? b.extraRuns : 0);
                conceded += charged;

                overRuns[b.over] = (overRuns[b.over] ?? 0) + charged;

                if (b.isWicket && ['bowled', 'caught', 'lbw', 'stumped', 'hit_wicket'].includes(b.dismissal)) {
                    wickets++;
                }
            }

            const completed = Math.floor(legal / this.ballsPerOver);
            const maidens = Object.entries(overRuns)
                .filter(([over, runs]) =>
                    runs === 0 && this.balls.filter(b => b.over === +over && b.bowlerId === id && b.isLegal).length === this.ballsPerOver
                ).length;

            return {
                overs: `${completed}.${legal % this.ballsPerOver}`,
                maidens,
                conceded,
                wickets,
                econ: legal ? (conceded / (legal / this.ballsPerOver)).toFixed(2) : '0.00',
            };
        },

        // ------------------------------------------------------------ actions
        scoreRuns(runs) {
            this.record({ runsOffBat: runs });
        },

        openExtra(type) {
            this.pendingExtra = type;
            this.modal = 'extra';
        },

        get extraTitle() {
            return { wide: 'Wide', no_ball: 'No ball', bye: 'Byes', leg_bye: 'Leg byes' }[this.pendingExtra] ?? '';
        },

        get extraHint() {
            return {
                wide:    'One run is added automatically. The ball is not counted in the over.',
                no_ball: 'One run is added automatically, plus anything hit off the bat.',
                bye:     'Counts as a legal ball; the runs are not credited to the batter.',
                leg_bye: 'Counts as a legal ball; the runs are not credited to the batter.',
            }[this.pendingExtra] ?? '';
        },

        get extraRunsLabel() {
            return this.pendingExtra === 'no_ball' ? 'Runs off the bat' : 'Additional runs run';
        },

        confirmExtra(n) {
            const type = this.pendingExtra;
            this.modal = null;
            this.pendingExtra = null;

            if (type === 'wide') {
                this.record({ extraType: 'wide', extraRuns: 1 + n });
            } else if (type === 'no_ball') {
                this.record({ extraType: 'no_ball', extraRuns: 1, runsOffBat: n });
            } else {
                this.record({ extraType: type, extraRuns: n });
            }
        },

        openWicket() {
            this.wicket = { type: 'bowled', fielderId: '', runs: 0, who: 'striker' };
            this.modal = 'wicket';
        },

        confirmWicket() {
            const w = this.wicket;
            const out = w.type === 'run_out' ? this.me(w.who) : this.striker;

            this.modal = null;
            this.record({
                runsOffBat: w.type === 'run_out' ? w.runs : 0,
                isWicket: true,
                dismissal: w.type,
                dismissedId: out.id,
                fielderId: w.fielderId === '' ? null : +w.fielderId,
            });
        },

        /**
         * Append one ball and advance the innings.
         *
         * The snapshot in `before` is what makes Undo exact: it restores who
         * was on strike, who was bowling and who was out, rather than trying
         * to reverse the rules.
         */
        record(opts = {}) {
            if (this.locked) return this.toast('Fill the gap in the middle first', 'error');

            // Live mode: the server records the ball and hands back the whole
            // scorecard. Nothing is applied locally first — a ball that the
            // database rejected must never appear to have been scored.
            if (this.live) return this.postBall(opts);

            return this.recordLocally(opts);
        },

        recordLocally({ runsOffBat = 0, extraRuns = 0, extraType = 'none', isWicket = false,
                        dismissal = null, dismissedId = null, fielderId = null } = {}) {

            const isLegal = extraType !== 'wide' && extraType !== 'no_ball';
            const over = Math.floor(this.totals.legal / this.ballsPerOver);

            const ball = {
                seq: this.balls.length + 1,
                over,
                overLabel: `${over}.${(this.balls.filter(b => b.over === over).length) + 1}`,
                strikerId: this.striker.id,
                nonStrikerId: this.nonStriker.id,
                bowlerId: this.bowler.id,
                runsOffBat, extraRuns, extraType, isLegal, isWicket, dismissal, dismissedId, fielderId,
                isFour: runsOffBat === 4 && extraType === 'none',
                isSix:  runsOffBat === 6 && extraType === 'none',
                before: {
                    strikerId: this.striker.id,
                    nonStrikerId: this.nonStriker.id,
                    bowlerId: this.bowler.id,
                    out: [...this.out],
                },
            };

            ball.chip = this.chipFor(ball);
            ball.tone = this.toneFor(ball);
            ball.text = this.commentaryFor(ball);

            this.balls.push(ball);

            // --- strike rotation -------------------------------------------
            // Batters cross on odd runs, whether they came off the bat or were
            // run as byes / extra wides.
            let ran = runsOffBat;
            if (extraType === 'bye' || extraType === 'leg_bye') ran += extraRuns;
            if (extraType === 'wide') ran += extraRuns - 1;
            if (ran % 2 === 1) this.rotate();

            // --- wicket -----------------------------------------------------
            if (isWicket) {
                this.out.push(dismissedId);

                if (dismissedId === this.striker.id)          this.striker = null;
                else if (dismissedId === this.nonStriker.id)  this.nonStriker = null;

                this.needsBatter = true;
                this.modal = 'batter';
            }

            // --- end of over -------------------------------------------------
            if (isLegal && this.totals.legal % this.ballsPerOver === 0) {
                this.rotate();
                this.needsBowler = true;
                if (!this.needsBatter) this.modal = 'bowler';
                this.toast(`End of over ${Math.floor(this.totals.legal / this.ballsPerOver)}`, 'muted');
            }
        },

        async undo() {
            if (this.live) {
                const payload = await this.post('undo', {});

                if (payload) {
                    this.hydrate(payload);
                    this.toast('Last ball removed', 'muted');
                }

                return;
            }

            const ball = this.balls.pop();
            if (!ball) return;

            this.striker    = this.batter(ball.before.strikerId);
            this.nonStriker = this.batter(ball.before.nonStrikerId);
            this.bowler     = this.bowlingXi.find(p => p.id === ball.before.bowlerId);
            this.out        = [...ball.before.out];
            this.needsBatter = false;
            this.needsBowler = false;
            this.modal = null;

            this.toast(`Undone: ${ball.chip}`, 'muted');
        },

        rotate() {
            [this.striker, this.nonStriker] = [this.nonStriker, this.striker];
        },

        swapStrike() {
            this.rotate();
            this.toast(`${this.striker.name} on strike`, 'muted');
        },

        /**
         * Fills the vacant end. At the start of an innings both ends are
         * vacant, so this is called twice; after a wicket, once.
         */
        sendInBatter(p) {
            if (this.striker === null) this.striker = p;
            else if (this.nonStriker === null) this.nonStriker = p;

            // Held until the next ball carries it to the server.
            if (this.live && !this.isOpening) this.pendingBatter = p.id;

            this.needsBatter = this.striker === null || this.nonStriker === null;
            this.modal = this.needsBatter ? 'batter' : (this.needsBowler ? 'bowler' : null);
            this.toast(`${p.name} to the crease`, 'success');
        },

        setBowler(p) {
            this.bowler = p;

            if (this.live) this.pendingBowler = p.id;

            this.needsBowler = false;
            this.modal = this.needsBatter ? 'batter' : null;
            this.toast(`${p.name} into the attack`, 'success');
        },

        // ---------------------------------------------------------------- api
        /**
         * POST one delivery. The response is the entire scorecard, so the
         * client replaces its state instead of patching it — a dropped or
         * out-of-order response can never leave the pad half-updated.
         */
        async postBall({ runsOffBat = 0, extraRuns = 0, extraType = 'none', isWicket = false,
                         dismissal = null, dismissedId = null, fielderId = null } = {}) {

            const fields = {
                innings_id:   this.inningsId,
                runs_off_bat: runsOffBat,
                extra_runs:   extraRuns,
                extra_type:   extraType,
                is_wicket:    isWicket ? '1' : '0',
            };

            if (isWicket) {
                fields.dismissal_type = dismissal;
                if (dismissedId) fields.dismissed_player_id = dismissedId;
                if (fielderId)   fields.fielder_id = fielderId;
            }

            if (this.isOpening) {
                fields.striker_id     = this.striker.id;
                fields.non_striker_id = this.nonStriker.id;
                fields.bowler_id      = this.bowler.id;
            } else {
                if (this.pendingBatter) fields.new_batter_id = this.pendingBatter;
                if (this.pendingBowler) fields.bowler_id     = this.pendingBowler;
            }

            const payload = await this.post('ball', fields);
            if (payload) this.hydrate(payload);
        },

        /** Returns the payload, or null after showing why it was refused. */
        async post(action, fields) {
            if (this.busy) return null;
            this.busy = true;

            const body = new URLSearchParams({
                action,
                csrf_token: this.csrf,
                innings_id: this.inningsId,
                ...fields,
            });

            try {
                const res = await fetch(this.apiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': this.csrf, 'Accept': 'application/json' },
                    body,
                });

                const data = await res.json();

                if (!data.ok) {
                    this.toast(data.message || 'That ball was refused', 'error');

                    // The server can also be telling us what it still needs.
                    if (data.error === 'NEEDS_BATTER')  { this.needsBatter = true; this.modal = 'batter'; }
                    if (data.error === 'NEEDS_BOWLER')  { this.needsBowler = true; this.modal = 'bowler'; }
                    if (data.error === 'NEEDS_OPENING') { this.isOpening = true; this.needsBatter = true; }

                    return null;
                }

                return data;
            } catch (e) {
                this.toast('Could not reach the scoring server — nothing was recorded', 'error');
                return null;
            } finally {
                this.busy = false;
            }
        },

        /** Replace all local state with the server's scorecard. */
        hydrate(payload) {
            if (!payload || !payload.balls) return;

            const previous = this.balls.length;

            this.balls = payload.balls.map(b => this.fromServer(b));

            const st = payload.state || {};

            if (st.needs_opening) {
                this.isOpening  = true;
                this.striker    = null;
                this.nonStriker = null;
                this.bowler     = null;
                this.needsBatter = true;
                this.needsBowler = true;
            } else {
                this.isOpening   = false;
                this.striker     = st.striker_id ? this.batter(st.striker_id) : null;
                this.nonStriker  = st.non_striker_id ? this.batter(st.non_striker_id) : null;
                this.bowler      = st.bowler_id ? this.bowlingXi.find(p => p.id === st.bowler_id) : null;
                this.needsBatter = !!st.needs_batter;
                this.needsBowler = !!st.needs_bowler;
            }

            this.out = st.out || [];
            this.pendingBatter = null;
            this.pendingBowler = null;

            if (this.needsBatter)      this.modal = 'batter';
            else if (this.needsBowler) this.modal = 'bowler';
            else if (this.modal === 'batter' || this.modal === 'bowler') this.modal = null;

            if (payload.innings && payload.innings.target !== undefined) {
                this.target = payload.innings.target;
            }

            if (this.balls.length > previous) {
                this.toast(this.balls[this.balls.length - 1].chip === 'W'
                    ? 'Wicket recorded'
                    : `Recorded: ${this.balls[this.balls.length - 1].chip}`, 'success');
            }
        },

        /** Server row -> the shape the rest of this component already uses. */
        fromServer(b) {
            const ball = {
                seq: b.seq,
                over: b.over,
                overLabel: `${b.over}.${b.ball_in_over}`,
                strikerId: b.striker_id,
                nonStrikerId: b.non_striker_id,
                bowlerId: b.bowler_id,
                runsOffBat: b.runs_off_bat,
                extraRuns: b.extra_runs,
                extraType: b.extra_type,
                isLegal: b.is_legal,
                isFour: b.is_four,
                isSix: b.is_six,
                isWicket: b.is_wicket,
                dismissal: b.dismissal_type,
                dismissedId: b.dismissed_player_id,
                fielderId: b.fielder_id,
            };

            ball.chip = this.chipFor(ball);
            ball.tone = this.toneFor(ball);
            ball.text = this.commentaryFor(ball);

            return ball;
        },

        // -------------------------------------------------------------- labels
        chipFor(b) {
            if (b.isWicket) return 'W';
            if (b.extraType === 'wide')    return 'wd' + (b.extraRuns > 1 ? b.extraRuns - 1 : '');
            if (b.extraType === 'no_ball') return 'nb' + (b.runsOffBat > 0 ? b.runsOffBat : '');
            if (b.extraType === 'bye')     return b.extraRuns + 'b';
            if (b.extraType === 'leg_bye') return b.extraRuns + 'lb';
            return String(b.runsOffBat);
        },

        toneFor(b) {
            if (b.isWicket)                 return 'bg-rose-600 text-white';
            if (b.extraType !== 'none')     return 'bg-amber-400/20 text-amber-200';
            if (b.runsOffBat === 6)         return 'bg-violet-500/25 text-violet-200';
            if (b.runsOffBat === 4)         return 'bg-sky-500/25 text-sky-200';
            if (b.runsOffBat === 0)         return 'bg-white/8 text-slate-400';
            return 'bg-white/10 text-white';
        },

        commentaryFor(b) {
            const bat = this.batter(b.strikerId)?.name ?? '';
            const bowl = this.bowlingXi.find(p => p.id === b.bowlerId)?.name ?? '';
            const head = `${bowl} to ${bat}, `;

            if (b.isWicket) {
                const who = this.batter(b.dismissedId)?.name ?? bat;
                return `${head}OUT — ${who}, ${b.dismissal.replace('_', ' ')}`;
            }
            if (b.extraType === 'wide')    return `${head}wide${b.extraRuns > 1 ? ` +${b.extraRuns - 1}` : ''}`;
            if (b.extraType === 'no_ball') return `${head}no ball${b.runsOffBat ? `, ${b.runsOffBat} off the bat` : ''}`;
            if (b.extraType === 'bye')     return `${head}${b.extraRuns} bye${b.extraRuns === 1 ? '' : 's'}`;
            if (b.extraType === 'leg_bye') return `${head}${b.extraRuns} leg bye${b.extraRuns === 1 ? '' : 's'}`;
            if (b.runsOffBat === 0)        return `${head}no run`;
            if (b.runsOffBat === 4)        return `${head}FOUR`;
            if (b.runsOffBat === 6)        return `${head}SIX`;
            return `${head}${b.runsOffBat} run${b.runsOffBat === 1 ? '' : 's'}`;
        },

        hotkey(e) {
            if (this.modal || e.metaKey || e.ctrlKey || e.altKey) return;
            if (['INPUT', 'SELECT', 'TEXTAREA'].includes(e.target.tagName)) return;

            const k = e.key.toLowerCase();

            if (['0', '1', '2', '3', '4', '6'].includes(k)) { e.preventDefault(); this.scoreRuns(+k); }
            else if (k === 'w') { e.preventDefault(); this.openWicket(); }
            else if (k === 'd') { e.preventDefault(); this.openExtra('wide'); }
            else if (k === 'n') { e.preventDefault(); this.openExtra('no_ball'); }
            else if (k === 'u') { e.preventDefault(); this.undo(); }
        },

        toast(message, kind = 'muted') {
            const id = ++this._seq;
            this.toasts.push({ id, message, kind });
            setTimeout(() => (this.toasts = this.toasts.filter(t => t.id !== id)), 1800);
        },
    };
}
</script>
</body>
</html>
