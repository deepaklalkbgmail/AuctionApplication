<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use Database;
use Throwable;

/**
 * Who changed what, and what it was before.
 *
 * Two destinations, on purpose:
 *
 *   activity_log     queryable, shown at Administration -> Activity
 *   the PHP error log  a one-line summary, so the trail survives the
 *                      database being the thing that went wrong
 *
 * ---------------------------------------------------------------------
 * IT MUST NEVER BREAK THE THING IT IS LOGGING
 *
 * Every write here is wrapped. A log that can abort a sale is worse than
 * no log at all — the organiser would rather have an unrecorded sale
 * than a refused one. If the insert fails, the failure itself goes to
 * the error log and the caller carries on none the wiser.
 *
 * That matters more than it looks: record() is called from inside
 * Database::transaction() in several places, and an exception escaping
 * it would roll back the real work.
 *
 * ---------------------------------------------------------------------
 * WHAT GOES IN `changes`
 *
 * Only the fields that actually moved, as {field: {from, to}}. A form
 * posts every field every time; recording all of them would bury the one
 * that mattered. diff() does the filtering.
 */
final class ActivityLog
{
    /** Actions worth showing prominently — the ones that move money or people. */
    public const NOTABLE = [
        'auction.sold', 'auction.unsold', 'auction.undo',
        'player.update', 'team.update', 'tournament.cancel',
        'account.approve', 'account.reject',
    ];

    /**
     * Record one change.
     *
     * @param string               $action    'player.update', 'auction.sold'
     * @param string               $subject   'player', 'team', 'tournament', 'account'
     * @param array<string,mixed>  $changes   from diff(), or a plain map
     */
    public static function record(
        string $action,
        string $subject,
        ?int $subjectId,
        string $label,
        array $changes = [],
        ?int $tournamentId = null,
        ?string $note = null,
    ): void {
        $actor = Auth::user();

        $encoded = $changes === []
            ? null
            : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        // The error log first: it is the destination that still works when
        // the database is the problem.
        error_log(sprintf(
            '[activity] %s %s#%s "%s" by %s%s',
            $action,
            $subject,
            $subjectId === null ? '?' : (string) $subjectId,
            $label,
            $actor['name'] ?? 'system',
            $encoded !== null ? ' ' . $encoded : ''
        ));

        try {
            Database::exec(
                'INSERT INTO activity_log
                    (actor_user_id, actor_name, actor_role, action,
                     subject_type, subject_id, subject_label,
                     tournament_id, changes, note, ip)
                 VALUES
                    (:actor, :actorName, :actorRole, :action,
                     :subject, :subjectId, :label,
                     :tournament, :changes, :note, :ip)',
                [
                    ':actor'      => isset($actor['id']) ? (int) $actor['id'] : null,
                    ':actorName'  => mb_substr((string) ($actor['name'] ?? 'system'), 0, 120),
                    ':actorRole'  => mb_substr((string) ($actor['role'] ?? 'system'), 0, 40),
                    ':action'     => mb_substr($action, 0, 40),
                    ':subject'    => mb_substr($subject, 0, 30),
                    ':subjectId'  => $subjectId,
                    ':label'      => mb_substr($label, 0, 160),
                    ':tournament' => $tournamentId,
                    ':changes'    => $encoded,
                    ':note'       => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
                    ':ip'         => self::ip(),
                ]
            );
        } catch (Throwable $e) {
            // Including "table doesn't exist", which is what an installation
            // that has not run migration 006 yet will say. The application
            // keeps working; only the trail is missing.
            error_log('[activity] could not be recorded: ' . $e->getMessage());
        }
    }

    /**
     * The fields that actually moved.
     *
     * Compares loosely on purpose: a form posts "5000" where the database
     * holds "5000.00", and calling that a change would fill the log with
     * lines that record nothing.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<int,string>   $fields  which keys to consider
     * @return array<string,array{from:mixed,to:mixed}>
     */
    public static function diff(array $before, array $after, array $fields): array
    {
        $changes = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $after)) {
                continue;
            }

            $from = $before[$field] ?? null;
            $to   = $after[$field]  ?? null;

            if (self::same($from, $to)) {
                continue;
            }

            $changes[$field] = ['from' => self::readable($from), 'to' => self::readable($to)];
        }

        return $changes;
    }

    /**
     * The log, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recent(?int $tournamentId = null, int $limit = 200): array
    {
        try {
            return Database::all(
                'SELECT * FROM activity_log
                  WHERE (:all = 1 OR tournament_id = :t)
               ORDER BY at DESC, id DESC
                  LIMIT ' . max(1, min(1000, $limit)),
                [':all' => $tournamentId === null ? 1 : 0, ':t' => $tournamentId]
            );
        } catch (Throwable $e) {
            error_log('[activity] could not be read: ' . $e->getMessage());

            return [];
        }
    }

    /** Everything ever done to one thing. */
    public static function forSubject(string $subject, int $id, int $limit = 50): array
    {
        try {
            return Database::all(
                'SELECT * FROM activity_log
                  WHERE subject_type = :s AND subject_id = :i
               ORDER BY at DESC, id DESC
                  LIMIT ' . max(1, min(500, $limit)),
                [':s' => $subject, ':i' => $id]
            );
        } catch (Throwable) {
            return [];
        }
    }

    /** Has migration 006 been run? Used to explain an empty screen. */
    public static function isAvailable(): bool
    {
        try {
            return (int) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
                [':t' => 'activity_log']
            ) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------

    /** "5000" and "5000.00" are the same number and not a change. */
    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.000001;
        }

        return (string) ($a ?? '') === (string) ($b ?? '');
    }

    private static function readable(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return is_bool($value) ? ($value ? 'yes' : 'no') : $value;
        }

        return (string) json_encode($value);
    }

    /**
     * The caller's address, taking a proxy header only when one is there.
     * Trusted no further than the log — it is written down, never acted on.
     */
    private static function ip(): ?string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        if (is_string($forwarded) && $forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);

            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($remote) && $remote !== '' ? mb_substr($remote, 0, 45) : null;
    }
}
