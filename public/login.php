<?php

declare(strict_types=1);

/**
 * Sign-in. Kept minimal on purpose — it exists so the auction API has a
 * session to authorise against; the styled auth flow lands with the router.
 *
 * Failure is always reported as one generic message: telling an attacker
 * "no such account" versus "wrong password" hands them a user enumerator.
 */

require_once dirname(__DIR__) . '/config/config.php';

use App\Core\Auth;
use App\Core\Security;

if (Auth::check()) {
    header('Location: ' . (Auth::role() === Auth::ROLE_SCORER ? 'score.php' : 'auction.php'));
    exit;
}

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (Auth::attempt(Database::pdo(), $email, $password)) {
            // Straight to the screen this person signed in to use — a scorer
            // at the ground should not have to pass through a hub page.
            header('Location: ' . (Auth::role() === Auth::ROLE_SCORER ? 'score.php' : 'auction.php'));
            exit;
        }

        $error = 'Those credentials do not match our records.';
    }
}

?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in — <?= e(APP_NAME) ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2032%2032%22%3E%3Crect%20width%3D%2232%22%20height%3D%2232%22%20rx%3D%227%22%20fill%3D%22%2322c55e%22%2F%3E%3Cpath%20d%3D%22M8.5%2024%2019%2013.5M17.5%206.5%2025.5%2014.5%2021.5%2018.5%2013.5%2010.5z%22%20stroke%3D%22%23020617%22%20stroke-width%3D%222.6%22%20fill%3D%22none%22%20stroke-linecap%3D%22round%22%2F%3E%3Ccircle%20cx%3D%228%22%20cy%3D%2224.5%22%20r%3D%222.3%22%20fill%3D%22%23020617%22%2F%3E%3C%2Fsvg%3E">
    <link rel="stylesheet" href="assets/css/app.css">

</head>
<body class="bg-gate grid min-h-screen place-items-center px-4 font-sans text-slate-200">

    <main class="w-full max-w-sm">
        <div class="mb-7 flex items-center gap-3">
            <div class="grid h-11 w-11 place-items-center rounded-xl bg-gradient-to-br from-emerald-400 to-emerald-600 shadow-lg shadow-emerald-500/25">
                <svg viewBox="0 0 24 24" class="h-6 w-6 text-slate-950" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                    <path d="M4.5 19.5 14 10"/><path d="M13 3.8 20.2 11l-3.4 3.4L9.6 7.2z"/><circle cx="5" cy="19" r="1.6" fill="currentColor" stroke="none"/>
                </svg>
            </div>
            <div>
                <p class="text-lg font-extrabold tracking-tight text-white"><?= e(APP_NAME) ?></p>
                <p class="text-[12px] font-medium text-slate-400">Sign in to bid or score</p>
            </div>
        </div>

        <form method="post"
              class="space-y-4 rounded-2xl border border-white/10 bg-white/[0.04] p-6 backdrop-blur-xl">

            <?= csrf_field() ?>

            <?php if ($error !== null): ?>
                <p role="alert" class="rounded-xl border border-rose-400/30 bg-rose-500/10 px-3.5 py-2.5 text-[13px] font-semibold text-rose-200">
                    <?= e($error) ?>
                </p>
            <?php endif; ?>

            <div>
                <label for="email" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">Email</label>
                <input id="email" name="email" type="email" required autofocus autocomplete="username"
                       value="<?= e((string) ($_POST['email'] ?? '')) ?>"
                       class="w-full rounded-xl border border-white/10 bg-slate-950/60 px-3.5 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-emerald-400/50 focus:ring-2 focus:ring-emerald-400/20"
                       placeholder="you@club.test">
            </div>

            <div>
                <label for="password" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="w-full rounded-xl border border-white/10 bg-slate-950/60 px-3.5 py-3 text-sm text-white outline-none transition focus:border-emerald-400/50 focus:ring-2 focus:ring-emerald-400/20"
                       placeholder="••••••••">
            </div>

            <button type="submit"
                    class="w-full rounded-xl bg-gradient-to-r from-emerald-400 to-emerald-500 px-4 py-3.5 text-sm font-black uppercase tracking-wide text-slate-950 shadow-lg shadow-emerald-500/25 transition hover:brightness-110">
                Sign in
            </button>
        </form>

        <p class="mt-5 text-center text-[12px] text-slate-500">
            Watching only? <a href="auction.php?role=viewer" class="font-semibold text-emerald-400 hover:underline">Open the live board</a>
        </p>
    </main>
</body>
</html>
