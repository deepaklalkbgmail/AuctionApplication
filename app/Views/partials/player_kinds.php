<?php

declare(strict_types=1);

/**
 * How a kind of cricketer is written on screen.
 *
 * The list itself lives on AccountService, next to the validation that
 * guards it. This is only the view's way in, so that the shell, the
 * standalone auction board and the cards all print the same words —
 * and so that nothing falls back to str_replace('_', ' '), which turns
 * batting_all_rounder into "batting all rounder".
 */

use App\Services\AccountService;

if (!function_exists('player_kind')) {

    /** batting_all_rounder -> "Batting all-rounder" */
    function player_kind(?string $key): string
    {
        $key = (string) $key;

        return AccountService::PLAYER_KINDS[$key]
            ?? ($key === '' ? '—' : ucfirst(str_replace('_', ' ', $key)));
    }

    /**
     * The kinds, in order, for a select.
     *
     * @return array<string,string>
     */
    function player_kinds(bool $blankFirst = true): array
    {
        return $blankFirst
            ? ['' => 'Choose one…'] + AccountService::PLAYER_KINDS
            : AccountService::PLAYER_KINDS;
    }
}
