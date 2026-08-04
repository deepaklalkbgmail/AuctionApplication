<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A rejected account or tournament-registration action.
 *
 * Same contract as the auction and scoring exceptions: a machine-readable
 * code, a message safe to show the person, and the HTTP status.
 */
final class AccountException extends RuntimeException
{
    public const VALIDATION        = 'VALIDATION';
    public const EMAIL_TAKEN       = 'EMAIL_TAKEN';
    public const USERNAME_TAKEN    = 'USERNAME_TAKEN';
    public const NOT_FOUND         = 'NOT_FOUND';
    public const NOT_APPROVED      = 'NOT_APPROVED';
    public const ALREADY_DECIDED   = 'ALREADY_DECIDED';
    public const BAD_SECRET_CODE   = 'BAD_SECRET_CODE';
    public const REGISTRATION_SHUT = 'REGISTRATION_SHUT';
    public const ALREADY_APPLIED   = 'ALREADY_APPLIED';
    public const DEADLINE_PASSED   = 'DEADLINE_PASSED';
    public const NOT_YOUR_TEAM     = 'NOT_YOUR_TEAM';
    public const NAME_TAKEN        = 'NAME_TAKEN';
    public const WEAK_PASSWORD     = 'WEAK_PASSWORD';
    public const WRONG_PASSWORD    = 'WRONG_PASSWORD';
    public const UPLOAD_FAILED     = 'UPLOAD_FAILED';

    /** @param array<string,mixed> $context */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $context = [],
        private readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string { return $this->errorCode; }
    public function status(): int       { return $this->status; }

    /** @return array<string,mixed> */
    public function context(): array    { return $this->context; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['ok' => false, 'error' => $this->errorCode,
                'message' => $this->getMessage(), 'context' => $this->context];
    }
}
