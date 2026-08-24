<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  The shell every account screen renders inside
 * =====================================================================
 *
 *  Registration, profiles, applications and the admin hub all share one
 *  header, one set of links and one way of showing a message, so the
 *  screens themselves stay short enough to read in one go.
 *
 *  No JavaScript. These are forms: they post, the server answers, the
 *  page re-renders. That also means they render under a strict
 *  Content-Security-Policy without an exception.
 *
 *  $up is how far the current page sits below public/ — '' for
 *  public/register.php, '../' for public/admin/users.php. Links are built
 *  from it rather than from a hard-coded root, so the application works
 *  the same at example.com/ and at example.com/APL/.
 */

use App\Core\Auth;

/** A one-shot message carried across a redirect. */
function flash(string $kind, string $message): void
{
    $_SESSION['flash'] = ['kind' => $kind, 'message' => $message];
}

/** @return array{kind:string,message:string}|null */
function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

/** Where a signed-in person belongs. */
function home_for_role(?string $role, string $up = ''): string
{
    return $up . match ($role) {
        Auth::ROLE_SCORER => 'score.php',
        Auth::ROLE_ADMIN  => 'admin/index.php',
        Auth::ROLE_PLAYER => 'profile.php',
        Auth::ROLE_OWNER  => 'team.php',
        default           => 'auction.php',
    };
}

/**
 * @param array<int,array{href:string,label:string,current?:bool}> $links
 */
function page_head(string $title, string $up = '', array $links = []): void
{
    $user = Auth::user();

    ?><!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> — <?= e(APP_NAME) ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2032%2032%22%3E%3Crect%20width%3D%2232%22%20height%3D%2232%22%20rx%3D%227%22%20fill%3D%22%2322c55e%22%2F%3E%3Cpath%20d%3D%22M8.5%2024%2019%2013.5M17.5%206.5%2025.5%2014.5%2021.5%2018.5%2013.5%2010.5z%22%20stroke%3D%22%23020617%22%20stroke-width%3D%222.6%22%20fill%3D%22none%22%20stroke-linecap%3D%22round%22%2F%3E%3Ccircle%20cx%3D%228%22%20cy%3D%2224.5%22%20r%3D%222.3%22%20fill%3D%22%23020617%22%2F%3E%3C%2Fsvg%3E">
    <link rel="stylesheet" href="<?= e($up) ?>assets/css/app.css">
</head>
<body class="bg-gate min-h-screen font-sans text-slate-200">

<header class="border-b border-white/10 bg-slate-950/50">
    <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3.5">

        <a href="<?= e($up) ?>index.php" class="flex items-center gap-2.5">
            <span class="grid h-9 w-9 place-items-center rounded-lg bg-gradient-to-br from-emerald-400 to-emerald-600">
                <svg viewBox="0 0 24 24" class="h-5 w-5 text-slate-950" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                    <path d="M4.5 19.5 14 10"/><path d="M13 3.8 20.2 11l-3.4 3.4L9.6 7.2z"/><circle cx="5" cy="19" r="1.6" fill="currentColor" stroke="none"/>
                </svg>
            </span>
            <span class="text-[15px] font-extrabold tracking-tight text-white"><?= e(APP_NAME) ?></span>
        </a>

        <?php if ($links !== []): ?>
            <nav class="no-bar order-3 -mx-1 flex w-full gap-1 overflow-x-auto sm:order-none sm:mx-0 sm:w-auto">
                <?php foreach ($links as $link): ?>
                    <a href="<?= e($link['href']) ?>"
                       class="whitespace-nowrap rounded-lg px-3 py-1.5 text-[13px] font-semibold transition <?= !empty($link['current'])
                           ? 'bg-emerald-500/15 text-emerald-300'
                           : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <?= e($link['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <div class="ml-auto flex items-center gap-3">
            <?php if ($user !== null): ?>
                <span class="hidden text-[12px] text-slate-400 sm:block">
                    <?= e((string) $user['name']) ?>
                    <span class="ml-1 rounded bg-white/5 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-400">
                        <?= e(str_replace('_', ' ', (string) $user['role'])) ?>
                    </span>
                </span>
                <?php // A POST, because logout.php refuses a GET: any other site
                      // could sign you out with an <img src="…/logout.php">. ?>
                <form method="post" action="<?= e($up) ?>logout.php" class="inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="text-[13px] font-semibold text-slate-400 transition hover:text-rose-300">
                        Sign out
                    </button>
                </form>
            <?php else: ?>
                <a href="<?= e($up) ?>login.php" class="text-[13px] font-semibold text-slate-300 hover:text-emerald-300">Sign in</a>
                <a href="<?= e($up) ?>register.php"
                   class="rounded-lg bg-emerald-500 px-3 py-1.5 text-[13px] font-bold text-slate-950 hover:brightness-110">Register</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="mx-auto max-w-5xl px-4 py-8">
<?php
}

/** The flash message, if there is one, plus any inline error. */
function page_message(?string $error = null, ?string $success = null): void
{
    $flash = take_flash();

    if ($flash !== null) {
        $kind    = $flash['kind'];
        $message = $flash['message'];
    } elseif ($error !== null) {
        $kind    = 'error';
        $message = $error;
    } elseif ($success !== null) {
        $kind    = 'success';
        $message = $success;
    } else {
        return;
    }

    $classes = $kind === 'error'
        ? 'border-rose-400/30 bg-rose-500/10 text-rose-200'
        : 'border-emerald-400/30 bg-emerald-500/10 text-emerald-200';

    ?>
    <p role="alert" class="mb-6 rounded-xl border px-4 py-3 text-[13px] font-semibold <?= $classes ?>">
        <?= e($message) ?>
    </p>
    <?php
}

function page_foot(): void
{
    ?>
</main>

<footer class="mx-auto max-w-5xl px-4 pb-10 pt-2 text-center text-[11px] text-slate-600">
    <?= e(APP_NAME) ?> — auction and live scoring
</footer>

</body>
</html>
<?php
}

/**
 * A DOM id that is unique on the page.
 *
 * Two forms on one screen — editing somebody, and creating a scorer —
 * legitimately both have a field called "name". They may share the POST
 * name, since they post separately, but they may not share an id: a
 * duplicate id sends a <label> to the wrong input.
 */
function field_id(string $name): string
{
    static $seen = [];

    $seen[$name] = ($seen[$name] ?? 0) + 1;

    return 'f_' . $name . ($seen[$name] > 1 ? '_' . $seen[$name] : '');
}

/** A labelled text input, because these forms are mostly the same shape. */
function field(
    string $name,
    string $label,
    string $value = '',
    string $type = 'text',
    bool $required = true,
    string $hint = '',
    bool $readonly = false,
    string $placeholder = '',
): void {
    $id = field_id($name);

    ?>
    <div>
        <label for="<?= e($id) ?>" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">
            <?= e($label) ?><?php if (!$required): ?><span class="ml-1 font-medium normal-case tracking-normal text-slate-600">optional</span><?php endif; ?>
        </label>
        <input id="<?= e($id) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>"
               value="<?= e($value) ?>"
               <?= $required ? 'required' : '' ?>
               <?= $readonly ? 'readonly' : '' ?>
               <?= $placeholder !== '' ? 'placeholder="' . e($placeholder) . '"' : '' ?>
               class="w-full rounded-xl border border-white/10 px-3.5 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-emerald-400/50 focus:ring-2 focus:ring-emerald-400/20 <?=
                   $readonly ? 'cursor-not-allowed bg-slate-900/60 text-slate-500' : 'bg-slate-950/60' ?>">
        <?php if ($hint !== ''): ?>
            <p class="mt-1.5 text-[11px] text-slate-500"><?= e($hint) ?></p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * @param array<string,string> $options value => label
 */
function select_field(string $name, string $label, array $options, string $value = '', bool $required = true): void
{
    $id = field_id($name);

    ?>
    <div>
        <label for="<?= e($id) ?>" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">
            <?= e($label) ?>
        </label>
        <select id="<?= e($id) ?>" name="<?= e($name) ?>" <?= $required ? 'required' : '' ?>
                class="w-full rounded-xl border border-white/10 bg-slate-950/60 px-3.5 py-2.5 text-sm text-white outline-none focus:border-emerald-400/50 focus:ring-2 focus:ring-emerald-400/20">
            <?php foreach ($options as $optValue => $optLabel): ?>
                <option value="<?= e((string) $optValue) ?>" <?= (string) $optValue === $value ? 'selected' : '' ?>>
                    <?= e($optLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php
}

function submit_button(string $label, string $tone = 'primary'): void
{
    $classes = $tone === 'primary'
        ? 'bg-gradient-to-r from-emerald-400 to-emerald-500 text-slate-950 shadow-lg shadow-emerald-500/20 hover:brightness-110'
        : ($tone === 'danger'
            ? 'border border-rose-400/30 bg-rose-500/10 text-rose-200 hover:bg-rose-500/20'
            : 'border border-white/10 bg-white/5 text-slate-200 hover:bg-white/10');

    ?>
    <button type="submit" class="rounded-xl px-4 py-2.5 text-[13px] font-black uppercase tracking-wide transition <?= $classes ?>">
        <?= e($label) ?>
    </button>
    <?php
}

/** The kinds of cricketer, for a select. */
function player_type_options(bool $blankFirst = true): array
{
    $options = [
        'batsman'       => 'Batsman',
        'bowler'        => 'Bowler',
        'all_rounder'   => 'All-rounder',
        'wicket_keeper' => 'Wicket-keeper',
    ];

    return $blankFirst ? ['' => 'Choose one…'] + $options : $options;
}

/**
 * Money the way the room says it: ₹12,34,567.
 *
 * Indian digit grouping — last three, then pairs. Written out in full
 * rather than as "₹12.35 L", because an abbreviation is one more thing to
 * decode when somebody is checking whether a team can afford a bid.
 */
function rupees(float|string|null $amount): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }

    $n     = (int) round((float) $amount);
    $s     = (string) abs($n);
    $last3 = substr($s, -3);
    $rest  = substr($s, 0, -3);

    if ($rest !== '') {
        $last3 = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last3;
    }

    return ($n < 0 ? '-' : '') . '₹' . $last3;
}

/** Pretty-print a stored DATE, or a dash when it is not set. */
function pretty_date(?string $date): string
{
    if ($date === null || $date === '' || $date === '0000-00-00') {
        return '—';
    }

    $ts = strtotime($date);

    return $ts === false ? $date : date('j M Y', $ts);
}
