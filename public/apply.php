<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Join a tournament with its secret code
 * =====================================================================
 *
 *  The code is how a player gets in. Applying files an application; an
 *  administrator approves it, and only then does the name appear in the
 *  auction list.
 *
 *  The code is never listed on this page. Anyone who has one was given it
 *  by the organisers, which is exactly the point of having one.
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
$error       = null;
$joined      = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            $joined = $tournaments->apply($userId, (string) ($_POST['secret_code'] ?? ''));
        } catch (AccountException $e) {
            $error = $e->getMessage();
        }
    }
}

$applications = $tournaments->myApplications($userId);

$links = [
    ['href' => 'profile.php', 'label' => 'My details'],
    ['href' => 'apply.php',   'label' => 'Join a tournament', 'current' => true],
    ['href' => 'password.php','label' => 'Password'],
    ['href' => 'auction.php', 'label' => 'Live auction'],
];

if (Auth::is(Auth::ROLE_OWNER)) {
    array_splice($links, 1, 0, [['href' => 'team.php', 'label' => 'My team']]);
}

page_head('Join a tournament', '', $links);
page_message($error);
?>

<div class="mx-auto max-w-2xl">

    <?php if ($joined !== null): ?>

        <div class="rounded-2xl border border-emerald-400/25 bg-emerald-500/[0.07] p-8 text-center">
            <h1 class="text-xl font-extrabold tracking-tight text-white">
                Applied to <?= e((string) $joined['tournament']) ?>
            </h1>
            <p class="mx-auto mt-3 max-w-md text-sm leading-relaxed text-slate-300">
                An administrator will review your application. Your name goes into the auction list
                once it is approved — you do not need to do anything else.
            </p>
            <a href="profile.php"
               class="mt-6 inline-block rounded-xl bg-gradient-to-r from-emerald-400 to-emerald-500 px-6 py-3 text-[13px] font-black uppercase tracking-wide text-slate-950 hover:brightness-110">
                Back to my details
            </a>
        </div>

    <?php else: ?>

        <h1 class="text-2xl font-extrabold tracking-tight text-white">Join a tournament</h1>
        <p class="mt-2 text-sm text-slate-400">
            The organisers will have given you a code. Type it exactly as you received it —
            upper or lower case, spaces or none, it makes no difference.
        </p>

        <form method="post" class="mt-6 rounded-2xl border border-white/10 bg-white/[0.03] p-6">
            <?= csrf_field() ?>

            <label for="f_secret_code" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">
                Tournament code
            </label>
            <input id="f_secret_code" name="secret_code" type="text" required autofocus autocomplete="off"
                   maxlength="20" placeholder="e.g. KXQ7RBTM"
                   class="w-full rounded-xl border border-white/10 bg-slate-950/60 px-4 py-4 text-center font-mono text-2xl font-black uppercase tracking-[0.35em] text-white outline-none transition placeholder:tracking-normal placeholder:text-slate-700 focus:border-emerald-400/50 focus:ring-2 focus:ring-emerald-400/20">

            <p class="mt-3 text-[11px] leading-relaxed text-slate-500">
                Codes never contain the characters that get misread — no zero or letter O,
                no one, I or L. If you think you see one, it is something else.
            </p>

            <div class="mt-5">
                <?php submit_button('Apply'); ?>
            </div>
        </form>

    <?php endif; ?>

    <?php if ($applications !== []): ?>
        <section class="mt-10">
            <h2 class="text-[13px] font-bold uppercase tracking-wider text-slate-400">Where my applications stand</h2>

            <ul class="mt-4 space-y-3">
                <?php foreach ($applications as $app): ?>
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3.5">
                        <div>
                            <p class="text-sm font-bold text-white"><?= e((string) $app['name']) ?>
                                <span class="ml-1 text-[12px] font-medium text-slate-500"><?= e((string) $app['season_year']) ?></span>
                            </p>
                            <p class="mt-0.5 text-[11px] text-slate-500">
                                Auction <?= e(pretty_date($app['auction_date'])) ?>
                                · Starts <?= e(pretty_date($app['start_date'])) ?>
                            </p>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide <?=
                            match ($app['status']) {
                                'approved' => 'bg-emerald-500/15 text-emerald-300',
                                'pending'  => 'bg-amber-400/15 text-amber-300',
                                default    => 'bg-slate-500/15 text-slate-400',
                            } ?>">
                            <?= e((string) $app['status']) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</div>

<?php page_foot(); ?>
