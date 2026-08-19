<?php

declare(strict_types=1);

/**
 * A complete, standalone "nothing here yet" page.
 *
 * Used when a screen has no real data to show. It exists so a clean
 * installation shows an honest empty state rather than falling back to the
 * bundled demonstration fixtures — a production site displaying invented
 * players and teams looks like a bug, and is impossible to tell apart from
 * one.
 *
 * Expects, from the including page:
 *   $emptyTitle   short heading
 *   $emptyBody    one or two sentences explaining what will fill this screen
 *   $emptyHint    optional extra line, e.g. what an administrator should do
 *   $emptyAction  optional ['url', 'label', 'note'] — a POST button, so the
 *                 person who can fix the emptiness can do it from here
 *                 rather than being told to go and find another screen
 *
 * Include it and exit:
 *   require dirname(__DIR__, 2) . '/Views/partials/empty_state.php';
 *   exit;
 */

/** @var string $emptyTitle */
/** @var string $emptyBody */
$emptyHint   ??= null;
$emptyAction ??= null;

?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#020617">
    <title><?= e($emptyTitle) ?> — <?= e(APP_NAME) ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2032%2032%22%3E%3Crect%20width%3D%2232%22%20height%3D%2232%22%20rx%3D%227%22%20fill%3D%22%2322c55e%22%2F%3E%3Cpath%20d%3D%22M8.5%2024%2019%2013.5M17.5%206.5%2025.5%2014.5%2021.5%2018.5%2013.5%2010.5z%22%20stroke%3D%22%23020617%22%20stroke-width%3D%222.6%22%20fill%3D%22none%22%20stroke-linecap%3D%22round%22%2F%3E%3Ccircle%20cx%3D%228%22%20cy%3D%2224.5%22%20r%3D%222.3%22%20fill%3D%22%23020617%22%2F%3E%3C%2Fsvg%3E">
    <link rel="stylesheet" href="assets/css/app.css">
</head>

<body class="bg-arena grid min-h-screen place-items-center px-4 font-sans text-slate-200">

    <main class="w-full max-w-md text-center">

        <div class="mx-auto mb-6 grid h-14 w-14 place-items-center rounded-2xl border border-white/10 bg-white/[0.04]">
            <svg viewBox="0 0 24 24" class="h-7 w-7 text-slate-500" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                <circle cx="12" cy="12" r="9"/><path d="M12 8v4.5"/><path d="M12 16h.01"/>
            </svg>
        </div>

        <h1 class="text-2xl font-black tracking-tight text-white sm:text-3xl"><?= e($emptyTitle) ?></h1>

        <p class="mx-auto mt-3 max-w-sm text-[14px] leading-relaxed text-slate-400">
            <?= e($emptyBody) ?>
        </p>

        <?php if ($emptyHint !== null): ?>
            <p class="mx-auto mt-4 max-w-sm rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3 text-[12.5px] leading-relaxed text-slate-500">
                <?= e($emptyHint) ?>
            </p>
        <?php endif; ?>

        <?php if ($emptyAction !== null): ?>
            <form method="post" action="<?= e($emptyAction['url']) ?>" class="mt-7">
                <?= csrf_field() ?>
                <button type="submit"
                        class="rounded-xl bg-gradient-to-r from-emerald-400 to-emerald-500 px-7 py-3.5 text-[13px] font-black uppercase tracking-wide text-ink-900 shadow-lg shadow-emerald-500/25 transition hover:brightness-110">
                    <?= e($emptyAction['label']) ?>
                </button>
                <?php if (!empty($emptyAction['note'])): ?>
                    <p class="mt-2.5 text-[12px] text-slate-500"><?= e($emptyAction['note']) ?></p>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="index.php"
               class="rounded-xl bg-gradient-to-r from-emerald-400 to-emerald-500 px-5 py-3 text-[13px] font-black uppercase tracking-wide text-ink-900 shadow-lg shadow-emerald-500/25 transition hover:brightness-110">
                Home
            </a>
            <?php if (!\App\Core\Auth::check()): ?>
                <a href="login.php"
                   class="rounded-xl border border-white/15 px-5 py-3 text-[13px] font-black uppercase tracking-wide text-slate-200 transition hover:bg-white/5">
                    Sign in
                </a>
            <?php endif; ?>
        </div>
    </main>

</body>
</html>
