<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Players in the auction pool
 * =====================================================================
 *
 *  Approval, on the Applications screen, is a fast decision made while
 *  somebody is waiting. It sets a base price, a set and an overseas flag
 *  in about four seconds. This screen is where those get corrected
 *  afterwards, along with everything else the player card shows.
 *
 *  It exists so that fixing a base price does not mean opening
 *  phpMyAdmin. The number lives in two tables — players and auction_lots
 *  — and an UPDATE that touches one of them leaves the auction sheet
 *  bidding from the old figure. The service keeps the pair in step.
 *
 *  The money is only editable while a lot is still queued. Once it has
 *  been called, two CHECK constraints in the schema will refuse the
 *  change anyway; being told that in a sentence, before saving, is
 *  better than an error number after.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/app/Views/layouts/shell.php';
require_once BASE_PATH . '/app/Views/partials/player_kinds.php';

use App\Core\Auth;
use App\Core\Security;
use App\Exceptions\AccountException;
use App\Services\TournamentService;

// A tournament administrator set these fields when they approved the
// player, so they may correct them. Scope is enforced twice: the list of
// tournaments they are offered is narrowed, and the player they end up
// editing is checked against the tournament that player really belongs to.
Auth::require(Auth::ROLE_ADMIN, Auth::ROLE_TADMIN);

$tournaments = new TournamentService();
$error       = null;
$editingId   = null;

$all = $tournaments->listTournamentsForCurrentUser();

$links = [
    ['href' => 'index.php',        'label' => 'Overview'],
    ['href' => 'users.php',        'label' => 'People'],
    ['href' => 'tournaments.php',  'label' => 'Tournaments'],
    ['href' => 'applications.php', 'label' => 'Applications'],
    ['href' => 'players.php',      'label' => 'Players', 'current' => true],
    ['href' => 'teams.php',        'label' => 'Teams'],
    ['href' => 'auction.php',      'label' => 'Auction'],
];

if ($all === []) {
    page_head('Players', '../', $links);
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
        $playerId = (int) ($_POST['player_id'] ?? 0);

        // The tournament comes from the player's own row. The posted
        // tournament_id is only a hint for the redirect, and a hint is not
        // a permission.
        Auth::requireWorksOn((int) Database::scalar(
            'SELECT tournament_id FROM players WHERE id = :p',
            [':p' => $playerId]
        ) ?: null);

        try {
            // Only the keys that actually arrived. A checkbox sends nothing
            // when it is off, so is_overseas and is_capped are added by
            // hand — without that, unticking one would never save.
            $in = array_intersect_key($_POST, array_flip([
                'full_name', 'display_name', 'country', 'role',
                'batting_style', 'bowling_style', 'auction_set', 'base_price',
                'career_matches', 'career_runs', 'career_wickets',
                'strike_rate', 'economy',
            ]));

            $in['is_overseas'] = !empty($_POST['is_overseas']);
            $in['is_capped']   = !empty($_POST['is_capped']);

            $saved = $tournaments->updatePlayer($playerId, $in);

            flash('success', $saved['name'] . ' updated.'
                . ($saved['base_price'] ? ' The auction sheet has the new base price too.' : ''));

            header('Location: players.php?tournament=' . (int) ($_POST['tournament_id'] ?? 0));
            exit;
        } catch (AccountException $e) {
            $error     = $e->getMessage();
            // Keep the panel open on the player that failed, so the person
            // can see what they typed rather than hunting for the row again.
            $editingId = $playerId;
        }
    }
}

$tournamentId = isset($_GET['tournament']) ? (int) $_GET['tournament'] : (int) $all[0]['id'];
Auth::requireWorksOn($tournamentId);

$tournament = $tournaments->find($tournamentId);
$players    = $tournaments->poolPlayers($tournamentId);
$editingId  = $editingId ?? (isset($_GET['edit']) ? (int) $_GET['edit'] : null);

$bowlingStyles = TournamentService::BOWLING_STYLES;
$battingStyles = ['' => 'Not recorded', 'right_hand' => 'Right-hand', 'left_hand' => 'Left-hand'];

/** The sets in use, so the picker offers what this tournament already calls things. */
$sets = [];
foreach ($players as $row) {
    if (($row['auction_set'] ?? '') !== '' && $row['auction_set'] !== null) {
        $sets[(string) $row['auction_set']] = true;
    }
}
ksort($sets);

page_head('Players', '../', $links);
page_message($error);
?>

<h1 class="text-2xl font-extrabold tracking-tight text-white">Players in the auction</h1>
<p class="mt-2 max-w-3xl text-sm text-slate-400">
    Everything approval set, and everything the player card shows, for
    <strong class="text-slate-200"><?= e((string) $tournament['name']) ?></strong>.
    The base price can be changed while a lot is still waiting to be called; after that it is settled money.
</p>

<div class="no-bar mt-5 flex gap-2 overflow-x-auto">
    <?php foreach ($all as $row): ?>
        <a href="players.php?tournament=<?= (int) $row['id'] ?>"
           class="whitespace-nowrap rounded-lg px-3 py-1.5 text-[13px] font-semibold transition <?=
               (int) $row['id'] === $tournamentId
                   ? 'bg-emerald-500/15 text-emerald-300'
                   : 'border border-white/10 text-slate-400 hover:bg-white/5' ?>">
            <?= e((string) $row['name']) ?> <?= e((string) $row['season_year']) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($players === []): ?>
    <p class="mt-6 rounded-2xl border border-white/10 bg-white/[0.03] p-8 text-center text-sm text-slate-500">
        Nobody has been approved into this tournament yet.
        <a href="applications.php?tournament=<?= $tournamentId ?>" class="font-semibold text-emerald-400 hover:underline">See the applications.</a>
    </p>
<?php else: ?>
    <p class="mt-4 text-[12px] text-slate-500">
        <?= count($players) ?> in the pool.
    </p>

    <div class="mt-4 space-y-3">
        <?php foreach ($players as $p): ?>
            <?php
            $pid    = (int) $p['id'];
            $lot    = (string) ($p['lot_status'] ?? '');
            $locked = ($lot !== '' && $lot !== 'queued')
                   || in_array($p['status'], ['sold', 'in_auction'], true);
            ?>
            <details class="rounded-2xl border border-white/10 bg-white/[0.03]" <?= $editingId === $pid ? 'open' : '' ?>>
                <summary class="flex cursor-pointer flex-wrap items-center gap-3 px-5 py-4">
                    <?php if (!empty($p['photo_url'])): ?>
                        <img src="<?= e('../' . $p['photo_url']) ?>" alt=""
                             class="h-10 w-10 shrink-0 rounded-lg border border-white/15 object-cover">
                    <?php else: ?>
                        <div class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-white/10 bg-white/5 text-sm font-black text-slate-600">
                            <?= e(strtoupper(mb_substr((string) $p['full_name'], 0, 1))) ?>
                        </div>
                    <?php endif; ?>

                    <div class="min-w-[12rem] flex-1">
                        <p class="text-[15px] font-extrabold text-white"><?= e((string) $p['full_name']) ?></p>
                        <p class="text-[11.5px] text-slate-500">
                            <?= e(player_kind($p['role'])) ?>
                            <?php if (!empty($p['auction_set'])): ?> · <?= e((string) $p['auction_set']) ?><?php endif; ?>
                            <?php if ((int) $p['is_overseas'] === 1): ?> · Overseas<?php endif; ?>
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Base</p>
                        <p class="font-mono text-sm font-bold text-slate-200"><?= e(rupees($p['base_price'])) ?></p>
                    </div>

                    <span class="shrink-0 rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide <?=
                        match ((string) $p['status']) {
                            'sold'       => 'bg-emerald-500/15 text-emerald-300',
                            'in_auction' => 'bg-amber-400/15 text-amber-300',
                            'unsold'     => 'bg-slate-500/15 text-slate-400',
                            'withdrawn'  => 'bg-rose-500/15 text-rose-300',
                            default      => 'bg-sky-500/10 text-sky-300',
                        } ?>">
                        <?= e(str_replace('_', ' ', (string) $p['status'])) ?>
                        <?php if (!empty($p['team_name'])): ?> · <?= e((string) $p['team_name']) ?><?php endif; ?>
                    </span>
                </summary>

                <form method="post" class="grid gap-4 border-t border-white/5 p-5 sm:grid-cols-2 lg:grid-cols-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="player_id" value="<?= $pid ?>">
                    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">

                    <?php
                    field('full_name', 'Full name', (string) $p['full_name']);
                    field('display_name', 'Short name', (string) ($p['display_name'] ?? ''), 'text', false, 'For the scorecard.');
                    field('country', 'Country', (string) $p['country']);
                    select_field('role', 'Type of player', player_kinds(false), (string) $p['role']);

                    select_field('batting_style', 'Batting', $battingStyles, (string) ($p['batting_style'] ?? ''), false);
                    select_field('bowling_style', 'Bowling', $bowlingStyles, (string) $p['bowling_style'], false);
                    field('auction_set', 'Auction set', (string) ($p['auction_set'] ?? ''), 'text', false,
                          $sets === [] ? 'Marquee, Set A…' : 'In use: ' . implode(', ', array_keys($sets)));

                    field(
                        'base_price',
                        'Base price (₹)',
                        (string) (int) round((float) $p['base_price']),
                        'number',
                        false,
                        $locked ? 'Settled — this lot has been called.' : 'Also updates the auction sheet.',
                        $locked
                    );
                    ?>

                    <div class="flex flex-wrap items-center gap-5 sm:col-span-2 lg:col-span-4">
                        <label class="flex items-center gap-2 text-[12px] font-semibold text-slate-400">
                            <input type="checkbox" name="is_overseas" value="1"
                                   <?= (int) $p['is_overseas'] === 1 ? 'checked' : '' ?>
                                   <?= $locked ? 'disabled' : '' ?>
                                   class="h-4 w-4 rounded border-white/20 bg-slate-950 text-emerald-500">
                            Overseas<?php if ($locked): ?><span class="ml-1 text-slate-600">— fixed once called</span><?php endif; ?>
                        </label>
                        <label class="flex items-center gap-2 text-[12px] font-semibold text-slate-400">
                            <input type="checkbox" name="is_capped" value="1"
                                   <?= (int) $p['is_capped'] === 1 ? 'checked' : '' ?>
                                   class="h-4 w-4 rounded border-white/20 bg-slate-950 text-emerald-500">
                            Capped
                        </label>
                    </div>

                    <?php
                    // A disabled checkbox posts nothing, which would read as
                    // "unticked" and try to clear the flag. This carries the
                    // stored value through so the save is a no-op.
                    if ($locked && (int) $p['is_overseas'] === 1) {
                        echo '<input type="hidden" name="is_overseas" value="1">';
                    }

                    field('career_matches', 'Matches', (string) $p['career_matches'], 'number', false);
                    field('career_runs', 'Runs', (string) $p['career_runs'], 'number', false);
                    field('career_wickets', 'Wickets', (string) $p['career_wickets'], 'number', false);
                    field('strike_rate', 'Strike rate', rtrim(rtrim((string) $p['strike_rate'], '0'), '.'), 'text', false);
                    field('economy', 'Economy', rtrim(rtrim((string) $p['economy'], '0'), '.'), 'text', false);
                    ?>

                    <div class="flex items-end sm:col-span-2 lg:col-span-4">
                        <?php submit_button('Save changes'); ?>
                    </div>
                </form>
            </details>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php page_foot(); ?>
