<?php

declare(strict_types=1);

namespace App\Services\X;

use App\Enums\CredentialType;
use App\Enums\MediaCategory;
use App\Exceptions\XApiException;
use App\Models\PostMedia;
use App\Models\XAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Storage;

/**
 * Chunked media upload (INIT / APPEND / FINALIZE / STATUS).
 *
 * A media_id belongs to the app that uploaded it, so the id is cached per app
 * on the PostMedia row. For a 10k-account blast on a pool of ten apps that
 * turns 10k uploads into ten -- which is the difference between a campaign that
 * ships in minutes and one that spends its whole budget on media.
 */
final class XMediaUploader
{
    private const ENDPOINT = 'https://api.x.com/2/media/upload';

    /** 4 MB is comfortably under the 5 MB per-chunk ceiling. */
    private const CHUNK_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly XApiClientFactory $clients,
        private readonly XCardMediaPreparer $cardMedia,
    ) {}

    /**
     * Upload once per account, then reuse. Returns the X media_id.
     */
    public function ensureUploaded(PostMedia $media, XAccount $account): string
    {
        return $this->cached($media, $account, 'media', fn () => $this->upload($media, $account));
    }

    /**
     * Upload for use in an Ads card, returning the media_key.
     *
     * A card will not take a `tweet_video`, and the category is fixed at INIT,
     * so the same file has to go up a second time under `amplify_video`. Cached
     * separately for that reason: the two ids are not interchangeable, and
     * reusing one where the other belongs fails with an unhelpful error.
     *
     * Cards want the media_key ("13_1234"), not the media_id ("1234").
     */
    public function ensureAmplifyUploaded(PostMedia $media, XAccount $account): string
    {
        // Old amplify uploads may contain a ratio that cards reject. Keep a
        // separate namespace so retrying a failed card uploads the prepared
        // video instead of reusing that rejected key.
        return $this->cached($media, $account, 'amplify:card-v1', fn () => $this->upload(
            $this->cardMedia->prepare($media), $account, MediaCategory::AmplifyVideo, wantKey: true,
        ));
    }

    /**
     * Look up this account's id for the file, uploading it if there is not one.
     *
     * Keyed by account, not by app.
     *
     * X scopes an uploaded file to the user who uploaded it, so a second
     * account on the same app cannot attach the first one's media -- it is
     * refused as "Invalid media: 13_...", which names an id that plainly
     * exists and says nothing about whose it is. Sharing one app across a pool
     * of accounts made that collision the normal case rather than an edge one.
     *
     * The cost is real: one video published from a thousand accounts is a
     * thousand uploads. Cards are the exception, and the reason they matter at
     * scale -- a card owns its media, so one card publishes from any number of
     * accounts without re-uploading anything.
     *
     * @param  callable(): string  $upload
     */
    private function cached(PostMedia $media, XAccount $account, string $kind, callable $upload): string
    {
        if ($account->x_app_id === null) {
            throw new XApiException('Account '.$account->handle().' is not bound to an X app.');
        }

        $key = $kind.':account:'.$account->id;

        if ($cached = data_get($media->x_media_ids, $key)) {
            return $cached;
        }

        $id = $upload();

        $map = $media->x_media_ids ?? [];
        $map[$key] = $id;
        $media->forceFill(['x_media_ids' => $map])->save();

        return $id;
    }

    public function upload(
        PostMedia $media,
        XAccount $account,
        ?MediaCategory $category = null,
        bool $wantKey = false,
    ): string {
        $this->assertCanUploadMedia($account);

        $client = $this->clients->for($account);
        $disk = Storage::disk($media->disk);

        if (! $disk->exists($media->path)) {
            throw new XApiException("Media file [{$media->path}] is missing from disk [{$media->disk}].");
        }

        [$mediaId, $mediaKey] = $this->init($client, $media, $category ?? $media->category);

        $stream = $disk->readStream($media->path);
        $segment = 0;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $this->append($client, $mediaId, $segment++, $chunk);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->finalize($client, $mediaId, $category ?? $media->category);

        if ($media->alt_text) {
            $this->setAltText($client, $mediaId, $media->alt_text);
        }

        // Cards address media by key ("13_1234"); a tweet addresses it by id.
        return $wantKey ? $mediaKey : $mediaId;
    }

    /**
     * Fail before the upload rather than after, when the token cannot do it.
     *
     * POST /2/media/upload is the only endpoint that needs `media.write`, and
     * X refuses it with a bare `{"title":"Forbidden"}` that names neither the
     * scope nor the endpoint. A token that publishes text all day returns that
     * the first time it is handed a file, which sends you looking at billing,
     * app permissions and the callback URL before the scope.
     */
    private function assertCanUploadMedia(XAccount $account): void
    {
        if ($account->credential_type !== CredentialType::OAuth2) {
            return;
        }

        $scopes = (array) $account->scopes;

        // An empty list means the account predates scope recording, not that it
        // has none -- let X be the judge in that case.
        if ($scopes !== [] && ! in_array('media.write', $scopes, true)) {
            throw new XApiException(
                'Tài khoản '.$account->handle().' chưa có scope [media.write] nên không upload được media. '
                .'Vào X Apps, bấm "Kết nối tài khoản" để cấp lại quyền.',
                errorCode: 'missing_media_scope',
            );
        }
    }

    /** @return array{0: string, 1: string} media_id and media_key */
    private function init(XApiClient $client, PostMedia $media, MediaCategory $category): array
    {
        /*
         * v2 has a dedicated endpoint per step. The v1.1 style of posting
         * `command=INIT` to /2/media/upload is not supported there: that URL is
         * the single-shot upload, so it reads the request as a plain upload
         * missing its file and answers "Missing media field in JSON" -- an
         * error about a field INIT does not have, on a step that sends no bytes.
         */
        $response = $client->send('POST', self::ENDPOINT.'/initialize', json: [
            'media_type' => $media->mime,
            'total_bytes' => $media->size,
            'media_category' => $category->value,
        ]);

        $id = data_get($response->json(), 'data.id') ?? data_get($response->json(), 'media_id_string');

        if (blank($id)) {
            throw new XApiException('INIT did not return a media id.', $response->status(), 'invalid_media', $response->json() ?? []);
        }

        return [(string) $id, (string) (data_get($response->json(), 'data.media_key') ?: $id)];
    }

    private function append(XApiClient $client, string $mediaId, int $segmentIndex, string $chunk): void
    {
        // The one call that carries bytes, so the one call that is multipart.
        // The media id is in the path, not the body.
        $client->send('POST', self::ENDPOINT."/{$mediaId}/append", multipart: [
            ['name' => 'segment_index', 'contents' => (string) $segmentIndex],
            ['name' => 'media', 'contents' => $chunk, 'filename' => 'chunk'],
        ], configure: fn (PendingRequest $request) => $request->timeout(
            max((int) config('xpost.http.timeout', 30), (int) config('xpost.media.upload_timeout', 120)),
        ));
    }

    private function finalize(XApiClient $client, string $mediaId, MediaCategory $category): void
    {
        $response = $client->send('POST', self::ENDPOINT."/{$mediaId}/finalize");

        $state = data_get($response->json(), 'data.processing_info.state');

        // Images finalise synchronously; video and GIF go through transcoding.
        if ($state !== null && $state !== 'succeeded') {
            $this->awaitProcessing($client, $mediaId);
        }
    }

    private function awaitProcessing(XApiClient $client, string $mediaId, int $maxWaitSeconds = 180): void
    {
        $waited = 0;

        while ($waited < $maxWaitSeconds) {
            // Status is a query on the collection, not a path segment: the
            // /{id} and /{id}/status forms both 404.
            $response = $client->send('GET', self::ENDPOINT, query: ['media_id' => $mediaId]);

            $info = data_get($response->json(), 'data.processing_info', []);
            $state = $info['state'] ?? 'succeeded';

            if ($state === 'succeeded') {
                return;
            }

            if ($state === 'failed') {
                throw new XApiException(
                    'Media transcoding failed: '.data_get($info, 'error.message', 'unknown'),
                    422,
                    'invalid_media',
                    $info,
                );
            }

            $wait = max(1, (int) ($info['check_after_secs'] ?? 5));
            sleep($wait);
            $waited += $wait;
        }

        throw new XApiException("Media {$mediaId} did not finish processing within {$maxWaitSeconds}s.", 408, 'media_timeout');
    }

    private function setAltText(XApiClient $client, string $mediaId, string $altText): void
    {
        // Alt text is best-effort: losing it must never fail the publish.
        try {
            $client->send('POST', 'https://api.x.com/2/media/metadata', json: [
                'id' => $mediaId,
                'metadata' => ['alt_text' => ['text' => mb_substr($altText, 0, 1000)]],
            ]);
        } catch (XApiException) {
            // swallowed on purpose
        }
    }
}
