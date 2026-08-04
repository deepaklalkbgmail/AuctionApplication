<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Tournament applications
 * =====================================================================
 *
 *  The queue that decides the auction list. Approving an application
 *  writes the player and their lot in one transaction, so the moment you
 *  approve somebody they are in the auction — there is no separate step
 *  to forget.
 *
 *  The base price and the overseas flag are set here, at the point of
 *  approval, because that is the only moment when someone is actually
 *  looking at the player's details.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/app/Views/layouts/shell.php';

use App\Core\Auth;
use App\Core\Security;
use App\Exceptions\AccountException;
use App\Services\TournamentService;

Auth::require(Auth::ROLE_ADMIN);

$tournaments = new TournamentService();
$adminId     = (int) Auth::id();
$error       = null;

$all = $tournaments->listTournaments();

if ($all === []) {
    page_head('Applications', '../', [
        ['href' => 'index.php', 'label' => 'Overview'],
        ['href' => 'tournaments.php', 'label' => 'Tournaments'],
    ]);
    ?>
    <p class="rounded-2xl border border-white/10 bg-white/[0.03] p-8 text-center text-sm text-slate-500">
        There is no tournament yet.
        <a href="tournaments.php" class="font-semibold text-emerald-400 hover:underline">Create one first.</a>
    </p>
    <?php
    page_foot();
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $approve = ($_POST['action'] ?? '') === 'approve';

        try {
            $result = $tournaments->decideApplication(
                (int) ($_POST['registration_id'] ?? 0),
                $approve,
                $adminId,
                (string) ($_POST['note'] ?? ''),
                [
                    'base_price'  => trim((string) ($_POST['base_price'] ?? '')) !== ''
                        ? $_POST['base_price'] : 200000,
                    'is_overseas' => !empty($_POST['is_overseas']),
                    'auction_set' => $_POST['auction_set'] ?? null,
                ]
            );

            flash('success', $approve
                ? $result['player'] . ' is approved and is now in the auction list.'
                : $result['player'] . '\'s application was rejected.');

            header('Location: applications.php?tournament=' . (int) ($_POST['tournament_id'] ?? 0));
            exit;
        } catch (AccountException $e) {
            $error = $e->getMessage();
        }
    }
}

$tournamentId = isset($_GET['tournament']) ? (int) $_GET['tournament'] : (int) $all[0]['id'];
$status       = (string) ($_GET['status'] ?? 'pending');
$tournament   = $tournaments->find($tournamentId);
$queue        = $tournaments->applications($tournamentId, $status);

$links = [
    ['href' => 'index.php',        'label' => 'Overview'],
    ['href' => 'users.php',        'label' => 'People'],
    ['href' => 'tournaments.php',  'label' => 'Tournaments'],
    ['href' => 'applications.php', 'label' => 'Applications', 'current' => true],
    ['href' => 'teams.php',        'label' => 'Teams'],
];

page_head('Applications', '../', $links);
page_message($error);
?>

<h1 class="text-2xl font-extrabold tracking-tight text-white">Applications</h1>
<p class="mt-2 text-sm text-slate-400">
    Approving somebody puts their name straight into the auction list for
    <strong class="text-slate-200"><?= e((string) $tournament['name']) ?></strong>.
</p>

<!-- ---------------------------------------------------------- pickers -->
<div class="no-bar mt-5 flex gap-2 overflow-x-auto">
    <?php foreach ($all as $row): ?>
        <a href="applications.php?tournament=<?= (int) $row['id'] ?>&status=<?= e($status) ?>"
           class="whitespace-nowrap rounded-lg px-3 py-1.5 text-[13px] font-semibold transition <?=
               (int) $row['id'] === $tournamentId
                   ? 'bg-emerald-500/15 text-emerald-300'
                   : 'border border-white/10 text-slate-400 hover:bg-white/5' ?>">
            <?= e((string) $row['name']) ?> <?= e((string) $row['season_year']) ?>
            <?php if ((int) $row['pending_count'] > 0): ?>
                <span class="ml-1 rounded bg-amber-400/20 px-1.5 text-[11px] font-bold text-amber-200"><?= (int) $row['pending_count'] ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="no-bar mt-3 flex gap-2 overflow-x-auto">
    <?php foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label): ?>
        <a href="applications.php?tournament=<?= $tournamentId ?>&status=<?= e($value) ?>"
           class="whitespace-nowrap rounded-lg px-3 py-1 text-[12px] font-semibold transition <?=
               $status === $value ? 'bg-white/10 text-slate-100' : 'text-slate-500 hover:text-slate-300' ?>">
            <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- ----------------------------------------------------------- queue -->
<?php if ($queue === []): ?>
    <p class="mt-6 rounded-2xl border border-white/10 bg-white/[0.03] p-8 text-center text-sm text-slate-500">
        <?= $status === 'pending' ? 'Nothing waiting for a decision.' : 'Nothing here.' ?>
    </p>
<?php else: ?>
    <div class="mt-6 space-y-4">
        <?php foreach ($queue as $app): ?>
            <article class="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                <div class="flex flex-wrap items-start gap-4">
                    <?php if (!empty($app['photo_path'])): ?>
                        <img src="<?= e('../' . $app['photo_path']) ?>" alt=""
                             class="h-16 w-16 shrink-0 rounded-xl border border-white/15 object-cover">
                    <?php else: ?>
                        <div class="grid h-16 w-16 shrink-0 place-items-center rounded-xl border border-white/10 bg-white/5 text-lg font-black text-slate-600">
                            <?= e(strtoupper(mb_substr((string) $app['name'], 0, 1))) ?>
                        </div>
                    <?php endif; ?>

                    <div class="min-w-[14rem] flex-1">
                        <p class="text-base font-extrabold text-white"><?= e((string) $app['name']) ?></p>
                        <p class="text-[12px] text-slate-500"><?= e((string) $app['email']) ?> · <?= e((string) ($app['phone'] ?? '—')) ?></p>
                        <p class="mt-1 text-[12px] text-slate-500"><?= e((string) ($app['address'] ?? '')) ?></p>
                        <p class="mt-2 flex flex-wrap gap-2">
                            <span class="rounded bg-sky-500/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-300">
                                <?= e(str_replace('_', ' ', (string) ($app['player_type'] ?? 'unknown'))) ?>
                            </span>
                            <span class="rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide <?=
                                match ($app['status']) {
                                    'approved' => 'bg-emerald-500/15 text-emerald-300',
                                    'pending'  => 'bg-amber-400/15 text-amber-300',
                                    default    => 'bg-slate-500/15 text-slate-400',
                                } ?>"><?= e((string) $app['status']) ?></span>
                            <span class="text-[11px] text-slate-600">applied <?= e(pretty_date(substr((string) $app['applied_at'], 0, 10))) ?></span>
                        </p>
                    </div>
                </div>

                <?php if ($app['status'] === 'pending'): ?>
                    <form method="post" class="mt-4 grid gap-4 border-t border-white/5 pt-4 sm:grid-cols-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="registration_id" value="<?= (int) $app['id'] ?>">
                        <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">

                        <?php
                        field('base_price', 'Base price (₹)', '200000', 'number', false);
                        field('auction_set', 'Auction set', '', 'text', false, 'Marquee, Set A…');
                        field('note', 'Note', '', 'text', false, 'Kept on the record.');
                        ?>

                        <div class="flex items-end gap-2">
                            <label class="mb-2.5 flex items-center gap-2 text-[12px] font-semibold text-slate-400">
                                <input type="checkbox" name="is_overseas" value="1"
                                       class="h-4 w-4 rounded border-white/20 bg-slate-950 text-emerald-500">
                                Overseas
                            </label>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 sm:col-span-4">
                            <button type="submit" name="action" value="approve"
                                    class="rounded-xl bg-gradient-to-r from-emerald-400 to-emerald-500 px-4 py-2.5 text-[13px] font-black uppercase tracking-wide text-slate-950 hover:brightness-110">
                                Approve — add to the auction
                            </button>
                            <button type="submit" name="action" value="reject"
                                    class="rounded-xl border border-rose-400/30 bg-rose-500/10 px-4 py-2.5 text-[13px] font-black uppercase tracking-wide text-rose-200 hover:bg-rose-500/20">
                                Reject
                            </button>
                        </div>
                    </form>
                <?php elseif (!empty($app['decided_by_name'])): ?>
                    <p class="mt-3 border-t border-white/5 pt-3 text-[12px] text-slate-500">
                        <?= e((string) $app['status']) ?> by <?= e((string) $app['decided_by_name']) ?>
                        <?php if (!empty($app['note'])): ?> — <?= e((string) $app['note']) ?><?php endif; ?>
                    </p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php page_foot(); ?>
