<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Tournaments
 * =====================================================================
 *
 *  Create a season with its four dates, read off the secret code that
 *  players join with, and open or close entries.
 *
 *  The code is shown in full here and nowhere else in the application.
 *  This screen is behind the administrator gate, which is the whole
 *  reason a code is worth anything.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/app/Views/layouts/shell.php';

use App\Core\Auth;
use App\Core\Security;
use App\Exceptions\AccountException;
use App\Services\TournamentService;

Auth::require(Auth::ROLE_ADMIN);

$tournaments = new TournamentService();
$error       = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $id     = (int) ($_POST['tournament_id'] ?? 0);

        try {
            switch ($action) {
                case 'create':
                    $created = $tournaments->create($_POST);
                    flash('success', 'Created "' . $created['name'] . '". Its code is '
                        . $created['secret_code'] . ' — give that to the players.');
                    header('Location: tournaments.php');
                    exit;

                case 'update':
                    $tournaments->update($id, [
                        'name'                      => $_POST['name'] ?? '',
                        'start_date'                => $_POST['start_date'] ?? null,
                        'auction_date'              => $_POST['auction_date'] ?? null,
                        'end_date'                  => $_POST['end_date'] ?? null,
                        'team_name_change_deadline' => $_POST['team_name_change_deadline'] ?? null,
                        'status'                    => $_POST['status'] ?? 'draft',
                    ]);
                    flash('success', 'Saved.');
                    header('Location: tournaments.php?edit=' . $id);
                    exit;

                case 'toggle_registration':
                    $tournaments->update($id, ['registration_open' => (int) ($_POST['open'] ?? 0) === 1]);
                    flash('success', (int) ($_POST['open'] ?? 0) === 1
                        ? 'Entries are open.' : 'Entries are closed.');
                    header('Location: tournaments.php');
                    exit;

                case 'new_code':
                    $code = $tournaments->regenerateSecretCode($id);
                    flash('success', 'New code: ' . $code . '. The old one no longer works.');
                    header('Location: tournaments.php');
                    exit;
            }
        } catch (AccountException $e) {
            $error = $e->getMessage();
        }
    }
}

$all     = $tournaments->listTournaments();
$editing = isset($_GET['edit']) ? $tournaments->find((int) $_GET['edit']) : null;

$links = [
    ['href' => 'index.php',        'label' => 'Overview'],
    ['href' => 'users.php',        'label' => 'People'],
    ['href' => 'tournaments.php',  'label' => 'Tournaments', 'current' => true],
    ['href' => 'applications.php', 'label' => 'Applications'],
    ['href' => 'teams.php',        'label' => 'Teams'],
];

page_head('Tournaments', '../', $links);
page_message($error);
?>

<h1 class="text-2xl font-extrabold tracking-tight text-white">Tournaments</h1>

<?php if ($all === []): ?>
    <p class="mt-5 rounded-2xl border border-white/10 bg-white/[0.03] p-8 text-center text-sm text-slate-500">
        No tournament yet. Create the first one below — players can apply the moment it exists.
    </p>
<?php else: ?>
    <div class="mt-5 space-y-4">
        <?php foreach ($all as $row): ?>
            <article class="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-extrabold tracking-tight text-white">
                            <?= e((string) $row['name']) ?>
                            <span class="ml-1 text-sm font-bold text-slate-500"><?= e((string) $row['season_year']) ?></span>
                        </h2>
                        <p class="mt-1 text-[12px] text-slate-500">
                            <?= (int) $row['team_count'] ?> teams
                            · <?= (int) $row['player_count'] ?> players
                            <?php if ((int) $row['pending_count'] > 0): ?>
                                · <a href="applications.php?tournament=<?= (int) $row['id'] ?>"
                                     class="font-bold text-amber-300 hover:underline"><?= (int) $row['pending_count'] ?> waiting</a>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Secret code</p>
                        <p class="font-mono text-2xl font-black tracking-[0.2em] text-emerald-300">
                            <?= e((string) ($row['secret_code'] ?? '—')) ?>
                        </p>
                    </div>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 border-t border-white/5 pt-4 sm:grid-cols-4">
                    <?php foreach ([
                        'Auction'      => $row['auction_date'],
                        'Starts'       => $row['start_date'],
                        'Ends'         => $row['end_date'],
                        'Names locked' => $row['team_name_change_deadline'],
                    ] as $label => $date): ?>
                        <div>
                            <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-500"><?= e($label) ?></dt>
                            <dd class="mt-0.5 text-[13px] font-semibold text-slate-200"><?= e(pretty_date($date)) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>

                <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-white/5 pt-4">
                    <span class="rounded bg-white/5 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-400">
                        <?= e((string) $row['status']) ?>
                    </span>
                    <span class="rounded px-2 py-1 text-[10px] font-bold uppercase tracking-wide <?=
                        (int) $row['registration_open'] === 1
                            ? 'bg-emerald-500/15 text-emerald-300' : 'bg-slate-500/15 text-slate-400' ?>">
                        entries <?= (int) $row['registration_open'] === 1 ? 'open' : 'closed' ?>
                    </span>

                    <a href="tournaments.php?edit=<?= (int) $row['id'] ?>"
                       class="rounded-lg border border-white/10 px-3 py-1.5 text-[12px] font-bold text-slate-300 hover:bg-white/5">Edit</a>

                    <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_registration">
                        <input type="hidden" name="tournament_id" value="<?= (int) $row['id'] ?>">
                        <input type="hidden" name="open" value="<?= (int) $row['registration_open'] === 1 ? 0 : 1 ?>">
                        <button type="submit" class="rounded-lg border border-white/10 px-3 py-1.5 text-[12px] font-bold text-slate-300 hover:bg-white/5">
                            <?= (int) $row['registration_open'] === 1 ? 'Close entries' : 'Open entries' ?>
                        </button>
                    </form>

                    <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="new_code">
                        <input type="hidden" name="tournament_id" value="<?= (int) $row['id'] ?>">
                        <button type="submit" class="rounded-lg border border-white/10 px-3 py-1.5 text-[12px] font-bold text-slate-300 hover:bg-white/5">
                            Issue a new code
                        </button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ------------------------------------------------------------ editor -->
<?php if ($editing !== null): ?>
    <section class="mt-10">
        <h2 class="text-[13px] font-bold uppercase tracking-wider text-slate-400">
            Editing <?= e((string) $editing['name']) ?>
        </h2>

        <form method="post" class="mt-4 grid gap-5 rounded-2xl border border-white/10 bg-white/[0.03] p-6 sm:grid-cols-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="tournament_id" value="<?= (int) $editing['id'] ?>">

            <?php
            field('name', 'Tournament name', (string) $editing['name']);
            select_field('status', 'Status', [
                'draft'     => 'Draft',
                'auction'   => 'Auction',
                'ongoing'   => 'Ongoing',
                'completed' => 'Completed',
            ], (string) $editing['status']);

            field('auction_date', 'Auction date', (string) ($editing['auction_date'] ?? ''), 'date', false,
                'Entries close at the end of this day.');
            field('start_date', 'Start date', (string) ($editing['start_date'] ?? ''), 'date', false,
                'On or after the auction date.');
            field('end_date', 'End date', (string) ($editing['end_date'] ?? ''), 'date', false);
            field('team_name_change_deadline', 'Team name change deadline',
                (string) ($editing['team_name_change_deadline'] ?? ''), 'date', false,
                'Owners may rename their team up to the end of this day.');
            ?>

            <div class="flex items-center gap-3 sm:col-span-2">
                <?php submit_button('Save'); ?>
                <a href="tournaments.php" class="text-[13px] font-semibold text-slate-400 hover:text-slate-200">Cancel</a>
            </div>
        </form>
    </section>
<?php endif; ?>

<!-- --------------------------------------------------------------- new -->
<section class="mt-10">
    <h2 class="text-[13px] font-bold uppercase tracking-wider text-slate-400">Create a tournament</h2>
    <p class="mt-2 max-w-2xl text-[13px] leading-relaxed text-slate-500">
        A secret code is generated for you. Players apply with it, and appear in the auction list only
        once you approve them.
    </p>

    <form method="post" class="mt-4 grid gap-5 rounded-2xl border border-white/10 bg-white/[0.03] p-6 sm:grid-cols-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <?php
        field('name', 'Tournament name', '', 'text', true, 'For example: Alappuzha Premier League.');
        field('season_year', 'Season', (string) date('Y'), 'number');

        field('auction_date', 'Auction date', '', 'date', false, 'Entries close at the end of this day.');
        field('start_date', 'Start date', '', 'date', false, 'On or after the auction date.');
        field('end_date', 'End date', '', 'date', false);
        field('team_name_change_deadline', 'Team name change deadline', '', 'date', false,
            'Owners may rename their team up to the end of this day.');

        field('purse_per_team', 'Purse per team (₹)', '10000000', 'number', false);
        field('bid_increment', 'Bid increment (₹)', '500000', 'number', false);
        field('min_squad_size', 'Minimum squad', '11', 'number', false);
        field('max_squad_size', 'Maximum squad', '15', 'number', false);
        field('max_overseas', 'Overseas limit', '4', 'number', false);
        field('overs_per_innings', 'Overs per innings', '20', 'number', false);
        ?>

        <div class="sm:col-span-2">
            <?php submit_button('Create tournament'); ?>
        </div>
    </form>
</section>

<?php page_foot(); ?>
