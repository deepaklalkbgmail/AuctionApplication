<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A rejected scoring action.
 *
 * Same contract as AuctionException: a machine-readable code the pad can act
 * on, plus the HTTP status. NEEDS_OPENING / NEEDS_BATTER / NEEDS_BOWLER are
 * not failures so much as the server telling the client which gap in the
 * middle it has to fill before the next ball can be recorded.
 */
final class ScoringException extends RuntimeException
{
    public const INNINGS_NOT_FOUND = 'INNINGS_NOT_FOUND';
    public const INNINGS_CLOSED    = 'INNINGS_CLOSED';
    public const MATCH_NOT_LIVE    = 'MATCH_NOT_LIVE';
    public const NEEDS_OPENING     = 'NEEDS_OPENING';
    public const NEEDS_BATTER      = 'NEEDS_BATTER';
    public const NEEDS_BOWLER      = 'NEEDS_BOWLER';
    public const NOT_IN_SQUAD      = 'NOT_IN_SQUAD';
    public const ALREADY_OUT       = 'ALREADY_OUT';
    public const SAME_BATTER       = 'SAME_BATTER';
    public const CONSECUTIVE_OVERS = 'CONSECUTIVE_OVERS';
    public const BAD_BALL          = 'BAD_BALL';
    public const NOTHING_TO_UNDO   = 'NOTHING_TO_UNDO';

    /** @param array<string,mixed> $context */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $context = [],
        private readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        return $this->context;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'ok'      => false,
            'error'   => $this->errorCode,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }
}
