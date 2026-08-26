<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  The auction board, for everybody
 * =====================================================================
 *
 *  A complete, standalone, read-only page: purses, what has been sold,
 *  and who is still to be called.
 *
 *  It exists because the auction is now called aloud in the room and
 *  recorded afterwards, so no lot is ever "live" in the application. The
 *  live-bidding board only renders when one is, which left a spectator
 *  looking at "No auction is running" through an entire auction — every
 *  player sold, every purse moved, and nothing to see.
 *
 *  No JavaScript. Reload to refresh; there is nothing here that changes
 *  between one press of the auctioneer's hammer and the next.
 *
 *  Expects, from the including page:
 *    $boardTournament  the tournament row
 *    $boardTeams       teams with purses
 *    $boardSold        closed lots, newest first
 *    $boardToCall      lots still queued
 *    $boardOwners      team id => owner's name
 *    $boardSquads      team id => the players they have bought
 *    $boardIsAdmin     whether to offer the admin's links
 */

/** @var array<string,mixed> $boardTournament */
/** @var array<int,array<string,mixed>> $boardTeams */
/** @var array<int,array<string,mixed>> $boardSold */
/** @var array<int,array<string,mixed>> $boardToCall */

$boardIsAdmin ??= false;
$boardOwners  ??= [];
$boardSquads  ??= [];

if (!function_exists('board_rupees')) {
    /** ₹12,34,567 — Indian grouping, as the room says it. */
    function board_rupees(float|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        $n     = (int) round((float) $amount);
        $s     = (string) abs($n);
        $last3 = substr($s, -3);
        $rest  = substr($s, 0, -3);

        if ($rest !== '') {
            $last3 = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last3;
        }

        return ($n < 0 ? '-' : '') . '₹' . $last3;
    }
}

$boardSpent = array_sum(array_map(static fn ($t) => (float) $t['purse_spent'], $boardTeams));

$boardLimits = [
    'max_squad_size' => (int) ($boardTournament['max_squad_size'] ?? 15),
    'max_overseas'   => (int) ($boardTournament['max_overseas'] ?? 4),
];

require_once __DIR__ . '/player_kinds.php';
require_once __DIR__ . '/team_card.php';

?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#020617">
    <title>Auction board — <?= e(APP_NAME) ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2032%2032%22%3E%3Crect%20width%3D%2232%22%20height%3D%2232%22%20rx%3D%227%22%20fill%3D%22%2322c55e%22%2F%3E%3Cpath%20d%3D%22M8.5%2024%2019%2013.5M17.5%206.5%2025.5%2014.5%2021.5%2018.5%2013.5%2010.5z%22%20stroke%3D%22%23020617%22%20stroke-width%3D%222.6%22%20fill%3D%22none%22%20stroke-linecap%3D%22round%22%2F%3E%3Ccircle%20cx%3D%228%22%20cy%3D%2224.5%22%20r%3D%222.3%22%20fill%3D%22%23020617%22%2F%3E%3C%2Fsvg%3E">
    <link rel="stylesheet" href="assets/css/app.css">
    <?php team_card_styles(); ?>

    <?php /* Refreshes the figures in place every few seconds. External, not
             inline, because the deployed policy allows no inline script; and
             an enhancement, not a requirement — with it blocked or broken the
             page is exactly what it was before, a board you reload. */ ?>
    <script defer src="assets/js/board-live.js"></script>
</head>

<body class="bg-arena min-h-screen font-sans text-slate-200">

<header class="border-b border-white/10 bg-slate-950/50">
    <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-5 gap-y-3 px-4 py-3.5">
        <a href="index.php" class="flex items-center gap-2.5">
            <span class="grid h-9 w-9 place-items-center rounded-lg bg-gradient-to-br from-emerald-400 to-emerald-600">
                <svg viewBox="0 0 24 24" class="h-5 w-5 text-slate-950" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                    <path d="M4.5 19.5 14 10"/><path d="M13 3.8 20.2 11l-3.4 3.4L9.6 7.2z"/><circle cx="5" cy="19" r="1.6" fill="currentColor" stroke="none"/>
                </svg>
            </span>
            <span class="text-[15px] font-extrabold tracking-tight text-white"><?= e(APP_NAME) ?></span>
        </a>

        <p class="text-[12px] text-slate-400">
            <?= e((string) $boardTournament['name']) ?> · <?= e((string) $boardTournament['season_year']) ?>
        </p>

        <div class="ml-auto flex items-center gap-3">
            <a href="score.php" class="text-[13px] font-semibold text-slate-400 hover:text-slate-200">Live scoring</a>
            <?php if (\App\Core\Auth::check()): ?>
                <?php if ($boardIsAdmin): ?>
                    <a href="admin/auction.php"
                       class="rounded-lg bg-emerald-500 px-3 py-1.5 text-[13px] font-bold text-slate-950 hover:brightness-110">Record a sale</a>
                <?php endif; ?>
                <form method="post" action="logout.php" class="inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="text-[13px] font-semibold text-slate-400 hover:text-rose-300">Sign out</button>
                </form>
            <?php else: ?>
                <a href="login.php" class="text-[13px] font-semibold text-slate-300 hover:text-emerald-300">Sign in</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="mx-auto max-w-5xl px-4 py-7" id="board">

    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-extrabold tracking-tight text-white">Auction board</h1>
        <p class="text-[13px] text-slate-400">
            <?= count($boardSold) ?> sold · <?= count($boardToCall) ?> still to call
        </p>
    </div>
    <p class="mt-1.5 text-[12px] text-slate-500">
        <?php /* The honest message is the one for a page with no scripting.
                 board-live.js replaces it the moment it starts, so nobody is
                 ever told the board is updating itself when it is not. */ ?>
        The auction is called in the room and each result recorded here.
        <span data-board-status>Reload to see the latest.</span>
    </p>

    <?php /* Once nothing is left to call, this page stops being a running
             commentary and becomes the record everybody comes back to. Say
             so, rather than leaving them to infer it from an absence. */ ?>
    <?php if ($boardToCall === [] && $boardSold !== []): ?>
        <p class="mt-5 rounded-2xl border border-emerald-400/25 bg-emerald-400/[0.07] px-4 py-3 text-[13px] font-semibold text-emerald-200">
            The auction is finished. These are the final squads — click any team to see it.
        </p>
    <?php endif; ?>

    <!-- ---------------------------------------------------------- purses -->
    <section class="mt-6">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 class="text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">Purse board</h2>
            <?php if ($boardTeams !== []): ?>
                <p class="text-[11px] text-slate-500">Click a team for its owner and squad</p>
            <?php endif; ?>
        </div>

        <?php if ($boardTeams === []): ?>
            <p class="mt-3 rounded-2xl border border-white/10 bg-white/[0.03] p-6 text-center text-[13px] text-slate-500">
                No teams yet.
            </p>
        <?php else: ?>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($boardTeams as $team):
                    $pct = (float) $team['purse_total'] > 0
                        ? min(100, ((float) $team['purse_spent'] / (float) $team['purse_total']) * 100)
                        : 0;
                    ?>
                    <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                        <div class="flex items-center gap-2.5">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-[11px] font-black text-slate-950"
                                  style="background: <?= e((string) $team['primary_color']) ?>">
                                <?= e((string) $team['short_name']) ?>
                            </span>
                            <p class="min-w-0 flex-1 truncate text-[13px] font-bold text-white"><?= team_card_link($team) ?></p>
                            <p class="shrink-0 text-base font-black text-emerald-400"><?= e(board_rupees($team['purse_remaining'])) ?></p>
                        </div>
                        <div class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-white/10">
                            <div class="h-full rounded-full bg-emerald-400/70" style="width: <?= number_format($pct, 1) ?>%"></div>
                        </div>
                        <p class="mt-2 flex justify-between text-[11px] text-slate-500">
                            <span><?= (int) $team['players_bought'] ?> bought</span>
                            <span>spent <?= e(board_rupees($team['purse_spent'])) ?></span>
                        </p>
                    </div>

                    <?php team_card(
                        $team,
                        $boardOwners[(int) $team['id']] ?? null,
                        $boardSquads[(int) $team['id']] ?? [],
                        $boardLimits
                    ); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ------------------------------------------------------------ sold -->
    <section class="mt-8">
        <h2 class="text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">
            Sold <span class="ml-1 text-slate-600">(<?= count($boardSold) ?>)</span>
        </h2>

        <?php if ($boardSold === []): ?>
            <p class="mt-3 rounded-2xl border border-white/10 bg-white/[0.03] p-6 text-center text-[13px] text-slate-500">
                Nothing sold yet. The first result appears here as soon as it is recorded.
            </p>
        <?php else: ?>
            <div class="mt-3 overflow-x-auto rounded-2xl border border-white/10 bg-white/[0.03]">
                <table class="w-full min-w-[30rem] text-left">
                    <thead>
                        <tr class="border-b border-white/10 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                            <th class="px-4 py-2.5">Player</th>
                            <th class="px-4 py-2.5">Team</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($boardSold as $row): ?>
                            <tr class="border-b border-white/5 last:border-0">
                                <td class="px-4 py-2.5">
                                    <span class="text-[13px] font-bold text-white"><?= e((string) $row['full_name']) ?></span>
                                    <span class="ml-1.5 text-[11px] text-slate-500"><?= e(player_kind((string) $row['role'])) ?></span>
                                </td>
                                <td class="px-4 py-2.5 text-[13px] text-slate-300">
                                    <?= team_card_link(['id' => $row['team_id'], 'name' => $row['team_name']]) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- -------------------------------------------------------- to call -->
    <?php if ($boardToCall !== []): ?>
        <section class="mt-8">
            <h2 class="text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">
                Still to call <span class="ml-1 text-slate-600">(<?= count($boardToCall) ?>)</span>
            </h2>
            <div class="mt-3 flex flex-wrap gap-2">
                <?php foreach ($boardToCall as $row): ?>
                    <span class="rounded-xl border border-white/10 bg-white/[0.03] px-3.5 py-2 text-[13px] text-slate-300">
                        <?= e((string) $row['full_name']) ?>
                        <span class="ml-1.5 text-[11px] text-slate-500">base <?= e(board_rupees($row['base_price'])) ?></span>
                    </span>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</main>

<footer class="mx-auto max-w-5xl px-4 pb-10 pt-2 text-center text-[11px] text-slate-600">
    <?= e(APP_NAME) ?> — auction and live scoring
</footer>

</body>
</html>
