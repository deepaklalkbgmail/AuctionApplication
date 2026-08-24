<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A rejected auction action.
 *
 * Carries a machine-readable code so the UI can react (disable a button,
 * highlight the purse) without string-matching the message, plus the HTTP
 * status the controller should return.
 *
 * These are expected outcomes, not bugs: a bid arriving one millisecond after
 * another team's is a normal event, and the loser gets BID_TOO_LOW.
 */
final class AuctionException extends RuntimeException
{
    public const LOT_NOT_FOUND     = 'LOT_NOT_FOUND';
    public const LOT_NOT_LIVE      = 'LOT_NOT_LIVE';
    public const LOT_EXPIRED       = 'LOT_EXPIRED';
    public const LOT_ALREADY_OPEN  = 'LOT_ALREADY_OPEN';
    public const TEAM_NOT_FOUND    = 'TEAM_NOT_FOUND';
    public const WRONG_TOURNAMENT  = 'WRONG_TOURNAMENT';
    public const ALREADY_LEADING   = 'ALREADY_LEADING';
    public const BID_TOO_LOW       = 'BID_TOO_LOW';
    public const BID_NOT_ALIGNED   = 'BID_NOT_ALIGNED';
    public const INSUFFICIENT_PURSE = 'INSUFFICIENT_PURSE';
    public const SQUAD_FULL        = 'SQUAD_FULL';
    public const OVERSEAS_LIMIT    = 'OVERSEAS_LIMIT';
    public const NO_BIDS           = 'NO_BIDS';
    public const NOTHING_QUEUED    = 'NOTHING_QUEUED';
    public const ALREADY_SOLD      = 'ALREADY_SOLD';
    public const NOT_SOLD          = 'NOT_SOLD';

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
