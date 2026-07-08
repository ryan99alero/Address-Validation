<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Pace API call returned an error response. Carries the HTTP status so callers can classify:
 * 4xx = a validation/permanent error (never retry — same payload fails identically), 5xx = a
 * transient server error (safe to retry after verifying the record didn't already apply).
 */
class PaceRequestException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $body)
    {
        parent::__construct("Pace API {$status}: ".mb_substr($body, 0, 500));
    }

    /** A 4xx client/validation error — retrying the identical request is pointless. */
    public function isPermanent(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }
}
