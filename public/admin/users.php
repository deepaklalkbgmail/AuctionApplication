<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  People — approvals, details, staff accounts, passwords
 * =====================================================================
 *
 *  Four jobs on one screen because they are the same job seen from
 *  different angles: deciding who is real, keeping their details right,
 *  and handing out the credentials that let staff work.
 *
 *  This is the only place a player's name or email can be changed. That
 *  asymmetry is the point — a player cannot quietly become someone else,
 *  but a genuine typo is still fixable.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/app/Views/layouts/shell.php';

use App\Core\Auth;
use App\Core\Security;
use App\Exceptions\AccountException;
use App\Services\AccountService;

Auth::require(Auth::ROLE_ADMIN);

$accounts = new AccountService();
$adminId  = (int) Auth::id();
$error    = null;
$issued   = null;                       // credentials to read out, once

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        try {
            switch ($action) {
                case 'approve':
                case 'reject':
                    $decision = $accounts->decideRegistration(
                        (int) ($_POST['user_id'] ?? 0),
                        $action === 'approve',
                        $adminId
                    );
                    flash('success', 'Registration ' . $decision['status'] . '.');
                    header('Location: users.php?status=pending');
                    exit;

                case 'update':
                    $accounts->adminUpdateUser(
                        (int) ($_POST['user_id'] ?? 0),
                        $_POST,
                        $_FILES['photo'] ?? null
                    );
                    flash('success', 'Details saved.');
                    header('Location: users.php?edit=' . (int) ($_POST['user_id'] ?? 0));
                    exit;

                case 'create_staff':
                    $issued = $accounts->createStaffAccount(
                        (string) ($_POST['name'] ?? ''),
                        (string) ($_POST['username'] ?? ''),
                        (string) ($_POST['email'] ?? ''),
                        (string) ($_POST['role'] ?? 'scorer'),
                        // Blank means "generate one" — which is the better
                        // habit, so the field is optional.
                        trim((string) ($_POST['password'] ?? '')) !== ''
                            ? (string) $_POST['password'] : null
                    );
                    break;

                case 'reset_password':
                    $userId   = (int) ($_POST['user_id'] ?? 0);
                    $password = $accounts->adminResetPassword($userId);
                    $user     = $accounts->findUser($userId);
                    $issued   = [
                        'user_id'  => $userId,
                        'username' => (string) ($user['username'] ?? $user['email']),
                        'password' => $password,
                        'reset'    => true,
                    ];
                    break;
            }
        } catch (AccountException $e) {
            $error = $e->getMessage();
        }
    }
}

$filter  = (string) ($_GET['status'] ?? 'all');
$editing = isset($_GET['edit']) ? $accounts->findUser((int) $_GET['edit']) : null;

$people = Database::all(
    'SELECT u.id, u.username, u.name, u.email, u.phone, u.address, u.photo_path,
            u.player_type, u.role, u.status, u.must_change_password, u.created_at,
            t.name AS team_name
       FROM users u
  LEFT JOIN teams t ON t.id = u.team_id
      WHERE (:all = 1 OR u.status = :status)
   ORDER BY FIELD(u.status, \'pending\', \'approved\', \'rejected\', \'suspended\'), u.name',
    [':all' => $filter === 'all' ? 1 : 0, ':status' => $filter]
);

$links = [
    ['href' => 'index.php',        'label' => 'Overview'],
    ['href' => 'users.php',        'label' => 'People', 'current' => true],
    ['href' => 'tournaments.php',  'label' => 'Tournaments'],
    ['href' => 'applications.php', 'label' => 'Applications'],
    ['href' => 'teams.php',        'label' => 'Teams'],
];

page_head('People', '../', $links);
page_message($error);
?>

<?php if ($issued !== null): ?>
    <div class="mb-6 rounded-2xl border border-emerald-400/30 bg-emerald-500/[0.07] p-6">
        <p class="text-[13px] font-bold uppercase tracking-wider text-emerald-300">
            <?= !empty($issued['reset']) ? 'Password reset' : 'Account created' ?> — read this out now
        </p>
        <p class="mt-2 text-[13px] text-slate-300">
            This password is shown once and is not stored anywhere readable. The account must change it
            at first sign-in.
        </p>
        <dl class="mt-4 flex flex-wrap gap-x-10 gap-y-3">
            <div>
                <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Username</dt>
                <dd class="mt-0.5 font-mono text-lg font-black text-white"><?= e((string) $issued['username']) ?></dd>
            </div>
            <div>
                <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Password</dt>
                <dd class="mt-0.5 font-mono text-lg font-black tracking-widest text-emerald-300"><?= e((string) $issued['password']) ?></dd>
            </div>
        </dl>
    </div>
<?php endif; ?>

<h1 class="text-2xl font-extrabold tracking-tight text-white">People</h1>

<!-- ------------------------------------------------------------ filter -->
<div class="no-bar mt-5 flex gap-2 overflow-x-auto">
    <?php foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected',
                    'suspended' => 'Suspended', 'all' => 'Everyone'] as $value => $label): ?>
        <a href="users.php?status=<?= e($value) ?>"
           class="whitespace-nowrap rounded-lg px-3 py-1.5 text-[13px] font-semibold transition <?= $filter === $value
               ? 'bg-emerald-500/15 text-emerald-300'
               : 'border border-white/10 text-slate-400 hover:bg-white/5' ?>">
            <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- -------------------------------------------------------------- list -->
<?php if ($people === []): ?>
    <p class="mt-6 rounded-2xl border border-white/10 bg-white/[0.03] p-8 text-center text-sm text-slate-500">
        Nobody here.
    </p>
<?php else: ?>
    <ul class="mt-5 space-y-3">
        <?php foreach ($people as $person): ?>
            <li class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                <div class="flex flex-wrap items-start gap-4">

                    <?php if (!empty($person['photo_path'])): ?>
                        <img src="<?= e('../' . $person['photo_path']) ?>" alt=""
                             class="h-12 w-12 shrink-0 rounded-full border border-white/15 object-cover">
                    <?php else: ?>
                        <div class="grid h-12 w-12 shrink-0 place-items-center rounded-full border border-white/10 bg-white/5 text-sm font-black text-slate-500">
                            <?= e(strtoupper(mb_substr((string) $person['name'], 0, 1))) ?>
                        </div>
                    <?php endif; ?>

                    <div class="min-w-[12rem] flex-1">
                        <p class="text-sm font-extrabold text-white"><?= e((string) $person['name']) ?></p>
                        <p class="text-[12px] text-slate-500">
                            <?= e((string) $person['email']) ?>
                            <?php if (!empty($person['username'])): ?>
                                · <span class="font-mono"><?= e((string) $person['username']) ?></span>
                            <?php endif; ?>
                        </p>
                        <p class="mt-1 text-[12px] text-slate-500">
                            <?= e((string) ($person['phone'] ?? '—')) ?>
                            <?php if (!empty($person['address'])): ?>
                                · <?= e((string) $person['address']) ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <span class="rounded bg-white/5 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-400">
                            <?= e(str_replace('_', ' ', (string) $person['role'])) ?>
                        </span>
                        <?php if (!empty($person['team_name'])): ?>
                            <span class="rounded bg-sky-500/10 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-sky-300">
                                <?= e((string) $person['team_name']) ?>
                            </span>
                        <?php endif; ?>
                        <span class="rounded px-2 py-1 text-[10px] font-bold uppercase tracking-wide <?=
                            match ($person['status']) {
                                'approved' => 'bg-emerald-500/15 text-emerald-300',
                                'pending'  => 'bg-amber-400/15 text-amber-300',
                                default    => 'bg-rose-500/15 text-rose-300',
                            } ?>">
                            <?= e((string) $person['status']) ?>
                        </span>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-white/5 pt-3">
                    <?php if ($person['status'] === 'pending'): ?>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                            <button type="submit" class="rounded-lg bg-emerald-500 px-3 py-1.5 text-[12px] font-bold text-slate-950 hover:brightness-110">
                                Approve
                            </button>
                        </form>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                            <button type="submit" class="rounded-lg border border-rose-400/30 bg-rose-500/10 px-3 py-1.5 text-[12px] font-bold text-rose-200 hover:bg-rose-500/20">
                                Reject
                            </button>
                        </form>
                    <?php endif; ?>

                    <a href="users.php?edit=<?= (int) $person['id'] ?>"
                       class="rounded-lg border border-white/10 px-3 py-1.5 text-[12px] font-bold text-slate-300 hover:bg-white/5">
                        Edit details
                    </a>

                    <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                        <button type="submit" class="rounded-lg border border-white/10 px-3 py-1.5 text-[12px] font-bold text-slate-300 hover:bg-white/5">
                            Reset password
                        </button>
                    </form>

                    <?php if ((int) $person['must_change_password'] === 1): ?>
                        <span class="text-[11px] font-semibold text-amber-300/80">must change password at next sign-in</span>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<!-- ------------------------------------------------------------ editor -->
<?php if ($editing !== null): ?>
    <section id="edit" class="mt-10">
        <h2 class="text-[13px] font-bold uppercase tracking-wider text-slate-400">
            Editing <?= e((string) $editing['name']) ?>
        </h2>

        <form method="post" enctype="multipart/form-data"
              class="mt-4 grid gap-5 rounded-2xl border border-white/10 bg-white/[0.03] p-6 sm:grid-cols-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="user_id" value="<?= (int) $editing['id'] ?>">

            <?php
            field('name', 'Full name', (string) $editing['name'], 'text', true,
                'The player cannot change this — you can.');
            field('email', 'Email address', (string) $editing['email'], 'email', true,
                'The player cannot change this — you can.');
            field('phone', 'Mobile number', (string) ($editing['phone'] ?? ''), 'tel');
            field('address', 'Address', (string) ($editing['address'] ?? ''), 'text', false);
            select_field('player_type', 'Kind of player', player_type_options(),
                (string) ($editing['player_type'] ?? ''), false);
            select_field('status', 'Account status', [
                'approved'  => 'Approved',
                'pending'   => 'Waiting for approval',
                'rejected'  => 'Rejected',
                'suspended' => 'Suspended',
            ], (string) $editing['status']);
            ?>

            <div class="sm:col-span-2">
                <label for="f_photo" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">
                    Replace photo <span class="ml-1 font-medium normal-case tracking-normal text-slate-600">optional</span>
                </label>
                <input id="f_photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp"
                       class="w-full rounded-xl border border-white/10 bg-slate-950/60 px-3.5 py-2.5 text-sm text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-1.5 file:text-[12px] file:font-bold file:text-slate-200">
            </div>

            <div class="flex items-center gap-3 sm:col-span-2">
                <?php submit_button('Save details'); ?>
                <a href="users.php" class="text-[13px] font-semibold text-slate-400 hover:text-slate-200">Cancel</a>
            </div>
        </form>
    </section>
<?php endif; ?>

<!-- ------------------------------------------------------ staff accounts -->
<section class="mt-10">
    <h2 class="text-[13px] font-bold uppercase tracking-wider text-slate-400">Create a scorer or administrator</h2>
    <p class="mt-2 max-w-2xl text-[13px] leading-relaxed text-slate-500">
        Scorers do not register themselves — you create the account and hand over the credentials.
        Leave the password blank and one is generated for you; either way the account has to change
        it at first sign-in.
    </p>

    <form method="post" class="mt-4 grid gap-5 rounded-2xl border border-white/10 bg-white/[0.03] p-6 sm:grid-cols-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_staff">

        <?php
        field('name', 'Full name');
        field('username', 'Username', '', 'text', true, 'What they sign in with.');
        field('email', 'Email address', '', 'email');
        select_field('role', 'Role', ['scorer' => 'Scorer', 'viewer' => 'Viewer', 'admin' => 'Administrator'], 'scorer');
        field('password', 'Password', '', 'text', false, 'Leave blank to generate one.');
        ?>

        <div class="flex items-end">
            <?php submit_button('Create account'); ?>
        </div>
    </form>
</section>

<?php page_foot(); ?>
