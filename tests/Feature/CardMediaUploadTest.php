<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\MediaCategory;
use App\Exceptions\XApiException;
use App\Models\PostMedia;
use App\Models\Team;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\X\XMediaUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CardMediaUploadTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL = 'original video bytes with aspect ratio 135:64';

    private const PADDED = 'padded MP4 bytes containing the full original frame';

    private Team $team;

    private XAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team] = $this->tenant();

        Storage::fake('local');
        Http::preventStrayRequests();
        Process::fake();
        Process::preventStrayProcesses();

        $app = XApp::factory()->create([
            'team_id' => $this->team->id,
            'consumer_key' => 'test-consumer-key',
            'consumer_secret' => 'test-consumer-secret',
        ]);

        $this->account = XAccount::factory()->create([
            'team_id' => $this->team->id,
            'x_app_id' => $app->id,
            'credential_type' => CredentialType::OAuth1,
            'access_token' => 'test-token',
            'token_secret' => 'test-token-secret',
        ]);
    }

    #[Test]
    public function an_old_rejected_upload_is_replaced_with_padded_bytes_and_reused_on_retry(): void
    {
        $legacyKey = 'amplify:account:'.$this->account->id;
        $originalKey = 'media:account:'.$this->account->id;
        $media = $this->video([
            $legacyKey => '13_rejected',
            $originalKey => 'original-tweet-id',
        ]);
        $source = $media->only(['disk', 'path', 'mime', 'category', 'size', 'width', 'height', 'sha256']);

        $this->fakePadding();
        $this->fakeUpload();

        $uploader = app(XMediaUploader::class);
        $this->assertSame('13_padded', $uploader->ensureAmplifyUploaded($media, $this->account));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/initialize')
            && $request['media_category'] === 'amplify_video'
            && $request['media_type'] === 'video/mp4'
            && $request['total_bytes'] === strlen(self::PADDED));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/append')
            && str_contains($request->body(), self::PADDED)
            && ! str_contains($request->body(), self::ORIGINAL));

        $media->refresh();
        $this->assertSame($source, $media->only(array_keys($source)));
        $this->assertSame(self::ORIGINAL, Storage::disk('local')->get($media->path));
        $this->assertSame('original-tweet-id', $media->x_media_ids[$originalKey]);

        // A retry skips both conversion and upload, while ordinary tweets
        // still use the original account-scoped media id.
        $this->assertSame('13_padded', $uploader->ensureAmplifyUploaded($media->fresh(), $this->account));
        $this->assertSame('original-tweet-id', $uploader->ensureUploaded($media->fresh(), $this->account));
        Http::assertSentCount(3);
        Process::assertRanTimes(fn (PendingProcess $process): bool => $this->isExecutable($process, 'ffmpeg'), 1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/tweets'));
    }

    #[Test]
    public function an_ordinary_tweet_uploads_the_original_video_without_padding(): void
    {
        $media = $this->video();
        $this->fakeUpload();

        $this->assertSame('padded', app(XMediaUploader::class)->ensureUploaded($media, $this->account));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/initialize')
            && $request['media_category'] === 'tweet_video'
            && $request['total_bytes'] === strlen(self::ORIGINAL));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/append')
            && str_contains($request->body(), self::ORIGINAL));
        Process::assertNothingRan();
        $this->assertSame('padded', $media->fresh()->mediaIdFor($this->account));
    }

    #[Test]
    public function a_failed_conversion_uploads_nothing_and_does_not_poison_the_cache(): void
    {
        $legacy = ['amplify:account:'.$this->account->id => '13_rejected'];
        $media = $this->video($legacy);
        $this->fakePadding(fails: true);
        $this->fakeUpload();

        try {
            app(XMediaUploader::class)->ensureAmplifyUploaded($media, $this->account);
            $this->fail('The failed conversion must stop the card upload.');
        } catch (XApiException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame($legacy, $media->fresh()->x_media_ids);
        $this->assertSame(self::ORIGINAL, Storage::disk('local')->get($media->path));
    }

    private function video(array $cachedIds = []): PostMedia
    {
        Storage::disk('local')->put('media/wide.mp4', self::ORIGINAL);

        return PostMedia::factory()->create([
            'team_id' => $this->team->id,
            'disk' => 'local',
            'path' => 'media/wide.mp4',
            'mime' => 'video/mp4',
            'category' => MediaCategory::TweetVideo,
            'size' => strlen(self::ORIGINAL),
            'width' => 1080,
            'height' => 512,
            'sha256' => hash('sha256', self::ORIGINAL),
            'x_media_ids' => $cachedIds,
        ]);
    }

    #[Test]
    public function upload_chunks_have_time_to_cross_a_slow_proxy_without_relaxing_api_calls(): void
    {
        config(['xpost.http.timeout' => 30, 'xpost.media.upload_timeout' => 120]);
        $timeouts = [];
        Http::fake(function (Request $request, array $options) use (&$timeouts) {
            $step = basename(parse_url($request->url(), PHP_URL_PATH));
            $timeouts[$step] = $options['timeout'];

            return Http::response(match ($step) {
                'initialize' => ['data' => ['id' => 'timed', 'media_key' => '13_timed']],
                'finalize' => ['data' => ['processing_info' => ['state' => 'succeeded']]],
                default => [],
            });
        });

        app(XMediaUploader::class)->ensureUploaded($this->video(), $this->account);

        $this->assertSame(30, $timeouts['initialize']);
        $this->assertSame(120, $timeouts['append']);
        $this->assertSame(30, $timeouts['finalize']);
    }

    private function fakeUpload(): void
    {
        Http::fake([
            'api.x.com/2/media/upload/initialize' => Http::response([
                'data' => ['id' => 'padded', 'media_key' => '13_padded'],
            ]),
            'api.x.com/2/media/upload/padded/append' => Http::response([], 204),
            'api.x.com/2/media/upload/padded/finalize' => Http::response([
                'data' => ['processing_info' => ['state' => 'succeeded']],
            ]),
        ]);
    }

    private function fakePadding(bool $fails = false): void
    {
        Process::fake(function (PendingProcess $process) use ($fails) {
            $command = $process->command;
            $this->assertIsArray($command);

            if ($this->isExecutable($process, 'ffprobe')) {
                $padded = file_get_contents($command[array_key_last($command)]) === self::PADDED;

                return Process::result(output: json_encode([
                    'streams' => [[
                        'codec_type' => 'video',
                        'width' => $padded ? 1920 : 1080,
                        'height' => $padded ? 1080 : 512,
                        'sample_aspect_ratio' => '1:1',
                    ]],
                ], JSON_THROW_ON_ERROR));
            }

            $this->assertTrue($this->isExecutable($process, 'ffmpeg'));

            if ($fails) {
                return Process::result(errorOutput: 'Test encoder failure', exitCode: 1);
            }

            file_put_contents($command[array_key_last($command)], self::PADDED);

            return Process::result();
        });
    }

    private function isExecutable(PendingProcess $process, string $name): bool
    {
        return is_array($process->command)
            && in_array(strtolower(basename(str_replace('\\', '/', (string) $process->command[0]))), [$name, $name.'.exe'], true);
    }
}
