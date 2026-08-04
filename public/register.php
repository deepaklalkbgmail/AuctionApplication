<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Player registration
 * =====================================================================
 *
 *  A player creates their own account. It reaches nothing until an
 *  administrator approves it.
 *
 *  The warning about the name and email being permanent is stated three
 *  times — at the top of the form, beside each of the two fields, and on
 *  the confirmation step — because it is the one thing on this page that
 *  cannot be undone by the person filling it in.
 *
 *  There is a deliberate confirmation step: the form posts back showing
 *  exactly what was typed and asks the player to read the name and email
 *  once more before the account is created. What was typed is held in the
 *  session between the two steps, not in hidden inputs — a password does
 *  not belong in the page source, in the back/forward cache, or in a
 *  screenshot of a form somebody is puzzling over.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/app/Views/layouts/shell.php';

use App\Core\Auth;
use App\Core\Security;
use App\Exceptions\AccountException;
use App\Services\AccountService;

if (Auth::check()) {
    header('Location: ' . home_for_role(Auth::role()));
    exit;
}

$accounts = new AccountService();
$error    = null;
$stage    = 'form';                 // form -> confirm -> done
$in       = [];

const REGISTER_FIELDS = ['name', 'email', 'username', 'phone', 'address', 'player_type'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please fill the form in again.';
    } elseif (($_POST['step'] ?? '') === 'confirm') {
        // Step two. Everything but the photo came from step one and is in
        // the session; a file input cannot survive a page load, so the
        // photo is uploaded here on the final submit.
        $in = $_SESSION['pending_registration'] ?? [];

        if ($in === []) {
            $error = 'Your session expired. Please fill the form in again.';
        } else {
            $photo = ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
                ? $_FILES['photo'] : null;

            try {
                $accounts->register($in, $photo);
                unset($_SESSION['pending_registration']);
                $stage = 'done';
            } catch (AccountException $e) {
                $error = $e->getMessage();
                $stage = 'confirm';
            }
        }
    } else {
        // Step one. Validate, then show it back to be read once more.
        foreach (REGISTER_FIELDS as $key) {
            $in[$key] = trim((string) ($_POST[$key] ?? ''));
        }

        $in['password']         = (string) ($_POST['password'] ?? '');
        $in['password_confirm'] = (string) ($_POST['password_confirm'] ?? '');

        try {
            $accounts->validateRegistration($in);
            $_SESSION['pending_registration'] = $in;
            $stage = 'confirm';
        } catch (AccountException $e) {
            $error = $e->getMessage();
            $stage = 'form';
        }
    }
} elseif (isset($_GET['edit']) && isset($_SESSION['pending_registration'])) {
    // "Go back and edit" — refill the form from what was typed.
    $in = $_SESSION['pending_registration'];
}

page_head('Register as a player');
page_message($error);
?>

<?php if ($stage === 'done'): ?>

    <div class="mx-auto max-w-xl rounded-2xl border border-emerald-400/25 bg-emerald-500/[0.07] p-8 text-center">
        <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-full bg-emerald-500/15">
            <svg viewBox="0 0 24 24" class="h-7 w-7 text-emerald-400" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                <path d="m5 13 4 4L19 7"/>
            </svg>
        </div>
        <h1 class="text-2xl font-extrabold tracking-tight text-white">Registration received</h1>
        <p class="mx-auto mt-3 max-w-md text-sm leading-relaxed text-slate-300">
            An administrator will review your details. You will be able to sign in and apply
            for a tournament as soon as your account is approved.
        </p>
        <p class="mx-auto mt-4 max-w-md text-[13px] leading-relaxed text-slate-400">
            Nothing else is needed from you right now. If your account is still not approved
            after a day or two, speak to the organisers.
        </p>
        <a href="login.php"
           class="mt-7 inline-block rounded-xl bg-gradient-to-r from-emerald-400 to-emerald-500 px-6 py-3 text-[13px] font-black uppercase tracking-wide text-slate-950 hover:brightness-110">
            Go to sign in
        </a>
    </div>

<?php elseif ($stage === 'confirm'): ?>

    <div class="mx-auto max-w-xl">
        <h1 class="text-2xl font-extrabold tracking-tight text-white">Check these before you continue</h1>

        <div class="mt-5 rounded-2xl border border-amber-400/30 bg-amber-400/[0.07] p-5">
            <p class="text-[13px] font-bold uppercase tracking-wider text-amber-300">Read this carefully</p>
            <p class="mt-2 text-sm leading-relaxed text-amber-100/90">
                Your <strong>full name</strong> and <strong>email address</strong> cannot be changed
                by you after this point. They are what the administrator approves and what appears
                on the auction sheet. If either is wrong, go back and correct it now — afterwards
                only an administrator can.
            </p>
        </div>

        <dl class="mt-5 divide-y divide-white/5 rounded-2xl border border-white/10 bg-white/[0.03] px-5">
            <?php
            $rows = [
                'Full name'      => $in['name'],
                'Email address'  => $in['email'],
                'Username'       => $in['username'],
                'Mobile number'  => $in['phone'],
                'Address'        => $in['address'],
                'Kind of player' => player_type_options(false)[$in['player_type']] ?? $in['player_type'],
            ];

            foreach ($rows as $label => $value):
                $permanent = in_array($label, ['Full name', 'Email address'], true);
                ?>
                <div class="flex gap-4 py-3.5">
                    <dt class="w-36 shrink-0 text-[12px] font-semibold uppercase tracking-wide <?= $permanent ? 'text-amber-300/80' : 'text-slate-500' ?>">
                        <?= e($label) ?>
                    </dt>
                    <dd class="text-sm <?= $permanent ? 'font-bold text-white' : 'text-slate-300' ?>">
                        <?= e((string) $value) ?>
                        <?php if ($permanent): ?>
                            <span class="ml-2 rounded bg-amber-400/15 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-300">permanent</span>
                        <?php endif; ?>
                    </dd>
                </div>
            <?php endforeach; ?>
        </dl>

        <form method="post" enctype="multipart/form-data" class="mt-6 space-y-5">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="confirm">

            <div>
                <label for="f_photo" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">
                    Photo <span class="ml-1 font-medium normal-case tracking-normal text-slate-600">optional — you can add or change this later</span>
                </label>
                <input id="f_photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp"
                       class="w-full rounded-xl border border-white/10 bg-slate-950/60 px-3.5 py-2.5 text-sm text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-1.5 file:text-[12px] file:font-bold file:text-slate-200">
                <p class="mt-1.5 text-[11px] text-slate-500">JPEG, PNG or WebP, up to 3 MB.</p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <?php submit_button('Confirm and register'); ?>
                <a href="register.php?edit=1" class="text-[13px] font-semibold text-slate-400 hover:text-slate-200">Go back and edit</a>
            </div>
        </form>
    </div>

<?php else: ?>

    <div class="mx-auto max-w-xl">
        <h1 class="text-2xl font-extrabold tracking-tight text-white">Register as a player</h1>
        <p class="mt-2 text-sm text-slate-400">
            One account per player. An administrator approves it before you can enter an auction.
        </p>

        <div class="mt-5 rounded-2xl border border-amber-400/30 bg-amber-400/[0.07] p-5">
            <p class="text-[13px] font-bold uppercase tracking-wider text-amber-300">Before you start</p>
            <p class="mt-2 text-sm leading-relaxed text-amber-100/90">
                Your <strong>full name</strong> and <strong>email address</strong> are permanent.
                Once you register you will not be able to change them — only an administrator can.
                Your mobile number, address and photo you can update yourself at any time.
            </p>
        </div>

        <form method="post" class="mt-6 space-y-5 rounded-2xl border border-white/10 bg-white/[0.03] p-6">
            <?= csrf_field() ?>

            <?php
            field('name', 'Full name', (string) ($in['name'] ?? ''), 'text', true,
                'Permanent — check the spelling. This is the name that goes on the auction sheet.');

            field('email', 'Email address', (string) ($in['email'] ?? ''), 'email', true,
                'Permanent — you cannot change this later. Use an address you will keep.');

            field('username', 'Username', (string) ($in['username'] ?? ''), 'text', true,
                'What you sign in with. Letters, numbers, dot, underscore or hyphen.');

            field('phone', 'Mobile number', (string) ($in['phone'] ?? ''), 'tel', true,
                'You can change this later.');

            field('address', 'Address', (string) ($in['address'] ?? ''), 'text', true,
                'You can change this later.');

            select_field('player_type', 'Kind of player', player_type_options(), (string) ($in['player_type'] ?? ''));

            field('password', 'Password', '', 'password', true, 'At least 8 characters, with a letter and a number.');
            field('password_confirm', 'Repeat password', '', 'password');
            ?>

            <div class="flex flex-wrap items-center gap-3 pt-1">
                <?php submit_button('Continue'); ?>
                <span class="text-[12px] text-slate-500">You will get to check everything on the next screen.</span>
            </div>
        </form>

        <p class="mt-5 text-center text-[12px] text-slate-500">
            Already registered? <a href="login.php" class="font-semibold text-emerald-400 hover:underline">Sign in</a>
        </p>
    </div>

<?php endif; ?>

<?php page_foot(); ?>
