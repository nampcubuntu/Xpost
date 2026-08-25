<?php

declare(strict_types=1);

namespace App\Services\X\DTO;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Parsed x-rate-limit-* response headers. Feeding these back into the limiter
 * keeps our local budget in step with what X actually thinks it owes us.
 */
final class RateLimitSnapshot
{
    public function __construct(
        public readonly ?int $limit,
        public readonly ?int $remaining,
        public readonly ?CarbonInterface $resetAt,
    ) {}

    /** @param array<string, array<int, string>|string> $headers */
    public static function fromHeaders(array $headers): self
    {
        $get = static function (string $name) use ($headers): ?string {
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, $name) === 0) {
                    return is_array($value) ? ($value[0] ?? null) : $value;
                }
            }

            return null;
        };

        $reset = $get('x-rate-limit-reset');

        return new self(
            limit: ($v = $get('x-rate-limit-limit')) !== null ? (int) $v : null,
            remaining: ($v = $get('x-rate-limit-remaining')) !== null ? (int) $v : null,
            resetAt: $reset !== null ? CarbonImmutable::createFromTimestampUTC((int) $reset) : null,
        );
    }

    public function isExhausted(): bool
    {
        return $this->remaining !== null && $this->remaining <= 0;
    }
}
