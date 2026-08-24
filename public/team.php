<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  My team
 * =====================================================================
 *
 *  An owner names their team, and may rename it up to the tournament's
 *  team name change deadline — the point of which is that a name can be
 *  settled with the squad after the auction, then frozen.
 *
 *  A team comes into existence when an administrator creates it and names
 *  its owner. That is deliberate: if any signed-in account could create
 *  one, the tournament's team list would be whatever the internet decided.
 *  Everything after that — the name, the short name, the colour, the
 *  ground — is the owner's, until the deadline.
 *
 *  One team has one owner. That is a unique index on users.team_id, not a
 *  check in this file.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/app/Views/layouts/shell.php';

use App\Core\Auth;
use App\Core\Security;
use App\Exceptions\AccountException;
use App\Services\TournamentService;

Auth::require();

$tournaments = new TournamentService();
$userId      = (int) Auth::id();
$teamId      = Auth::teamId();
$error       = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $teamId !== null) {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            $tournaments->renameTeam($teamId, $userId, $_POST);
            flash('success', 'Your team has been updated.');
            header('Location: team.php');
            exit;
        } catch (AccountException $e) {
            $error = $e->getMessage();
        }
    }
}

$team = $teamId === null ? null : Database::one(
    'SELECT t.*, tr.name AS tournament_name, tr.season_year,
            tr.team_name_change_deadline, tr.auction_date
       FROM teams t
       JOIN tournaments tr ON tr.id = t.tournament_id
      WHERE t.id = :id',
    [':id' => $teamId]
);

$squad = $team === null ? [] : Database::all(
    'SELECT full_name, role, sold_price FROM players
      WHERE team_id = :t AND status = :sold ORDER BY sold_price DESC',
    [':t' => $teamId, ':sold' => 'sold']
);

$canRename = $team !== null && $tournaments->canRenameTeam((int) $team['tournament_id']);

$links = [
    ['href' => 'profile.php', 'label' => 'My details'],
    ['href' => 'team.php',    'label' => 'My team', 'current' => true],
    ['href' => 'apply.php',   'label' => 'Join a tournament'],
    ['href' => 'auction.php', 'label' => 'Live auction'],
];

page_head('My team', '', $links);
page_message($error);
?>

<?php if ($team === null): ?>

    <div class="mx-auto max-w-xl rounded-2xl border border-white/10 bg-white/[0.03] p-8 text-center">
        <h1 class="text-xl font-extrabold tracking-tight text-white">You do not own a team</h1>
        <p class="mx-auto mt-3 max-w-md text-sm leading-relaxed text-slate-400">
            An administrator assigns each team to one owner. Once yours is assigned it appears here,
            and you can set its name, short name and colour yourself — and change them again right up
            to the tournament's name change deadline.
        </p>
        <a href="profile.php"
           class="mt-6 inline-block rounded-xl border border-white/10 bg-white/5 px-5 py-2.5 text-[13px] font-bold text-slate-200 hover:bg-white/10">
            Back to my details
        </a>
    </div>

<?php else: ?>

    <div class="grid gap-8 lg:grid-cols-[1fr,320px]">
        <section>
            <h1 class="text-2xl font-extrabold tracking-tight text-white"><?= e((string) $team['name']) ?></h1>
            <p class="mt-2 text-sm text-slate-400">
                <?= e((string) $team['tournament_name']) ?> <?= e((string) $team['season_year']) ?>
            </p>

            <?php if ($canRename): ?>
                <div class="mt-5 rounded-xl border border-white/10 bg-slate-950/40 px-4 py-3 text-[13px] text-slate-400">
                    You can change the name until
                    <strong class="text-slate-200"><?= e(pretty_date($team['team_name_change_deadline'])) ?></strong>.
                    After that only an administrator can.
                </div>
            <?php else: ?>
                <div class="mt-5 rounded-xl border border-amber-400/25 bg-amber-400/[0.06] px-4 py-3 text-[13px] text-amber-100/90">
                    The name change deadline
                    (<?= e(pretty_date($team['team_name_change_deadline'])) ?>) has passed —
                    the name is now fixed. Ask an administrator if it really has to change.
                </div>
            <?php endif; ?>

            <form method="post" class="mt-6 space-y-5 rounded-2xl border border-white/10 bg-white/[0.03] p-6">
                <?= csrf_field() ?>

                <?php if ($canRename): ?>
                    <?php
                    field('name', 'Team name', (string) $team['name']);
                    field('short_name', 'Short name', (string) $team['short_name'], 'text', true,
                        '2 to 6 letters or digits.');
                    ?>
                <?php else: ?>
                    <?php
                    field('locked_name', 'Team name', (string) $team['name'], 'text', false, '', readonly: true);
                    field('locked_short', 'Short name', (string) $team['short_name'], 'text', false, '', readonly: true);
                    ?>
                <?php endif; ?>

                <?php
                field('primary_color', 'Team colour', (string) $team['primary_color'], 'color', false);
                field('home_venue', 'Home ground', (string) ($team['home_venue'] ?? ''), 'text', false);

                submit_button('Save');
                ?>
            </form>
        </section>

        <aside class="space-y-6">
            <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-6">
                <h2 class="text-[13px] font-bold uppercase tracking-wider text-slate-400">Purse</h2>
                <p class="mt-3 text-3xl font-black tracking-tight text-emerald-400">
                    <?= e(rupees($team['purse_remaining'])) ?>
                </p>
                <p class="mt-1 text-[12px] text-slate-500">
                    left of <?= e(rupees($team['purse_total'])) ?>
                    <?php if ((float) $team['purse_spent'] > 0): ?>
                        · <?= e(rupees($team['purse_spent'])) ?> spent
                    <?php endif; ?>
                </p>
                <p class="mt-3 text-[12px] text-slate-400">
                    <?= (int) $team['players_bought'] ?> player<?= (int) $team['players_bought'] === 1 ? '' : 's' ?> bought
                </p>
            </div>

            <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-6">
                <h2 class="text-[13px] font-bold uppercase tracking-wider text-slate-400">Squad</h2>

                <?php if ($squad === []): ?>
                    <p class="mt-3 text-[13px] text-slate-500">
                        Nobody bought yet. The auction is on <?= e(pretty_date($team['auction_date'])) ?>.
                    </p>
                <?php else: ?>
                    <ul class="mt-3 divide-y divide-white/5">
                        <?php foreach ($squad as $player): ?>
                            <li class="flex items-center justify-between gap-3 py-2.5">
                                <span class="text-[13px] font-semibold text-slate-200"><?= e((string) $player['full_name']) ?></span>
                                <span class="shrink-0 text-[12px] font-bold text-emerald-400">
                                    <?= e(rupees($player['sold_price'])) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </aside>
    </div>

<?php endif; ?>

<?php page_foot(); ?>
