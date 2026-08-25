<?php

declare(strict_types=1);

namespace App\Services\X\DTO;

use App\Enums\ReplySettings;

/**
 * The body of POST /2/tweets, built once and reused across retries.
 */
final class TweetPayload
{
    /**
     * @param  list<string>  $mediaIds
     * @param  list<string>  $taggedUserIds
     */
    public function __construct(
        public readonly string $text,
        public readonly array $mediaIds = [],
        public readonly ?ReplySettings $replySettings = null,
        public readonly ?string $replyToTweetId = null,
        public readonly ?string $quoteTweetId = null,
        public readonly array $taggedUserIds = [],
        public readonly bool $nullcast = false,
        public readonly ?string $placeId = null,
    ) {}

    public function toArray(): array
    {
        $body = [];

        if ($this->text !== '') {
            $body['text'] = $this->text;
        }

        if ($this->mediaIds !== []) {
            $body['media'] = array_filter([
                'media_ids' => array_values($this->mediaIds),
                'tagged_user_ids' => $this->taggedUserIds ?: null,
            ]);
        }

        if ($this->replySettings?->toApiValue() !== null) {
            $body['reply_settings'] = $this->replySettings->toApiValue();
        }

        if ($this->replyToTweetId !== null) {
            $body['reply'] = ['in_reply_to_tweet_id' => $this->replyToTweetId];
        }

        if ($this->quoteTweetId !== null) {
            $body['quote_tweet_id'] = $this->quoteTweetId;
        }

        if ($this->placeId !== null) {
            $body['geo'] = ['place_id' => $this->placeId];
        }

        // Promoted-only: the post exists and is live, but is kept off the
        // author timeline. This is the "Promoted only" switch in the composer.
        if ($this->nullcast) {
            $body['nullcast'] = true;
        }

        return $body;
    }

    /** Stable hash used to detect duplicate content before X rejects it with code 187. */
    public function fingerprint(): string
    {
        return hash('xxh128', $this->text.'|'.implode(',', $this->mediaIds));
    }
}
