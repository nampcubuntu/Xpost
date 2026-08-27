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

    public static function fromResponseBody(int $status, array $body, string $rawBody = ''): self
    {
        $exception = self::fromResponse($status, $body);

        if (! self::looksUnfunded($status, $rawBody !== '' ? $rawBody : json_encode($body))) {
            return $exception;
        }

        return new self($exception->getMessage(), $status, 'out_of_credit', $body);
    }

    /**
     * Whether X is refusing because the account is out of credit.
     *
     * X posting is pay-per-use with no free tier, so a spent balance stops
     * every account at once. It arrives as an ordinary 4xx, which the retry
     * policy would otherwise treat as a per-post problem and burn ten thousand
     * targets on -- one at a time, at three in the morning.
     *
     * Matched on prose because X publishes no code for it. False negatives
     * cost the old behaviour; false positives only pause a queue a human then
     * resumes, so the loose match is the safer side to err on.
     */
    private static function looksUnfunded(int $status, string $body): bool
    {
        if ($status === 402) {
            return true;
        }

        if (! in_array($status, [401, 403, 429], true)) {
            return false;
        }

        foreach (['credit', 'insufficient fund', 'payment', 'billing', 'quota exceeded', 'usage cap'] as $needle) {
            if (str_contains(strtolower($body), $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Stable, greppable codes so the UI and retry policy do not parse prose. */
    private static function normaliseCode(int $status, string $raw): string
    {
        return match (true) {
            $status === 402 => 'out_of_credit',
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

    /**
     * Nothing is wrong with the post; there is no money to send it.
     *
     * Deliberately not "retryable" and not a failure: retrying spends nothing
     * but achieves nothing, and failing throws away a campaign over a balance
     * a top-up fixes in a minute.
     */
    public function isOutOfCredit(): bool
    {
        return $this->errorCode === 'out_of_credit';
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
