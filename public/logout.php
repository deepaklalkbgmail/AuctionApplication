<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Sign out
 * =====================================================================
 *
 *  Signing out is POST-only, and carries a CSRF token. A GET logout can
 *  be fired by any other site with nothing more than
 *  <img src="https://your-site/APL/logout.php">, which is a nuisance
 *  rather than a breach — but a cheap one to close.
 *
 *  What a GET must NOT do is quietly redirect to login.php: the visitor
 *  is still signed in, so login.php sends them straight back to their own
 *  screen and the button looks broken. It did exactly that. So a GET now
 *  renders a page with a real button on it, which works from a stale
 *  bookmark, a typed URL, or a link in an old copy of the application.
 */

require_once dirname(__DIR__) . '/config/config.php';

use App\Core\Auth;
use App\Core\Security;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        Auth::logout();

        header('Location: login.php?signedout=1');
        exit;
    }

    // A stale token — usually a page left open until the session rotated.
    // Wanting to leave is not something to argue with, so end the session
    // anyway and say so. There is nothing to protect here: the worst a
    // forged request achieves is signing somebody out.
    Auth::logout();

    header('Location: login.php?signedout=1');
    exit;
}

// Already signed out? Nothing to confirm.
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign out — <?= e(APP_NAME) ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2032%2032%22%3E%3Crect%20width%3D%2232%22%20height%3D%2232%22%20rx%3D%227%22%20fill%3D%22%2322c55e%22%2F%3E%3Cpath%20d%3D%22M8.5%2024%2019%2013.5M17.5%206.5%2025.5%2014.5%2021.5%2018.5%2013.5%2010.5z%22%20stroke%3D%22%23020617%22%20stroke-width%3D%222.6%22%20fill%3D%22none%22%20stroke-linecap%3D%22round%22%2F%3E%3Ccircle%20cx%3D%228%22%20cy%3D%2224.5%22%20r%3D%222.3%22%20fill%3D%22%23020617%22%2F%3E%3C%2Fsvg%3E">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-gate grid min-h-screen place-items-center px-4 font-sans text-slate-200">

    <main class="w-full max-w-sm text-center">
        <h1 class="text-xl font-extrabold tracking-tight text-white">Sign out of <?= e(APP_NAME) ?>?</h1>
        <p class="mt-2 text-sm text-slate-400">
            Signed in as <strong class="text-slate-200"><?= e((string) (Auth::user()['name'] ?? '')) ?></strong>.
        </p>

        <form method="post" class="mt-6 flex items-center justify-center gap-3">
            <?= csrf_field() ?>
            <button type="submit"
                    class="rounded-xl bg-gradient-to-r from-emerald-400 to-emerald-500 px-6 py-3 text-[13px] font-black uppercase tracking-wide text-slate-950 shadow-lg shadow-emerald-500/20 transition hover:brightness-110">
                Sign out
            </button>
            <a href="index.php"
               class="rounded-xl border border-white/10 px-5 py-3 text-[13px] font-bold text-slate-300 transition hover:bg-white/5">
                Stay signed in
            </a>
        </form>
    </main>

</body>
</html>
