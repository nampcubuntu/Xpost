<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class XApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly string $errorCode = 'x_api_error',
        public readonly array $payload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function fromResponse(int $status, array $body): self
    {
        // X returns two shapes: {title, detail, type} and {errors:[{message, code}]}
        $detail = $body['detail']
            ?? data_get($body, 'errors.0.message')
            ?? $body['title']
            ?? 'Unknown X API error';

        $code = (string) (data_get($body, 'errors.0.code') ?? $body['type'] ?? $status);

        return new self($detail, $status, self::normaliseCode($status, $code), $body);
    }

    /** Stable, greppable codes so the UI and retry policy do not parse prose. */
    private static function normaliseCode(int $status, string $raw): string
    {
        return match (true) {
            $status === 401 => 'unauthorized',
            $status === 403 && str_contains($raw, '187') => 'duplicate_content',
            $status === 403 => 'forbidden',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'x_server_error',
            str_contains($raw, '187') => 'duplicate_content',
            str_contains($raw, '186') => 'text_too_long',
            str_contains($raw, '324') => 'invalid_media',
            default => 'http_'.$status,
        };
    }

    /** Whether re-running the same job could plausibly succeed. */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, ['rate_limited', 'x_server_error'], true)
            || ($this->status !== null && $this->status >= 500);
    }

    /** A failure that will never succeed until a human fixes the account. */
    public function requiresReauth(): bool
    {
        return $this->errorCode === 'unauthorized';
    }
}
