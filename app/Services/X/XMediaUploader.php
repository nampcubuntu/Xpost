<?php

declare(strict_types=1);

namespace App\Services\X;

use App\Enums\MediaCategory;
use App\Exceptions\XApiException;
use App\Models\PostMedia;
use App\Models\XAccount;
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

    public function __construct(private readonly XApiClientFactory $clients) {}

    /**
     * Upload once per app, then reuse. Returns the X media_id.
     */
    public function ensureUploaded(PostMedia $media, XAccount $account): string
    {
        $appId = $account->x_app_id;

        if ($appId === null) {
            throw new XApiException('Account '.$account->handle().' is not bound to an X app.');
        }

        if ($cached = $media->mediaIdFor($appId)) {
            return $cached;
        }

        $mediaId = $this->upload($media, $account);
        $media->rememberMediaId($appId, $mediaId);

        return $mediaId;
    }

    public function upload(PostMedia $media, XAccount $account): string
    {
        $client = $this->clients->for($account);
        $disk = Storage::disk($media->disk);

        if (! $disk->exists($media->path)) {
            throw new XApiException("Media file [{$media->path}] is missing from disk [{$media->disk}].");
        }

        $mediaId = $this->init($client, $media);

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

        $this->finalize($client, $mediaId, $media->category);

        if ($media->alt_text) {
            $this->setAltText($client, $mediaId, $media->alt_text);
        }

        return $mediaId;
    }

    private function init(XApiClient $client, PostMedia $media): string
    {
        $response = $client->send('POST', self::ENDPOINT, json: array_filter([
            'command' => 'INIT',
            'total_bytes' => $media->size,
            'media_type' => $media->mime,
            'media_category' => $media->category->value,
            'shared' => true,
        ]));

        $id = data_get($response->json(), 'data.id') ?? data_get($response->json(), 'media_id_string');

        if (blank($id)) {
            throw new XApiException('INIT did not return a media id.', $response->status(), 'invalid_media', $response->json() ?? []);
        }

        return (string) $id;
    }

    private function append(XApiClient $client, string $mediaId, int $segmentIndex, string $chunk): void
    {
        $client->send('POST', self::ENDPOINT, configure: fn ($request) => $request
            ->asMultipart()
            ->attach('media', $chunk, 'chunk')
            ->withOptions([
                'multipart' => [
                    ['name' => 'command', 'contents' => 'APPEND'],
                    ['name' => 'media_id', 'contents' => $mediaId],
                    ['name' => 'segment_index', 'contents' => (string) $segmentIndex],
                    ['name' => 'media', 'contents' => $chunk, 'filename' => 'chunk'],
                ],
            ]));
    }

    private function finalize(XApiClient $client, string $mediaId, MediaCategory $category): void
    {
        $response = $client->send('POST', self::ENDPOINT, json: [
            'command' => 'FINALIZE',
            'media_id' => $mediaId,
        ]);

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
            $response = $client->send('GET', self::ENDPOINT, query: [
                'command' => 'STATUS',
                'media_id' => $mediaId,
            ]);

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
