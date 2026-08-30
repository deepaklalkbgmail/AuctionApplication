<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Administrator's hub
 * =====================================================================
 *
 *  What needs doing, and where to do it. The counts at the top are the
 *  two queues that hold everything else up: people waiting to be approved,
 *  and applications waiting to be let into a tournament.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/app/Views/layouts/shell.php';

use App\Core\Auth;

Auth::require(Auth::ROLE_ADMIN, Auth::ROLE_TADMIN);

// A tournament administrator sees their own tournament and nothing else.
// $mine is NULL for an administrator, which every query below reads as
// "no restriction".
$isAdmin = Auth::is(Auth::ROLE_ADMIN);
$mine    = $isAdmin ? null : Auth::tournamentId();

// Approving a player's ACCOUNT is an administrator's alone, so this queue
// is not counted for anybody else.
$pendingUsers = $isAdmin
    ? (int) Database::scalar("SELECT COUNT(*) FROM users WHERE status = 'pending'")
    : 0;

$pendingApplications = (int) Database::scalar(
    "SELECT COUNT(*) FROM tournament_registrations
      WHERE status = 'pending' AND (:all = 1 OR tournament_id = :t)",
    [':all' => $mine === null ? 1 : 0, ':t' => $mine]
);

$tournamentCount = $isAdmin ? (int) Database::scalar('SELECT COUNT(*) FROM tournaments') : 1;
$teamCount       = (int) Database::scalar(
    'SELECT COUNT(*) FROM teams WHERE (:all = 1 OR tournament_id = :t)',
    [':all' => $mine === null ? 1 : 0, ':t' => $mine]
);
$playerCount     = (int) Database::scalar(
    'SELECT COUNT(*) FROM players WHERE (:all = 1 OR tournament_id = :t)',
    [':all' => $mine === null ? 1 : 0, ':t' => $mine]
);
$scorerCount     = (int) Database::scalar(
    "SELECT COUNT(*) FROM users WHERE role = 'scorer' AND (:all = 1 OR tournament_id = :t)",
    [':all' => $mine === null ? 1 : 0, ':t' => $mine]
);

$links = [
    ['href' => 'index.php',       'label' => 'Overview', 'current' => true],
];

// People and Tournaments are an administrator's: one holds account
// approval, the other creates and cancels seasons.
if ($isAdmin) {
    $links[] = ['href' => 'users.php',       'label' => 'People'];
    $links[] = ['href' => 'tournaments.php', 'label' => 'Tournaments'];
}

$links[] = ['href' => 'applications.php', 'label' => 'Applications'];
$links[] = ['href' => 'players.php',      'label' => 'Players'];
$links[] = ['href' => 'teams.php',        'label' => 'Teams'];
$links[] = ['href' => 'auction.php',      'label' => 'Auction'];

page_head('Administration', '../', $links);
page_message();
?>

<h1 class="text-2xl font-extrabold tracking-tight text-white">Administration</h1>
<?php if ($isAdmin): ?>
    <p class="mt-2 text-sm text-slate-400">Everything that needs a decision, in one place.</p>
<?php else: ?>
    <?php $myTournament = $mine === null ? null : Database::one(
        'SELECT name, season_year FROM tournaments WHERE id = :t', [':t' => $mine]
    ); ?>
    <p class="mt-2 text-sm text-slate-400">
        <?php if ($myTournament !== null): ?>
            You run <strong class="text-slate-200"><?= e((string) $myTournament['name']) ?>
            <?= e((string) $myTournament['season_year']) ?></strong>. Everything below is that tournament.
        <?php else: ?>
            <span class="font-semibold text-amber-300">You have not been given a tournament yet.</span>
            An administrator sets that under People.
        <?php endif; ?>
    </p>
<?php endif; ?>

<!-- ------------------------------------------------------------ queues -->
<?php // One card for a tournament administrator, two for an administrator.
      // Two columns with one card in them leaves half the row empty. ?>
<div class="mt-6 grid gap-4 <?= $isAdmin ? 'sm:grid-cols-2' : '' ?>">

    <?php if ($isAdmin): ?>
    <a href="users.php?status=pending"
       class="group rounded-2xl border p-6 transition <?= $pendingUsers > 0
           ? 'border-amber-400/30 bg-amber-400/[0.07] hover:bg-amber-400/[0.11]'
           : 'border-white/10 bg-white/[0.03] hover:bg-white/[0.05]' ?>">
        <p class="text-[11px] font-bold uppercase tracking-wider <?= $pendingUsers > 0 ? 'text-amber-300' : 'text-slate-500' ?>">
            Registrations to approve
        </p>
        <p class="mt-2 text-4xl font-black tracking-tight <?= $pendingUsers > 0 ? 'text-amber-200' : 'text-slate-300' ?>">
            <?= $pendingUsers ?>
        </p>
        <p class="mt-2 text-[12px] text-slate-400">
            <?= $pendingUsers > 0
                ? 'Players waiting to be let into the application stage.'
                : 'Nobody is waiting.' ?>
        </p>
    </a>
    <?php endif; ?>

    <a href="applications.php"
       class="group rounded-2xl border p-6 transition <?= $pendingApplications > 0
           ? 'border-emerald-400/30 bg-emerald-500/[0.07] hover:bg-emerald-500/[0.11]'
           : 'border-white/10 bg-white/[0.03] hover:bg-white/[0.05]' ?>">
        <p class="text-[11px] font-bold uppercase tracking-wider <?= $pendingApplications > 0 ? 'text-emerald-300' : 'text-slate-500' ?>">
            Tournament applications
        </p>
        <p class="mt-2 text-4xl font-black tracking-tight <?= $pendingApplications > 0 ? 'text-emerald-200' : 'text-slate-300' ?>">
            <?= $pendingApplications ?>
        </p>
        <p class="mt-2 text-[12px] text-slate-400">
            <?= $pendingApplications > 0
                ? 'Approving one puts that player into the auction list.'
                : 'Nothing waiting.' ?>
        </p>
    </a>
</div>

<!-- ------------------------------------------------------------ counts -->
<div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
    <?php foreach ([
        'Tournaments' => $tournamentCount,
        'Teams'       => $teamCount,
        'Players'     => $playerCount,
        'Scorers'     => $scorerCount,
    ] as $label => $count): ?>
        <div class="rounded-2xl border border-white/10 bg-white/[0.03] px-5 py-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500"><?= e($label) ?></p>
            <p class="mt-1 text-2xl font-black tracking-tight text-white"><?= (int) $count ?></p>
        </div>
    <?php endforeach; ?>
</div>

<!-- ------------------------------------------------------------- tasks -->
<h2 class="mt-10 text-[13px] font-bold uppercase tracking-wider text-slate-400">What you can do here</h2>

<div class="mt-4 grid gap-4 sm:grid-cols-2">
    <?php
    // The two administrator-only screens are left out for a tournament
    // administrator rather than shown and then refused — a card that 403s
    // when you click it is worse than no card.
    $tasks = [];

    if ($isAdmin) {
        $tasks[] = ['users.php', 'People',
            'Approve registrations, correct anybody\'s details — including the name and email a player cannot change — create scorers and tournament administrators, and reset passwords.'];
        $tasks[] = ['tournaments.php', 'Tournaments',
            'Create a tournament with its four dates, edit any of its details, read off its secret code, open or close entries, and cancel one.'];
    }

    $tasks[] = ['applications.php', 'Applications',
        'Decide who gets into each tournament. Approving is what puts a player into the auction list.'];
    $tasks[] = ['players.php', 'Players',
        'Correct anything approval set — base price, auction set, type of player — and the career figures the auction card shows.'];
    $tasks[] = ['teams.php', 'Teams',
        'Create each team, name its one owner, and edit its name, colour, home ground' . ($isAdmin ? ' and purse.' : '.')];
    $tasks[] = ['auction.php', 'Run the auction',
        'The auctioneer\'s sheet. Call each lot in the room, then record the price it went for and the team that bought it.'];
    $tasks[] = ['../score.php', 'Score a match',
        'The scorer\'s pad. Ball by ball, with an undo.'];
    ?>
    <?php foreach ($tasks as [$href, $title, $blurb]): ?>
        <a href="<?= e($href) ?>"
           class="rounded-2xl border border-white/10 bg-white/[0.03] p-5 transition hover:border-emerald-400/25 hover:bg-white/[0.05]">
            <p class="text-sm font-extrabold text-white"><?= e($title) ?></p>
            <p class="mt-1.5 text-[12px] leading-relaxed text-slate-400"><?= e($blurb) ?></p>
        </a>
    <?php endforeach; ?>
</div>

<?php page_foot(); ?>
