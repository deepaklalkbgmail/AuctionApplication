<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Activity — who changed what, and what it was before
 * =====================================================================
 *
 *  The answer to "who dropped that base price, and what was it?"
 *
 *  Read-only by design. There is no control on this page that changes
 *  anything, and nothing in the application updates or deletes a line
 *  once it is written — a log you can edit is not evidence.
 *
 *  An administrator sees everything. A tournament administrator sees
 *  their own tournament, plus the lines that belong to no tournament at
 *  all only when they are an administrator, because an account approval
 *  is not theirs to see.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/app/Views/layouts/shell.php';
require_once BASE_PATH . '/app/Views/partials/player_kinds.php';

use App\Core\Auth;
use App\Services\ActivityLog;

Auth::require(Auth::ROLE_ADMIN, Auth::ROLE_TADMIN);

$isAdmin = Auth::is(Auth::ROLE_ADMIN);
$mine    = $isAdmin ? null : Auth::tournamentId();

$links = [
    ['href' => 'index.php',        'label' => 'Overview'],
];

if ($isAdmin) {
    $links[] = ['href' => 'users.php',       'label' => 'People'];
    $links[] = ['href' => 'tournaments.php', 'label' => 'Tournaments'];
}

$links[] = ['href' => 'applications.php', 'label' => 'Applications'];
$links[] = ['href' => 'players.php',      'label' => 'Players'];
$links[] = ['href' => 'teams.php',        'label' => 'Teams'];
$links[] = ['href' => 'auction.php',      'label' => 'Auction'];
$links[] = ['href' => 'activity.php',     'label' => 'Activity', 'current' => true];

$available = ActivityLog::isAvailable();
$lines     = $available ? ActivityLog::recent($mine, 300) : [];

/** Plain English for an action code. */
function action_words(string $action): string
{
    return [
        'log.enabled'            => 'Logging switched on',
        'account.approve'        => 'Approved an account',
        'account.reject'         => 'Rejected an account',
        'account.update'         => 'Edited a person',
        'account.create_staff'   => 'Created an account',
        'account.set_tournament' => 'Set somebody\'s tournament',
        'account.reset_password' => 'Reset a password',
        'application.approve'    => 'Let a player into the auction',
        'application.reject'     => 'Rejected an application',
        'player.update'          => 'Edited a player',
        'team.create'            => 'Created a team',
        'team.update'            => 'Edited a team',
        'team.assign_owner'      => 'Handed a team to an owner',
        'tournament.create'      => 'Created a tournament',
        'tournament.update'      => 'Edited a tournament',
        'tournament.cancel'      => 'Cancelled a tournament',
        'tournament.reinstate'   => 'Reinstated a tournament',
        'auction.sold'           => 'Sold a player',
        'auction.unsold'         => 'Passed a player over',
        'auction.undo'           => 'Reversed a sale',
    ][$action] ?? ucfirst(str_replace(['.', '_'], ' ', $action));
}

/** The colour an action earns. Money and people are worth spotting. */
function action_tone(string $action): string
{
    return match (true) {
        str_starts_with($action, 'auction.')       => 'bg-gold/15 text-gold',
        $action === 'tournament.cancel',
        str_ends_with($action, '.reject')          => 'bg-rose-500/15 text-rose-300',
        str_ends_with($action, '.approve')         => 'bg-emerald-500/15 text-emerald-300',
        str_starts_with($action, 'player.'),
        str_starts_with($action, 'team.')          => 'bg-sky-500/10 text-sky-300',
        default                                    => 'bg-white/5 text-slate-400',
    };
}

/** A field name as a person would say it. */
function field_words(string $field): string
{
    return [
        'full_name' => 'Name', 'display_name' => 'Short name', 'short_name' => 'Short name',
        'base_price' => 'Base price', 'sold_price' => 'Price', 'sold_to' => 'Bought by',
        'auction_set' => 'Auction set', 'role' => 'Type of player', 'is_overseas' => 'Overseas',
        'is_capped' => 'Capped', 'primary_color' => 'Colour', 'home_venue' => 'Home ground',
        'purse_total' => 'Purse', 'tournament_id' => 'Tournament', 'career_matches' => 'Matches',
        'career_runs' => 'Runs', 'career_wickets' => 'Wickets', 'strike_rate' => 'Strike rate',
        'batting_style' => 'Batting', 'bowling_style' => 'Bowling', 'owner' => 'Owner',
        'registration_open' => 'Entries', 'purse_per_team' => 'Purse per team',
    ][$field] ?? ucfirst(str_replace('_', ' ', $field));
}

/**
 * A stored value as a person would read it.
 *
 * The log holds what the column holds — 0, 'bowling_all_rounder',
 * '200000.00' — because that is what makes it evidence. Turning those
 * into money, yes/no and proper role names is this screen's job, not the
 * log's.
 */
function value_words(string $field, mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    if (in_array($field, ['base_price', 'sold_price', 'purse_total', 'purse_per_team'], true)
        && is_numeric($value)
    ) {
        return rupees((float) $value);
    }

    if (in_array($field, ['is_overseas', 'is_capped'], true)) {
        return ((int) $value === 1 || $value === 'yes') ? 'yes' : 'no';
    }

    if ($field === 'registration_open') {
        return (int) $value === 1 ? 'open' : 'closed';
    }

    if ($field === 'role' && function_exists('player_kind')) {
        $kind = player_kind((string) $value);

        // player_kind() falls back for a staff role like 'scorer', which
        // also arrives under this name from account.create_staff.
        if ($kind !== '—') {
            return $kind;
        }
    }

    return str_replace('_', ' ', (string) $value);
}

page_head('Activity', '../', $links);
page_message();
?>

<h1 class="text-2xl font-extrabold tracking-tight text-white">Activity</h1>
<p class="mt-2 max-w-3xl text-sm text-slate-400">
    Every administrative change, newest first, with what each field was before it moved.
    <?php if (!$isAdmin): ?>
        Limited to <strong class="text-slate-200">the tournament you run</strong>.
    <?php endif; ?>
    Nothing here can be edited or removed.
</p>

<?php if (!$available): ?>
    <div class="mt-6 rounded-2xl border border-amber-400/30 bg-amber-500/10 p-6 text-[13px] leading-relaxed text-amber-200">
        <p class="font-black uppercase tracking-wider">Not switched on yet</p>
        <p class="mt-2">
            The <code class="rounded bg-black/30 px-1.5 py-0.5 font-mono">activity_log</code> table is not in this
            database. Run <strong>database/migrations/006_activity_log.sql</strong> once, in phpMyAdmin, and changes
            from that moment on will be recorded here.
        </p>
        <p class="mt-2 text-amber-200/80">
            Everything else keeps working meanwhile — a missing log never stops a change from being saved.
        </p>
    </div>
<?php elseif ($lines === []): ?>
    <p class="mt-6 rounded-2xl border border-white/10 bg-white/[0.03] p-8 text-center text-sm text-slate-500">
        Nothing recorded yet. The next change you make appears here.
    </p>
<?php else: ?>
    <p class="mt-4 text-[12px] text-slate-500">
        <?= count($lines) ?> most recent.
        The same lines are written to the server's error log, so they survive a database problem.
    </p>

    <div class="mt-4 space-y-2">
        <?php foreach ($lines as $line): ?>
            <?php
            $changes = [];

            if (!empty($line['changes'])) {
                $decoded = json_decode((string) $line['changes'], true);
                $changes = is_array($decoded) ? $decoded : [];
            }
            ?>
            <article class="rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide <?= action_tone((string) $line['action']) ?>">
                        <?= e(action_words((string) $line['action'])) ?>
                    </span>

                    <span class="text-[14px] font-extrabold text-white"><?= e((string) $line['subject_label']) ?></span>

                    <span class="text-[12px] text-slate-500">
                        by <strong class="font-semibold text-slate-400"><?= e((string) $line['actor_name']) ?></strong>
                    </span>

                    <time class="ml-auto shrink-0 font-mono text-[11px] text-slate-600"
                          datetime="<?= e((string) $line['at']) ?>">
                        <?= e(date('j M Y, H:i', strtotime((string) $line['at']))) ?>
                    </time>
                </div>

                <?php if ($changes !== []): ?>
                    <dl class="mt-2 flex flex-wrap gap-x-5 gap-y-1">
                        <?php foreach ($changes as $field => $move): ?>
                            <div class="flex items-baseline gap-1.5 text-[12px]">
                                <dt class="font-semibold text-slate-500"><?= e(field_words((string) $field)) ?></dt>
                                <dd class="flex items-baseline gap-1.5">
                                    <span class="text-slate-500 line-through">
                                        <?= e(value_words((string) $field, $move['from'] ?? null)) ?>
                                    </span>
                                    <span class="text-slate-600">&rarr;</span>
                                    <span class="font-bold text-slate-200">
                                        <?= e(value_words((string) $field, $move['to'] ?? null)) ?>
                                    </span>
                                </dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>

                <?php if (!empty($line['note'])): ?>
                    <p class="mt-1.5 text-[11.5px] italic text-slate-500"><?= e((string) $line['note']) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php page_foot(); ?>
