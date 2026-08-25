<?php

declare(strict_types=1);

namespace App\Exceptions;

use Carbon\CarbonInterface;

class XRateLimitException extends XApiException
{
    public function __construct(
        string $message,
        public readonly ?CarbonInterface $resetAt = null,
        array $payload = [],
    ) {
        parent::__construct($message, 429, 'rate_limited', $payload);
    }

    public function retryAfterSeconds(int $fallback = 60): int
    {
        if ($this->resetAt === null) {
            return $fallback;
        }

        return max(1, (int) now()->diffInSeconds($this->resetAt, false));
    }
}
