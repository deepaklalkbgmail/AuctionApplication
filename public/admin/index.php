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

Auth::require(Auth::ROLE_ADMIN);

$pendingUsers = (int) Database::scalar(
    "SELECT COUNT(*) FROM users WHERE status = 'pending'"
);

$pendingApplications = (int) Database::scalar(
    "SELECT COUNT(*) FROM tournament_registrations WHERE status = 'pending'"
);

$tournamentCount = (int) Database::scalar('SELECT COUNT(*) FROM tournaments');
$teamCount       = (int) Database::scalar('SELECT COUNT(*) FROM teams');
$playerCount     = (int) Database::scalar('SELECT COUNT(*) FROM players');
$scorerCount     = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role = 'scorer'");

$links = [
    ['href' => 'index.php',       'label' => 'Overview', 'current' => true],
    ['href' => 'users.php',       'label' => 'People'],
    ['href' => 'tournaments.php', 'label' => 'Tournaments'],
    ['href' => 'applications.php','label' => 'Applications'],
    ['href' => 'teams.php',       'label' => 'Teams'],
    ['href' => 'auction.php',     'label' => 'Auction'],
];

page_head('Administration', '../', $links);
page_message();
?>

<h1 class="text-2xl font-extrabold tracking-tight text-white">Administration</h1>
<p class="mt-2 text-sm text-slate-400">Everything that needs a decision, in one place.</p>

<!-- ------------------------------------------------------------ queues -->
<div class="mt-6 grid gap-4 sm:grid-cols-2">

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
    <?php foreach ([
        ['users.php', 'People',
         'Approve registrations, correct anybody\'s details — including the name and email a player cannot change — create scorer accounts, and reset passwords.'],
        ['tournaments.php', 'Tournaments',
         'Create a tournament with its four dates, read off its secret code, and open or close entries.'],
        ['applications.php', 'Applications',
         'Decide who gets into each tournament. Approving is what puts a player into the auction list.'],
        ['teams.php', 'Teams',
         'Create each team and name its one owner. The owner sets the team name and can change it until the deadline.'],
        ['auction.php', 'Run the auction',
         'The auctioneer\'s sheet. Call each lot in the room, then record the price it went for and the team that bought it.'],
        ['../score.php', 'Score a match',
         'The scorer\'s pad. Ball by ball, with an undo.'],
    ] as [$href, $title, $blurb]): ?>
        <a href="<?= e($href) ?>"
           class="rounded-2xl border border-white/10 bg-white/[0.03] p-5 transition hover:border-emerald-400/25 hover:bg-white/[0.05]">
            <p class="text-sm font-extrabold text-white"><?= e($title) ?></p>
            <p class="mt-1.5 text-[12px] leading-relaxed text-slate-400"><?= e($blurb) ?></p>
        </a>
    <?php endforeach; ?>
</div>

<?php page_foot(); ?>
