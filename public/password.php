<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Change my password
 * =====================================================================
 *
 *  Everyone changes their password here — the administrator included.
 *
 *  When an administrator has issued or reset a password, Auth::require()
 *  sends every other screen back to this one until it has been replaced,
 *  so a password that was read out over the phone never stays in use.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/app/Views/layouts/shell.php';

use App\Core\Auth;
use App\Core\Security;
use App\Exceptions\AccountException;
use App\Services\AccountService;

Auth::require();

$accounts = new AccountService();
$forced   = Auth::mustChangePassword();
$error    = null;
$done     = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            $accounts->changeOwnPassword(
                (int) Auth::id(),
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['confirm_password'] ?? ''),
            );

            Auth::clearPasswordChangeFlag();
            $done = true;
        } catch (AccountException $e) {
            $error = $e->getMessage();
        }
    }
}

page_head('Change password');
page_message($error);
?>

<div class="mx-auto max-w-md">

    <?php if ($done): ?>

        <div class="rounded-2xl border border-emerald-400/25 bg-emerald-500/[0.07] p-8 text-center">
            <h1 class="text-xl font-extrabold tracking-tight text-white">Password changed</h1>
            <p class="mt-3 text-sm text-slate-300">Use the new one from now on.</p>
            <a href="<?= e(home_for_role(Auth::role())) ?>"
               class="mt-6 inline-block rounded-xl bg-gradient-to-r from-emerald-400 to-emerald-500 px-6 py-3 text-[13px] font-black uppercase tracking-wide text-slate-950 hover:brightness-110">
                Continue
            </a>
        </div>

    <?php else: ?>

        <h1 class="text-2xl font-extrabold tracking-tight text-white">Change password</h1>

        <?php if ($forced): ?>
            <div class="mt-5 rounded-2xl border border-amber-400/30 bg-amber-400/[0.07] p-5">
                <p class="text-[13px] font-bold uppercase tracking-wider text-amber-300">Choose your own password</p>
                <p class="mt-2 text-sm leading-relaxed text-amber-100/90">
                    You are signed in with a password an administrator issued. Replace it with one only
                    you know before going any further.
                </p>
            </div>
        <?php else: ?>
            <p class="mt-2 text-sm text-slate-400">You will need your current password.</p>
        <?php endif; ?>

        <form method="post" class="mt-6 space-y-5 rounded-2xl border border-white/10 bg-white/[0.03] p-6">
            <?= csrf_field() ?>

            <?php
            field('current_password', $forced ? 'The password you were given' : 'Current password', '', 'password');
            field('new_password', 'New password', '', 'password', true,
                'At least 8 characters, with a letter and a number.');
            field('confirm_password', 'Repeat new password', '', 'password');

            submit_button('Change password');
            ?>
        </form>

        <?php if (!$forced): ?>
            <p class="mt-5 text-center text-[12px] text-slate-500">
                <a href="<?= e(home_for_role(Auth::role())) ?>" class="font-semibold text-slate-400 hover:text-slate-200">Back</a>
            </p>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php page_foot(); ?>
