<?php

declare(strict_types=1);

namespace App\Services\Publishing;

/**
 * The outcomes of publishing a target. Modelled as a value object so
 * the job can branch on the result without re-reading the row or catching
 * exceptions it has no way to handle.
 */
final class PublishOutcome
{
    private function __construct(
        public readonly string $state,
        public readonly ?string $tweetId = null,
        public readonly ?int $retryInSeconds = null,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,
    ) {}

    public static function published(string $tweetId): self
    {
        return new self('published', tweetId: $tweetId);
    }

    public static function deferred(int $seconds, string $reason): self
    {
        return new self('deferred', retryInSeconds: $seconds, reason: $reason);
    }

    public static function failed(string $reason, string $message): self
    {
        return new self('failed', reason: $reason, message: $message);
    }

    public static function cancelled(): self
    {
        return new self('cancelled');
    }

    public function isCancelled(): bool
    {
        return $this->state === 'cancelled';
    }

    public function isPublished(): bool
    {
        return $this->state === 'published';
    }

    public function isDeferred(): bool
    {
        return $this->state === 'deferred';
    }

    public function isFailed(): bool
    {
        return $this->state === 'failed';
    }

    public function toArray(): array
    {
        return array_filter([
            'state' => $this->state,
            'tweet_id' => $this->tweetId,
            'retry_in' => $this->retryInSeconds,
            'reason' => $this->reason,
            'message' => $this->message,
        ], static fn ($v) => $v !== null);
    }
}
