<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Teams and their owners
 * =====================================================================
 *
 *  An administrator brings a team into existence and names its one owner.
 *  From then on the name is the owner's to set and re-set, up to the
 *  tournament's team name change deadline — which is why the starting
 *  name here can be a placeholder.
 *
 *  One team, one owner. The database enforces it with a unique index on
 *  users.team_id; handing a team to somebody new releases the previous
 *  owner in the same transaction.
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

$all = $tournaments->listTournaments();

$links = [
    ['href' => 'index.php',        'label' => 'Overview'],
    ['href' => 'users.php',        'label' => 'People'],
    ['href' => 'tournaments.php',  'label' => 'Tournaments'],
    ['href' => 'applications.php', 'label' => 'Applications'],
    ['href' => 'teams.php',        'label' => 'Teams', 'current' => true],
    ['href' => 'auction.php',      'label' => 'Auction'],
];

if ($all === []) {
    page_head('Teams', '../', $links);
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
        $action       = (string) ($_POST['action'] ?? '');
        $tournamentId = (int) ($_POST['tournament_id'] ?? 0);

        try {
            switch ($action) {
                case 'create':
                    $created = $tournaments->createTeam(
                        (int) ($_POST['owner_id'] ?? 0),
                        $tournamentId,
                        $_POST
                    );
                    flash('success', $created['name'] . ' created. Its owner can rename it until the deadline.');
                    break;

                case 'assign':
                    $moved = $tournaments->assignOwner(
                        (int) ($_POST['team_id'] ?? 0),
                        (int) ($_POST['owner_id'] ?? 0)
                    );
                    flash('success', $moved['team'] . ' now belongs to ' . $moved['owner'] . '.');
                    break;

                case 'rename':
                    $tournaments->renameTeam(
                        (int) ($_POST['team_id'] ?? 0),
                        (int) Auth::id(),
                        $_POST,
                        actorIsAdmin: true
                    );
                    flash('success', 'Team updated.');
                    break;
            }

            header('Location: teams.php?tournament=' . $tournamentId);
            exit;
        } catch (AccountException $e) {
            $error = $e->getMessage();
        }
    }
}

$tournamentId = isset($_GET['tournament']) ? (int) $_GET['tournament'] : (int) $all[0]['id'];
$tournament   = $tournaments->find($tournamentId);
$teams        = $tournaments->teams($tournamentId);

// Anyone approved who does not already hold a team can be given one.
$candidates = Database::all(
    "SELECT id, name, email, role FROM users
      WHERE status = 'approved' AND team_id IS NULL AND role <> 'scorer'
   ORDER BY name"
);

$candidateOptions = ['' => 'Choose a person…'];
foreach ($candidates as $person) {
    $candidateOptions[(string) $person['id']] = $person['name'] . ' — ' . $person['email'];
}

page_head('Teams', '../', $links);
page_message($error);
?>

<h1 class="text-2xl font-extrabold tracking-tight text-white">Teams</h1>
<p class="mt-2 text-sm text-slate-400">
    Each team has exactly one owner. The owner sets the team name and can change it until
    <strong class="text-slate-200"><?= e(pretty_date($tournament['team_name_change_deadline'])) ?></strong>.
</p>

<div class="no-bar mt-5 flex gap-2 overflow-x-auto">
    <?php foreach ($all as $row): ?>
        <a href="teams.php?tournament=<?= (int) $row['id'] ?>"
           class="whitespace-nowrap rounded-lg px-3 py-1.5 text-[13px] font-semibold transition <?=
               (int) $row['id'] === $tournamentId
                   ? 'bg-emerald-500/15 text-emerald-300'
                   : 'border border-white/10 text-slate-400 hover:bg-white/5' ?>">
            <?= e((string) $row['name']) ?> <?= e((string) $row['season_year']) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($teams === []): ?>
    <p class="mt-6 rounded-2xl border border-white/10 bg-white/[0.03] p-8 text-center text-sm text-slate-500">
        No teams in this tournament yet.
    </p>
<?php else: ?>
    <div class="mt-6 space-y-4">
        <?php foreach ($teams as $team): ?>
            <article class="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-extrabold tracking-tight text-white">
                            <?= e((string) $team['name']) ?>
                            <span class="ml-1 rounded bg-white/5 px-1.5 py-0.5 font-mono text-[11px] font-bold text-slate-400"><?= e((string) $team['short_name']) ?></span>
                        </h2>
                        <p class="mt-1 text-[12px] text-slate-500">
                            <?php if ($team['owner_id'] !== null): ?>
                                Owner: <strong class="text-slate-300"><?= e((string) $team['owner_name']) ?></strong>
                                (<?= e((string) $team['owner_email']) ?>)
                            <?php else: ?>
                                <span class="font-bold text-amber-300">No owner yet</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Purse left</p>
                        <p class="text-xl font-black text-emerald-400">
                            <?= e(rupees($team['purse_remaining'])) ?>
                        </p>
                        <p class="text-[11px] text-slate-500">
                            of <?= e(rupees($team['purse_total'])) ?>
                            · <?= (int) $team['players_bought'] ?> bought
                        </p>
                    </div>
                </div>

                <div class="mt-4 grid gap-4 border-t border-white/5 pt-4 sm:grid-cols-2">
                    <form method="post" class="flex flex-wrap items-end gap-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="rename">
                        <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
                        <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">

                        <div class="min-w-[10rem] flex-1">
                            <?php field('name', 'Rename', (string) $team['name']); ?>
                        </div>
                        <div class="w-24">
                            <?php field('short_name', 'Short', (string) $team['short_name']); ?>
                        </div>
                        <button type="submit" class="mb-0.5 rounded-lg border border-white/10 px-3 py-2.5 text-[12px] font-bold text-slate-300 hover:bg-white/5">
                            Save
                        </button>
                    </form>

                    <form method="post" class="flex flex-wrap items-end gap-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="assign">
                        <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
                        <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">

                        <div class="min-w-[12rem] flex-1">
                            <?php select_field('owner_id', $team['owner_id'] !== null ? 'Hand to' : 'Give an owner', $candidateOptions); ?>
                        </div>
                        <button type="submit" class="mb-0.5 rounded-lg border border-white/10 px-3 py-2.5 text-[12px] font-bold text-slate-300 hover:bg-white/5">
                            Assign
                        </button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- --------------------------------------------------------------- new -->
<section class="mt-10">
    <h2 class="text-[13px] font-bold uppercase tracking-wider text-slate-400">Create a team</h2>
    <p class="mt-2 max-w-2xl text-[13px] leading-relaxed text-slate-500">
        Pick the person who will own it. A working name is enough — the owner can change it
        themselves right up to the deadline, which is the point of having one.
    </p>

    <form method="post" class="mt-4 grid gap-5 rounded-2xl border border-white/10 bg-white/[0.03] p-6 sm:grid-cols-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">

        <?php
        select_field('owner_id', 'Owner', $candidateOptions);
        field('name', 'Team name', '', 'text', true, 'Unique within this tournament.');
        field('short_name', 'Short name', '', 'text', true, '2 to 6 letters or digits, like MI or CSK.');
        field('primary_color', 'Team colour', '#22c55e', 'color', false);
        field('home_venue', 'Home ground', '', 'text', false);
        ?>

        <div class="sm:col-span-2">
            <?php submit_button('Create team'); ?>
        </div>
    </form>
</section>

<?php page_foot(); ?>
