<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  My details
 * =====================================================================
 *
 *  A player keeps their mobile number, address and photo up to date, and
 *  sees where their tournament applications stand.
 *
 *  Name and email are shown but not editable. The inputs are marked
 *  readonly for honesty about what the screen does — the actual guarantee
 *  is that AccountService::updateOwnProfile() has no name or email
 *  parameter, so re-enabling the input in a browser achieves nothing.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/app/Views/layouts/shell.php';

use App\Core\Auth;
use App\Core\Security;
use App\Exceptions\AccountException;
use App\Services\AccountService;
use App\Services\TournamentService;

Auth::require();

$accounts    = new AccountService();
$tournaments = new TournamentService();
$userId      = (int) Auth::id();
$error       = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            $accounts->updateOwnProfile($userId, $_POST, $_FILES['photo'] ?? null);
            flash('success', 'Your details have been saved.');
            header('Location: profile.php');
            exit;
        } catch (AccountException $e) {
            $error = $e->getMessage();
        }
    }
}

$me           = $accounts->findUser($userId);
$applications = $tournaments->myApplications($userId);

$links = [
    ['href' => 'profile.php', 'label' => 'My details', 'current' => true],
    ['href' => 'apply.php',   'label' => 'Join a tournament'],
    ['href' => 'password.php','label' => 'Password'],
    ['href' => 'auction.php', 'label' => 'Live auction'],
];

if (Auth::is(Auth::ROLE_OWNER)) {
    array_splice($links, 1, 0, [['href' => 'team.php', 'label' => 'My team']]);
}

page_head('My details', '', $links);
page_message($error);
?>

<div class="grid gap-8 lg:grid-cols-[1fr,340px]">

    <!-- ------------------------------------------------------- details -->
    <section>
        <h1 class="text-2xl font-extrabold tracking-tight text-white">My details</h1>
        <p class="mt-2 text-sm text-slate-400">
            Keep your mobile number, address and photo current — this is how the organisers reach you.
        </p>

        <form method="post" enctype="multipart/form-data"
              class="mt-6 space-y-5 rounded-2xl border border-white/10 bg-white/[0.03] p-6">
            <?= csrf_field() ?>

            <div class="rounded-xl border border-white/10 bg-slate-950/40 p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Permanent — set at registration</p>
                <div class="mt-3 space-y-4">
                    <?php
                    field('display_name', 'Full name', (string) $me['name'], 'text', false,
                        'Only an administrator can change this. Ask the organisers if it is wrong.', readonly: true);
                    field('display_email', 'Email address', (string) $me['email'], 'text', false,
                        'Only an administrator can change this.', readonly: true);
                    field('display_username', 'Username', (string) ($me['username'] ?? ''), 'text', false,
                        'What you sign in with.', readonly: true);
                    ?>
                </div>
            </div>

            <?php
            field('phone', 'Mobile number', (string) ($me['phone'] ?? ''), 'tel');
            field('address', 'Address', (string) ($me['address'] ?? ''), 'text');
            select_field('player_type', 'Kind of player', player_type_options(), (string) ($me['player_type'] ?? ''), false);
            ?>

            <div>
                <label for="f_photo" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">
                    Photo <span class="ml-1 font-medium normal-case tracking-normal text-slate-600">leave empty to keep the current one</span>
                </label>
                <input id="f_photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp"
                       class="w-full rounded-xl border border-white/10 bg-slate-950/60 px-3.5 py-2.5 text-sm text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-1.5 file:text-[12px] file:font-bold file:text-slate-200">
                <p class="mt-1.5 text-[11px] text-slate-500">JPEG, PNG or WebP, up to 3 MB.</p>
            </div>

            <?php submit_button('Save changes'); ?>
        </form>
    </section>

    <!-- --------------------------------------------------------- aside -->
    <aside class="space-y-6">

        <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-6 text-center">
            <?php if (!empty($me['photo_path'])): ?>
                <img src="<?= e((string) $me['photo_path']) ?>" alt=""
                     class="mx-auto h-24 w-24 rounded-full border-2 border-emerald-400/40 object-cover">
            <?php else: ?>
                <div class="mx-auto grid h-24 w-24 place-items-center rounded-full border-2 border-dashed border-white/15 bg-white/5 text-2xl font-black text-slate-600">
                    <?= e(strtoupper(mb_substr((string) $me['name'], 0, 1))) ?>
                </div>
            <?php endif; ?>

            <p class="mt-4 text-base font-extrabold text-white"><?= e((string) $me['name']) ?></p>
            <p class="text-[12px] text-slate-500"><?= e((string) $me['email']) ?></p>

            <span class="mt-3 inline-block rounded-full px-3 py-1 text-[11px] font-bold uppercase tracking-wide <?=
                $me['status'] === 'approved'
                    ? 'bg-emerald-500/15 text-emerald-300'
                    : ($me['status'] === 'pending' ? 'bg-amber-400/15 text-amber-300' : 'bg-rose-500/15 text-rose-300') ?>">
                <?= e((string) $me['status']) ?>
            </span>
        </div>

        <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-6">
            <div class="flex items-baseline justify-between">
                <h2 class="text-[13px] font-bold uppercase tracking-wider text-slate-400">My tournaments</h2>
                <a href="apply.php" class="text-[12px] font-semibold text-emerald-400 hover:underline">Join one</a>
            </div>

            <?php if ($applications === []): ?>
                <p class="mt-4 text-[13px] leading-relaxed text-slate-500">
                    You have not applied to a tournament yet. You will need the secret code from the organisers.
                </p>
            <?php else: ?>
                <ul class="mt-4 space-y-3">
                    <?php foreach ($applications as $app): ?>
                        <li class="rounded-xl border border-white/10 bg-slate-950/40 p-3.5">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-[13px] font-bold text-white"><?= e((string) $app['name']) ?></p>
                                    <p class="text-[11px] text-slate-500"><?= e((string) $app['season_year']) ?></p>
                                </div>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide <?=
                                    match ($app['status']) {
                                        'approved'  => 'bg-emerald-500/15 text-emerald-300',
                                        'pending'   => 'bg-amber-400/15 text-amber-300',
                                        default     => 'bg-slate-500/15 text-slate-400',
                                    } ?>">
                                    <?= e((string) $app['status']) ?>
                                </span>
                            </div>

                            <?php if ($app['status'] === 'approved'): ?>
                                <p class="mt-2 text-[12px] text-slate-400">
                                    <?php if ($app['player_status'] === 'sold'): ?>
                                        Sold to <strong class="text-emerald-300"><?= e((string) $app['team_name']) ?></strong>
                                    <?php elseif ($app['player_status'] === 'unsold'): ?>
                                        Unsold in this round — you may be re-listed.
                                    <?php else: ?>
                                        In the auction pool. Auction on <?= e(pretty_date($app['auction_date'])) ?>.
                                    <?php endif; ?>
                                </p>
                            <?php elseif ($app['status'] === 'pending'): ?>
                                <p class="mt-2 text-[12px] text-slate-500">Waiting for an administrator.</p>
                            <?php elseif (!empty($app['note'])): ?>
                                <p class="mt-2 text-[12px] text-slate-500"><?= e((string) $app['note']) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php page_foot(); ?>
