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
     * @param  string|null  $websiteUrl  destination for the post
     * @param  string|null  $title  headline shown under attached media
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
        public readonly ?string $websiteUrl = null,
        public readonly ?string $title = null,
        /** `visit_site` or `watch_now` — see config('xpost.publishing.media_cta'). */
        public readonly string $mediaCta = 'visit_site',
        /**
         * Bare card id from XCardService — never the `card://…` form the Ads
         * API returns, which POST /2/tweets rejects as invalid.
         */
        public readonly ?string $cardUri = null,
    ) {}

    public function toArray(): array
    {
        $body = [];
        $text = trim($this->text);

        /*
         * A destination link reaches X two different ways, and picking the
         * wrong one silently loses it.
         *
         * With media attached, the URL belongs in media.call_to_actions --
         * that is the dedicated field, and it renders as the tappable
         * "From <domain>" label under the video that X Ads Manager produces.
         * Putting it in the text instead would leave a raw t.co link in the
         * body and no button at all, because attached media suppresses the
         * link preview card.
         *
         * With no media there is no call_to_actions to hang it on, so the URL
         * goes in the text and X builds a card from the destination's meta
         * tags. ComposerController::measure already counts it that way.
         */
        $url = trim((string) $this->websiteUrl);

        /*
         * A card already carries the video and the destination, so the media
         * and the URL must not be sent alongside it. X refuses a post that has
         * both `media` and `card_uri`, and a URL left in the text would sit
         * above the card as a stray t.co link.
         */
        $hasCard = filled($this->cardUri);
        $hasMedia = ! $hasCard && $this->mediaIds !== [];

        if ($url !== '' && ! $hasMedia && ! $hasCard && ! str_contains($text, $url)) {
            $text = trim($text.' '.$url);
        }

        if ($text !== '') {
            $body['text'] = $text;
        }

        if ($hasCard) {
            $body['card_uri'] = $this->cardUri;
        }

        if ($hasMedia) {
            $media = ['media_ids' => array_values($this->mediaIds)];

            if ($this->taggedUserIds !== []) {
                $media['tagged_user_ids'] = $this->taggedUserIds;
            }

            if ($url !== '') {
                $cta = in_array($this->mediaCta, ['visit_site', 'watch_now'], true)
                    ? $this->mediaCta
                    : 'visit_site';

                $media['call_to_actions'] = [$cta => ['url' => $url]];
            }

            // Only meaningful alongside a call to action -- X shows it as the
            // headline on the CTA row, and ignores it otherwise.
            if ($url !== '' && filled($this->title)) {
                $media['title'] = $this->title;
            }

            $body['media'] = $media;
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
        return hash('xxh128', $this->text.'|'.implode(',', $this->mediaIds).'|'.$this->websiteUrl);
    }
}
