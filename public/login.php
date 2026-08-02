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
    header('Location: index.php');
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
            header('Location: index.php');
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
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: Inter, system-ui, sans-serif;
            background:
                radial-gradient(900px 460px at 15% -10%, rgba(34,197,94,.16), transparent 60%),
                radial-gradient(700px 400px at 90% 8%, rgba(56,189,248,.12), transparent 62%),
                #020617;
        }
    </style>
</head>
<body class="grid min-h-screen place-items-center px-4 text-slate-200">

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
            Watching only? <a href="index.php?role=viewer" class="font-semibold text-emerald-400 hover:underline">Open the live board</a>
        </p>
    </main>
</body>
</html>
