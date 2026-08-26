<?php

declare(strict_types=1);

namespace App\Services\X;

use App\Enums\CredentialType;
use App\Enums\MediaCategory;
use App\Exceptions\XApiException;
use App\Models\PostMedia;
use App\Models\XAccount;
use Illuminate\Support\Str;

/**
 * Builds the `video_website` card that gives a post its "From <domain>" row.
 *
 * Two things about this are easy to get wrong and cost a lot of time:
 *
 *  - The media must be uploaded as `amplify_video`. The category is fixed at
 *    INIT and cannot be changed later, so a video already uploaded as
 *    `tweet_video` is useless here and has to go up again.
 *  - The Ads API returns `card_uri` as `card://1234`, but POST /2/tweets wants
 *    the bare id. Passing the prefixed form is rejected as an invalid card URI;
 *    passing a URL-shaped one is accepted and then silently ignored, which is
 *    worse -- the post publishes with no card at all.
 */
final class XCardService
{
    public function __construct(private readonly XMediaUploader $uploader) {}

    /**
     * @return string the bare card id, ready for POST /2/tweets `card_uri`
     */
    public function videoWebsiteCard(
        XAccount $account,
        PostMedia $media,
        string $websiteUrl,
        ?string $title = null,
    ): string {
        $mediaKey = $this->uploader->ensureAmplifyUploaded($media, $account);
        $ads = new XAdsApiClient($account);

        $response = $ads->postJson("accounts/{$ads->accountId()}/cards", [
            // Only ever seen in the ads UI, but required.
            'name' => Str::limit('Xpost '.$account->username.' '.now()->format('Y-m-d H:i:s'), 80, ''),
            'components' => [
                ['type' => 'MEDIA', 'media_key' => $mediaKey],
                [
                    'type' => 'DETAILS',
                    // Free text. X renders it as the badge over the video and
                    // derives the "From <domain>" line from the URL itself.
                    'title' => $title ?: parse_url($websiteUrl, PHP_URL_HOST) ?: 'Xem thêm',
                    'destination' => ['type' => 'WEBSITE', 'url' => $websiteUrl],
                ],
            ],
        ]);

        $uri = (string) data_get($response->json(), 'data.card_uri');

        if ($uri === '') {
            throw new XApiException(
                'Ads API không trả card_uri.',
                $response->status(),
                'invalid_card',
                $response->json() ?? [],
            );
        }

        return Str::after($uri, 'card://');
    }

    /** Whether this account and post can take the card route at all. */
    public function isAvailableFor(XAccount $account): bool
    {
        return filled($account->ads_account_id)
            && $account->credential_type === CredentialType::OAuth1
            && filled($account->token_secret)
            && $account->app?->supportsOAuth1();
    }

    /** The category a card's media has to carry. */
    public function requiredCategory(): MediaCategory
    {
        return MediaCategory::AmplifyVideo;
    }
}
