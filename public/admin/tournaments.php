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
                    // Everything the create form offers, so a tournament can
                    // be corrected rather than deleted and rebuilt.
                    //
                    // Only keys that actually arrived are passed on, because
                    // update() touches exactly the keys it is given. Passing
                    // ?? null for a field the form did not send would hand it
                    // a null to validate and refuse the whole save over a box
                    // nobody touched.
                    //
                    // A cleared NUMBER box means "leave this alone": these
                    // columns are NOT NULL with sensible defaults, so there is
                    // no such thing as an empty purse. A cleared DATE box does
                    // mean cleared — those columns are nullable and an
                    // administrator may genuinely not know a date yet.
                    $in = [];

                    foreach (['name', 'status', 'start_date', 'auction_date',
                              'end_date', 'team_name_change_deadline'] as $key) {
                        if (array_key_exists($key, $_POST)) {
                            $in[$key] = $_POST[$key];
                        }
                    }

                    foreach (['season_year', 'purse_per_team', 'bid_increment', 'min_squad_size',
                              'max_squad_size', 'max_overseas', 'overs_per_innings'] as $key) {
                        if (trim((string) ($_POST[$key] ?? '')) !== '') {
                            $in[$key] = $_POST[$key];
                        }
                    }

                    $tournaments->update($id, $in);
                    flash('success', 'Saved.');
                    header('Location: tournaments.php?edit=' . $id);
                    exit;

                case 'cancel':
                    $off = $tournaments->setCancelled($id, true);
                    flash('success', $off['name'] . ' is cancelled. Nothing has been deleted — '
                        . 'press Reinstate to put it back.');
                    header('Location: tournaments.php');
                    exit;

                case 'reinstate':
                    $on = $tournaments->setCancelled($id, false);
                    flash('success', $on['name'] . ' is back, as a draft. Entries are still closed — '
                        . 'open them when you are ready.');
                    header('Location: tournaments.php');
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

$all = $tournaments->listTournaments();

// A failed save has no ?edit in the address — it is the POST coming back —
// so without this the editor would vanish and the administrator would be
// left with a red message and no form to correct.
$editingId = isset($_GET['edit'])
    ? (int) $_GET['edit']
    : ($error !== null && ($_POST['action'] ?? '') === 'update' ? (int) ($_POST['tournament_id'] ?? 0) : 0);

$editing = $editingId > 0 ? $tournaments->find($editingId) : null;

// Cancelling is confirmed on its own screen rather than behind a one-click
// button. No JavaScript, so no confirm() dialogue is available — and a
// mis-click here closes entries on a live season.
$confirming = isset($_GET['confirm_cancel'])
    ? $tournaments->find((int) $_GET['confirm_cancel'])
    : null;

$links = [
    ['href' => 'index.php',        'label' => 'Overview'],
    ['href' => 'users.php',        'label' => 'People'],
    ['href' => 'tournaments.php',  'label' => 'Tournaments', 'current' => true],
    ['href' => 'applications.php', 'label' => 'Applications'],
    ['href' => 'players.php',      'label' => 'Players'],
    ['href' => 'teams.php',        'label' => 'Teams'],
    ['href' => 'auction.php',      'label' => 'Auction'],
    ['href' => 'activity.php',     'label' => 'Activity'],
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
        <?php foreach ($all as $row):
            $cancelled = (string) $row['status'] === 'cancelled';
            ?>
            <article class="rounded-2xl border p-5 <?= $cancelled
                ? 'border-rose-400/25 bg-rose-500/[0.05]'
                : 'border-white/10 bg-white/[0.03]' ?>">
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
                    <span class="rounded px-2 py-1 text-[10px] font-bold uppercase tracking-wide <?=
                        $cancelled ? 'bg-rose-500/20 text-rose-300' : 'bg-white/5 text-slate-400' ?>">
                        <?= e((string) $row['status']) ?>
                    </span>
                    <span class="rounded px-2 py-1 text-[10px] font-bold uppercase tracking-wide <?=
                        (int) $row['registration_open'] === 1
                            ? 'bg-emerald-500/15 text-emerald-300' : 'bg-slate-500/15 text-slate-400' ?>">
                        entries <?= (int) $row['registration_open'] === 1 ? 'open' : 'closed' ?>
                    </span>

                    <a href="tournaments.php?edit=<?= (int) $row['id'] ?>"
                       class="rounded-lg border border-white/10 px-3 py-1.5 text-[12px] font-bold text-slate-300 hover:bg-white/5">Edit</a>

                    <?php if (!$cancelled): ?>
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

                        <a href="tournaments.php?confirm_cancel=<?= (int) $row['id'] ?>"
                           class="ml-auto rounded-lg border border-rose-400/25 px-3 py-1.5 text-[12px] font-bold text-rose-300 hover:bg-rose-500/10">
                            Cancel tournament
                        </a>
                    <?php else: ?>
                        <form method="post" class="ml-auto inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reinstate">
                            <input type="hidden" name="tournament_id" value="<?= (int) $row['id'] ?>">
                            <button type="submit"
                                    class="rounded-lg border border-emerald-400/30 bg-emerald-500/10 px-3 py-1.5 text-[12px] font-bold text-emerald-300 hover:bg-emerald-500/20">
                                Reinstate
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if ($cancelled): ?>
                    <p class="mt-3 rounded-xl border border-rose-400/20 bg-rose-500/[0.06] px-4 py-2.5 text-[12px] text-rose-200/90">
                        Cancelled. Nothing has been deleted — every player, team and result is still here.
                        Players cannot join with its code, and it does not appear on the public board.
                    </p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- --------------------------------------------------- confirm a cancel -->
<?php if ($confirming !== null && (string) $confirming['status'] !== 'cancelled'):
    $counts = [
        'players'      => (int) Database::scalar('SELECT COUNT(*) FROM players WHERE tournament_id = :t', [':t' => (int) $confirming['id']]),
        'teams'        => (int) Database::scalar('SELECT COUNT(*) FROM teams WHERE tournament_id = :t', [':t' => (int) $confirming['id']]),
        'applications' => (int) Database::scalar('SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = :t', [':t' => (int) $confirming['id']]),
        'sold'         => (int) Database::scalar("SELECT COUNT(*) FROM auction_lots WHERE tournament_id = :t AND status = 'sold'", [':t' => (int) $confirming['id']]),
    ];
    ?>
    <section class="mt-8 rounded-2xl border border-rose-400/30 bg-rose-500/[0.07] p-6">
        <h2 class="text-lg font-extrabold tracking-tight text-white">
            Cancel <?= e((string) $confirming['name']) ?> <?= e((string) $confirming['season_year']) ?>?
        </h2>

        <p class="mt-2 max-w-2xl text-[13px] leading-relaxed text-rose-100/90">
            This marks the tournament cancelled and closes entries. <strong>Nothing is deleted.</strong>
            It holds <?= $counts['players'] ?> player<?= $counts['players'] === 1 ? '' : 's' ?>,
            <?= $counts['teams'] ?> team<?= $counts['teams'] === 1 ? '' : 's' ?>,
            <?= $counts['applications'] ?> application<?= $counts['applications'] === 1 ? '' : 's' ?>
            and <?= $counts['sold'] ?> recorded sale<?= $counts['sold'] === 1 ? '' : 's' ?>,
            and every one of them stays exactly where it is.
        </p>

        <p class="mt-3 max-w-2xl text-[13px] leading-relaxed text-rose-100/70">
            Afterwards: players are turned away when they try to join with its code, and the public
            auction board moves to the newest tournament that has not been cancelled. Press
            <strong>Reinstate</strong> to undo this.
        </p>

        <div class="mt-5 flex flex-wrap items-center gap-3">
            <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="tournament_id" value="<?= (int) $confirming['id'] ?>">
                <button type="submit"
                        class="rounded-xl bg-rose-500 px-5 py-2.5 text-[13px] font-black uppercase tracking-wide text-white hover:brightness-110">
                    Yes, cancel it
                </button>
            </form>
            <a href="tournaments.php" class="text-[13px] font-semibold text-slate-300 hover:text-white">
                No, leave it alone
            </a>
        </div>
    </section>
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
            field('season_year', 'Season', (string) $editing['season_year'], 'number');

            select_field('status', 'Status', [
                'draft'     => 'Draft',
                'auction'   => 'Auction',
                'ongoing'   => 'Ongoing',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
            ], (string) $editing['status']);
            ?>

            <div class="sm:col-span-2">
                <h3 class="border-t border-white/10 pt-5 text-[11px] font-black uppercase tracking-[0.14em] text-slate-500">
                    Dates
                </h3>
            </div>

            <?php
            field('auction_date', 'Auction date', (string) ($editing['auction_date'] ?? ''), 'date', false,
                'Entries close at the end of this day.');
            field('start_date', 'Start date', (string) ($editing['start_date'] ?? ''), 'date', false,
                'On or after the auction date.');
            field('end_date', 'End date', (string) ($editing['end_date'] ?? ''), 'date', false);
            field('team_name_change_deadline', 'Team name change deadline',
                (string) ($editing['team_name_change_deadline'] ?? ''), 'date', false,
                'Owners may rename their team up to the end of this day.');
            ?>

            <div class="sm:col-span-2">
                <h3 class="border-t border-white/10 pt-5 text-[11px] font-black uppercase tracking-[0.14em] text-slate-500">
                    Money and squads
                </h3>
                <?php /* These are enforced during the auction, so changing one
                         mid-season changes what the sheet will accept. */ ?>
                <p class="mt-2 max-w-2xl text-[12px] leading-relaxed text-slate-500">
                    The auction enforces these as it runs. Raising a purse or a squad cap after teams have
                    started buying is allowed; lowering one below what a team has already spent or bought
                    does not undo anything, it only stops them going further.
                </p>
            </div>

            <?php
            field('purse_per_team', 'Purse per team (₹)', (string) (int) $editing['purse_per_team'], 'number', false);
            field('bid_increment', 'Bid increment (₹)', (string) (int) $editing['bid_increment'], 'number', false);
            field('min_squad_size', 'Minimum squad', (string) (int) $editing['min_squad_size'], 'number', false,
                'Cannot be larger than the maximum.');
            field('max_squad_size', 'Maximum squad', (string) (int) $editing['max_squad_size'], 'number', false);
            field('max_overseas', 'Overseas limit', (string) (int) $editing['max_overseas'], 'number', false);
            field('overs_per_innings', 'Overs per innings', (string) (int) $editing['overs_per_innings'], 'number', false);
            ?>

            <div class="flex items-center gap-3 sm:col-span-2">
                <?php submit_button('Save'); ?>
                <a href="tournaments.php" class="text-[13px] font-semibold text-slate-400 hover:text-slate-200">Discard changes</a>
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
