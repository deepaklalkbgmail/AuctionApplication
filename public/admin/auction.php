<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Running the auction — the auctioneer's sheet
 * =====================================================================
 *
 *  The auction is called aloud in the room. This screen is the record of
 *  it: for each player, the administrator types the price that was agreed
 *  and the team that bought them.
 *
 *  Deliberately plain forms and no JavaScript. Somebody is typing figures
 *  into this while a room waits, often on a laptop with a poor connection.
 *  A form that posts and comes back with a plain answer is the right shape
 *  for that; a live-updating panel is not.
 *
 *  The purse board sits at the top and is always visible — you cannot
 *  decide whether a bid is affordable without it, and it is the first
 *  thing anyone asks between lots.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/app/Views/layouts/shell.php';

use App\Core\Auth;
use App\Core\Security;
use App\Exceptions\AuctionException;
use App\Services\AuctionService;
use App\Services\TournamentService;

Auth::require(Auth::ROLE_ADMIN);

$auction     = new AuctionService();
$tournaments = new TournamentService();
$adminId     = (int) Auth::id();
$error       = null;
$warning     = null;

$all = $tournaments->listTournaments();

$links = [
    ['href' => 'index.php',        'label' => 'Overview'],
    ['href' => 'users.php',        'label' => 'People'],
    ['href' => 'tournaments.php',  'label' => 'Tournaments'],
    ['href' => 'applications.php', 'label' => 'Applications'],
    ['href' => 'teams.php',        'label' => 'Teams'],
    ['href' => 'auction.php',      'label' => 'Auction', 'current' => true],
];

if ($all === []) {
    page_head('Auction', '../', $links);
    ?>
    <p class="rounded-2xl border border-white/10 bg-white/[0.03] p-8 text-center text-sm text-slate-500">
        There is no tournament yet.
        <a href="tournaments.php" class="font-semibold text-emerald-400 hover:underline">Create one first.</a>
    </p>
    <?php
    page_foot();
    exit;
}

// ---------------------------------------------------------------------
//  Actions
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tournamentId = (int) ($_POST['tournament_id'] ?? 0);

    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            switch ((string) ($_POST['action'] ?? '')) {
                case 'sell':
                    $result = $auction->recordSale(
                        (int) ($_POST['lot_id'] ?? 0),
                        (int) ($_POST['team_id'] ?? 0),
                        (string) ($_POST['amount'] ?? ''),
                        $adminId
                    );

                    flash('success', sprintf(
                        'SOLD — %s to %s for ₹%s.',
                        $result['player'],
                        $result['team'],
                        number_format((float) $result['price'])
                    ));

                    if ($result['warning'] !== null) {
                        $_SESSION['auction_warning'] = $result['warning'];
                    }
                    break;

                case 'unsold':
                    $result = $auction->markUnsold((int) ($_POST['lot_id'] ?? 0), $adminId);
                    flash('success', $result['player'] . ' passed over — unsold.');
                    break;

                case 'undo':
                    $result = $auction->undoSale((int) ($_POST['lot_id'] ?? 0), $adminId);
                    flash('success', sprintf(
                        'Undone — %s is back in the pool and ₹%s returned to %s.',
                        $result['player'],
                        number_format((float) $result['refunded']),
                        $result['team']
                    ));
                    break;

                case 'relist':
                    Database::exec(
                        "UPDATE auction_lots SET status = 'queued'
                          WHERE id = :lot AND status = 'unsold'",
                        [':lot' => (int) ($_POST['lot_id'] ?? 0)]
                    );
                    Database::exec(
                        "UPDATE players p
                           JOIN auction_lots l ON l.player_id = p.id
                            SET p.status = 'available'
                          WHERE l.id = :lot AND p.status = 'unsold'",
                        [':lot' => (int) ($_POST['lot_id'] ?? 0)]
                    );
                    flash('success', 'Back in the queue.');
                    break;
            }

            header('Location: auction.php?tournament=' . $tournamentId
                 . (isset($_POST['q']) && $_POST['q'] !== '' ? '&q=' . urlencode((string) $_POST['q']) : ''));
            exit;
        } catch (AuctionException $e) {
            $error = $e->getMessage();
        }
    }
}

$tournamentId = isset($_GET['tournament']) ? (int) $_GET['tournament'] : (int) $all[0]['id'];
$tournament   = $tournaments->find($tournamentId);
$search       = trim((string) ($_GET['q'] ?? ''));

$warning = $_SESSION['auction_warning'] ?? null;
unset($_SESSION['auction_warning']);

$teams = Database::all(
    'SELECT id, name, short_name, primary_color, purse_total, purse_spent,
            purse_remaining, players_bought, overseas_bought
       FROM teams WHERE tournament_id = :t ORDER BY name',
    [':t' => $tournamentId]
);

/** @return array<int,array<string,mixed>> */
function lotsWithStatus(int $tournamentId, array $statuses, string $search): array
{
    $in = implode(',', array_map(static fn ($i) => ':s' . $i, array_keys($statuses)));
    $params = [':t' => $tournamentId];

    foreach ($statuses as $i => $status) {
        $params[':s' . $i] = $status;
    }

    $like = '';

    if ($search !== '') {
        $like = ' AND p.full_name LIKE :q';
        $params[':q'] = '%' . $search . '%';
    }

    return Database::all(
        "SELECT l.id AS lot_id, l.status, l.base_price, l.sold_price, l.lot_order,
                p.id AS player_id, p.full_name, p.role, p.is_overseas, p.auction_set,
                p.photo_url, p.country,
                t.name AS team_name, t.short_name AS team_short
           FROM auction_lots l
           JOIN players p ON p.id = l.player_id
      LEFT JOIN teams   t ON t.id = l.sold_to_team_id
          WHERE l.tournament_id = :t AND l.status IN ({$in}){$like}
       ORDER BY l.lot_order, p.full_name",
        $params
    );
}

$toCall = lotsWithStatus($tournamentId, ['queued', 'live', 'paused'], $search);
$sold   = lotsWithStatus($tournamentId, ['sold'], $search);
$unsold = lotsWithStatus($tournamentId, ['unsold'], $search);

$spent  = array_sum(array_map(static fn ($t) => (float) $t['purse_spent'], $teams));

page_head('Auction', '../', $links);
page_message($error);
?>

<?php if ($warning !== null): ?>
    <p class="mb-6 rounded-xl border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-[13px] font-semibold text-amber-200">
        <?= e($warning) ?>
    </p>
<?php endif; ?>

<div class="flex flex-wrap items-baseline justify-between gap-3">
    <h1 class="text-2xl font-extrabold tracking-tight text-white">Auction</h1>
    <p class="text-[13px] text-slate-400">
        Call the lot in the room, then record what it went for.
    </p>
</div>

<!-- --------------------------------------------------------- tournament -->
<?php if (count($all) > 1): ?>
    <div class="no-bar mt-4 flex gap-2 overflow-x-auto">
        <?php foreach ($all as $row): ?>
            <a href="auction.php?tournament=<?= (int) $row['id'] ?>"
               class="whitespace-nowrap rounded-lg px-3 py-1.5 text-[13px] font-semibold transition <?=
                   (int) $row['id'] === $tournamentId
                       ? 'bg-emerald-500/15 text-emerald-300'
                       : 'border border-white/10 text-slate-400 hover:bg-white/5' ?>">
                <?= e((string) $row['name']) ?> <?= e((string) $row['season_year']) ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ========================================================= PURSE BOARD -->
<section class="mt-5">
    <div class="flex items-baseline justify-between">
        <h2 class="text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">Purse board</h2>
        <p class="text-[12px] text-slate-500">
            <?= count($sold) ?> sold · <?= rupees($spent) ?> spent · <?= count($toCall) ?> still to call
        </p>
    </div>

    <?php if ($teams === []): ?>
        <p class="mt-3 rounded-2xl border border-amber-400/25 bg-amber-400/[0.06] p-5 text-[13px] text-amber-100/90">
            This tournament has no teams yet, so there is nobody to sell to.
            <a href="teams.php?tournament=<?= $tournamentId ?>" class="font-bold underline">Create the teams first.</a>
        </p>
    <?php else: ?>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($teams as $team):
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
                        <p class="min-w-0 flex-1 truncate text-[13px] font-bold text-white"><?= e((string) $team['name']) ?></p>
                        <p class="shrink-0 text-right text-base font-black text-emerald-400">
                            <?= rupees($team['purse_remaining']) ?>
                        </p>
                    </div>

                    <div class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-white/10">
                        <div class="h-full rounded-full bg-emerald-400/70" style="width: <?= number_format($pct, 1) ?>%"></div>
                    </div>

                    <p class="mt-2 flex justify-between text-[11px] text-slate-500">
                        <span><?= (int) $team['players_bought'] ?> bought<?= (int) $team['overseas_bought'] > 0
                            ? ' · ' . (int) $team['overseas_bought'] . ' overseas' : '' ?></span>
                        <span>spent <?= rupees($team['purse_spent']) ?> of <?= rupees($team['purse_total']) ?></span>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- -------------------------------------------------------------- search -->
<form method="get" class="mt-7 flex flex-wrap items-end gap-3">
    <input type="hidden" name="tournament" value="<?= $tournamentId ?>">
    <div class="min-w-[14rem] flex-1">
        <label for="f_q" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">
            Find a player
        </label>
        <input id="f_q" name="q" type="search" value="<?= e($search) ?>"
               placeholder="Type part of a name"
               class="w-full rounded-xl border border-white/10 bg-slate-950/60 px-3.5 py-2.5 text-sm text-white outline-none placeholder:text-slate-600 focus:border-emerald-400/50 focus:ring-2 focus:ring-emerald-400/20">
    </div>
    <button type="submit" class="mb-0.5 rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-[13px] font-bold text-slate-200 hover:bg-white/10">
        Search
    </button>
    <?php if ($search !== ''): ?>
        <a href="auction.php?tournament=<?= $tournamentId ?>" class="mb-3 text-[13px] font-semibold text-slate-400 hover:text-slate-200">Clear</a>
    <?php endif; ?>
</form>

<!-- ====================================================== STILL TO CALL -->
<section class="mt-6">
    <h2 class="text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">
        Still to call <span class="ml-1 text-slate-600">(<?= count($toCall) ?>)</span>
    </h2>

    <?php if ($toCall === []): ?>
        <p class="mt-3 rounded-2xl border border-white/10 bg-white/[0.03] p-6 text-center text-[13px] text-slate-500">
            <?= $search !== ''
                ? 'Nobody left to call matches that name.'
                : 'Every player has been called. Nothing left in the pool.' ?>
        </p>
    <?php else: ?>
        <div class="mt-3 space-y-3">
            <?php foreach ($toCall as $lot): ?>
                <article class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-white/5 font-mono text-[12px] font-bold text-slate-400">
                            <?= (int) $lot['lot_order'] ?>
                        </span>
                        <div class="min-w-[10rem] flex-1">
                            <p class="text-[15px] font-extrabold text-white"><?= e((string) $lot['full_name']) ?></p>
                            <p class="text-[11px] text-slate-500">
                                <?= e(str_replace('_', ' ', (string) $lot['role'])) ?>
                                · base <?= rupees($lot['base_price']) ?>
                                <?php if (!empty($lot['auction_set'])): ?> · <?= e((string) $lot['auction_set']) ?><?php endif; ?>
                                <?php if ((int) $lot['is_overseas'] === 1): ?>
                                    · <span class="font-bold text-sky-300">overseas</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-end gap-3 border-t border-white/5 pt-3">
                        <form method="post" class="flex flex-wrap items-end gap-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="sell">
                            <input type="hidden" name="lot_id" value="<?= (int) $lot['lot_id'] ?>">
                            <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                            <input type="hidden" name="q" value="<?= e($search) ?>">

                            <div>
                                <label for="team_<?= (int) $lot['lot_id'] ?>"
                                       class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Sold to</label>
                                <select id="team_<?= (int) $lot['lot_id'] ?>" name="team_id" required
                                        class="rounded-xl border border-white/10 bg-slate-950/60 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-400/50">
                                    <option value="">Choose a team…</option>
                                    <?php foreach ($teams as $team): ?>
                                        <option value="<?= (int) $team['id'] ?>">
                                            <?= e((string) $team['name']) ?>
                                            (<?= rupees($team['purse_remaining']) ?> left)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="amt_<?= (int) $lot['lot_id'] ?>"
                                       class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Price (₹)</label>
                                <input id="amt_<?= (int) $lot['lot_id'] ?>" name="amount" type="number" required
                                       min="<?= (int) $lot['base_price'] ?>" step="1"
                                       placeholder="<?= (int) $lot['base_price'] ?>"
                                       class="w-36 rounded-xl border border-white/10 bg-slate-950/60 px-3 py-2.5 text-sm font-bold text-white outline-none placeholder:font-normal placeholder:text-slate-600 focus:border-emerald-400/50">
                            </div>

                            <button type="submit"
                                    class="rounded-xl bg-gradient-to-r from-emerald-400 to-emerald-500 px-5 py-2.5 text-[13px] font-black uppercase tracking-wide text-slate-950 hover:brightness-110">
                                Sold
                            </button>
                        </form>

                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="unsold">
                            <input type="hidden" name="lot_id" value="<?= (int) $lot['lot_id'] ?>">
                            <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                            <input type="hidden" name="q" value="<?= e($search) ?>">
                            <button type="submit"
                                    class="rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-[13px] font-bold text-slate-300 hover:bg-white/10">
                                Unsold
                            </button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- ============================================================== SOLD -->
<?php if ($sold !== []): ?>
    <section class="mt-9">
        <h2 class="text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">
            Sold <span class="ml-1 text-slate-600">(<?= count($sold) ?>)</span>
        </h2>

        <div class="mt-3 overflow-x-auto rounded-2xl border border-white/10 bg-white/[0.03]">
            <table class="w-full min-w-[36rem] text-left">
                <thead>
                    <tr class="border-b border-white/10 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                        <th class="px-4 py-2.5">Player</th>
                        <th class="px-4 py-2.5">Team</th>
                        <th class="px-4 py-2.5 text-right">Price</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sold as $lot): ?>
                        <tr class="border-b border-white/5 last:border-0">
                            <td class="px-4 py-2.5">
                                <span class="text-[13px] font-bold text-white"><?= e((string) $lot['full_name']) ?></span>
                                <span class="ml-1.5 text-[11px] text-slate-500"><?= e(str_replace('_', ' ', (string) $lot['role'])) ?></span>
                            </td>
                            <td class="px-4 py-2.5 text-[13px] text-slate-300"><?= e((string) $lot['team_name']) ?></td>
                            <td class="px-4 py-2.5 text-right font-mono text-[13px] font-bold text-emerald-400">
                                <?= rupees($lot['sold_price']) ?>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <form method="post" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="undo">
                                    <input type="hidden" name="lot_id" value="<?= (int) $lot['lot_id'] ?>">
                                    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                                    <input type="hidden" name="q" value="<?= e($search) ?>">
                                    <button type="submit"
                                            class="rounded-lg border border-white/10 px-2.5 py-1 text-[11px] font-bold text-slate-400 transition hover:border-rose-400/30 hover:bg-rose-500/10 hover:text-rose-300">
                                        Undo
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="mt-2 text-[11px] text-slate-500">
            <strong class="text-slate-400">Undo</strong> returns the money, takes the player out of the squad
            and puts them back in the queue — use it the moment you notice a wrong figure.
        </p>
    </section>
<?php endif; ?>

<!-- ============================================================ UNSOLD -->
<?php if ($unsold !== []): ?>
    <section class="mt-9">
        <h2 class="text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">
            Passed over <span class="ml-1 text-slate-600">(<?= count($unsold) ?>)</span>
        </h2>
        <p class="mt-1 text-[12px] text-slate-500">Put any of them back in the queue for a later round.</p>

        <div class="mt-3 flex flex-wrap gap-2">
            <?php foreach ($unsold as $lot): ?>
                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="relist">
                    <input type="hidden" name="lot_id" value="<?= (int) $lot['lot_id'] ?>">
                    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                    <input type="hidden" name="q" value="<?= e($search) ?>">
                    <button type="submit"
                            class="rounded-xl border border-white/10 bg-white/[0.03] px-3.5 py-2 text-[13px] font-semibold text-slate-300 transition hover:border-emerald-400/30 hover:bg-emerald-500/10 hover:text-emerald-300">
                        <?= e((string) $lot['full_name']) ?>
                        <span class="ml-1 text-[11px] text-slate-500">re-list</span>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php page_foot(); ?>
