<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaCategory;
use App\Models\PostMedia;
use App\Services\X\XCardMediaPreparer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Real encoder checks, in addition to the upload tests with faked processes. */
class CardMediaPreparationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ffmpeg', 'ffprobe'] as $binary) {
            try {
                $result = Process::run([config('xpost.media.'.$binary.'_binary'), '-version']);
                $available = $result->successful()
                    && preg_match('/version\s+n?(\d+)\./', $result->output(), $version)
                    && (int) $version[1] >= 8;
            } catch (\Throwable) {
                $available = false;
            }

            if (! $available) {
                $this->markTestSkipped('Real media preparation requires FFmpeg and ffprobe 8+.');
            }
        }

        Storage::fake('local');
    }

    private function video(int $width, int $height, string $sar = '1/1', bool $audio = false): PostMedia
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('media');
        $path = 'media/source.mp4';
        $command = [
            config('xpost.media.ffmpeg_binary'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', "color=c=red:s={$width}x{$height}:r=10:d=0.3",
        ];
        if ($audio) {
            array_push($command, '-f', 'lavfi', '-i', 'sine=frequency=440:duration=0.3');
        }
        array_push($command, '-vf', 'setsar='.$sar, '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', $disk->path($path));
        $result = Process::timeout(20)->run($command);
        $this->assertTrue($result->successful(), $result->errorOutput());

        return new PostMedia([
            'team_id' => 1, 'disk' => 'local', 'path' => $path,
            'mime' => 'video/mp4', 'category' => MediaCategory::TweetVideo,
            // Deliberately stale browser metadata: preparation must inspect the file.
            'width' => 1600, 'height' => 900,
            'size' => $disk->size($path), 'sha256' => hash_file('sha256', $disk->path($path)),
        ]);
    }

    private function streams(PostMedia $media): array
    {
        $result = Process::timeout(20)->run([
            config('xpost.media.ffprobe_binary'), '-v', 'error', '-show_streams', '-of', 'json',
            Storage::disk($media->disk)->path($media->path),
        ]);
        $this->assertTrue($result->successful(), $result->errorOutput());

        return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR)['streams'];
    }

    #[Test]
    public function the_reported_ratio_is_padded_with_audio_and_the_original_is_preserved(): void
    {
        $media = $this->video(1080, 512, audio: true);
        $original = $media->getAttributes();
        $prepared = app(XCardMediaPreparer::class)->prepare($media);

        $this->assertSame($original, $media->getAttributes());
        $this->assertSame($media->sha256, hash_file('sha256', Storage::disk('local')->path($media->path)));
        $this->assertNotSame($media->path, $prepared->path);
        $this->assertFalse($prepared->exists);
        $streams = $this->streams($prepared);
        $this->assertSame('16:9', $streams[0]['display_aspect_ratio']);
        $this->assertSame('1:1', $streams[0]['sample_aspect_ratio']);
        $this->assertSame('h264', $streams[0]['codec_name']);
        $this->assertSame('aac', $streams[1]['codec_name']);
        $this->assertGreaterThanOrEqual(1080, $streams[0]['width']);
        $this->assertGreaterThanOrEqual(512, $streams[0]['height']);

        // Decode a real frame: black border, red content at all four original corners.
        $frame = Storage::disk('local')->path('frame.png');
        Process::timeout(20)->run([
            config('xpost.media.ffmpeg_binary'), '-v', 'error', '-y',
            '-i', Storage::disk('local')->path($prepared->path), '-frames:v', '1', $frame,
        ])->throw();
        $image = imagecreatefrompng($frame);
        $this->assertLessThan(10, (imagecolorat($image, 0, 0) >> 16) & 255);
        $left = (imagesx($image) - 1080) / 2;
        $top = (imagesy($image) - 512) / 2;
        foreach ([[$left + 4, $top + 4], [$left + 1075, $top + 4], [$left + 4, $top + 507], [$left + 1075, $top + 507]] as [$x, $y]) {
            $this->assertGreaterThan(240, (imagecolorat($image, (int) $x, (int) $y) >> 16) & 255);
        }
        imagedestroy($image);

        Cache::flush();
        Process::fake()->preventStrayProcesses();
        $again = app(XCardMediaPreparer::class)->prepare($media);
        $this->assertSame($prepared->path, $again->path);
        $this->assertSame($prepared->size, $again->size);
        Process::assertNothingRan();
    }

    #[Test]
    public function non_square_pixels_are_preserved_as_display_dimensions_without_requiring_audio(): void
    {
        $prepared = app(XCardMediaPreparer::class)->prepare($this->video(720, 576, '16/15'));
        $streams = $this->streams($prepared);

        $this->assertCount(1, $streams);
        $this->assertSame('1:1', $streams[0]['sample_aspect_ratio']);
        $this->assertContains($streams[0]['display_aspect_ratio'], ['2:3', '4:5', '191:100', '1:1', '9:16', '16:9']);
        $this->assertGreaterThanOrEqual(768, $streams[0]['width']);
        $this->assertGreaterThanOrEqual(576, $streams[0]['height']);
    }

    #[Test]
    public function a_supported_display_ratio_with_non_square_pixels_is_normalized(): void
    {
        $media = $this->video(720, 576, '64/45');
        $prepared = app(XCardMediaPreparer::class)->prepare($media);
        $streams = $this->streams($prepared);

        $this->assertNotSame($media->path, $prepared->path);
        $this->assertSame('1:1', $streams[0]['sample_aspect_ratio']);
        $this->assertSame('16:9', $streams[0]['display_aspect_ratio']);
        $this->assertSame(1024, $streams[0]['width']);
        $this->assertSame(576, $streams[0]['height']);
    }

    #[Test]
    public function rotation_is_applied_before_choosing_the_padding(): void
    {
        $media = $this->video(640, 480);
        $disk = Storage::disk('local');
        Process::timeout(20)->run([
            config('xpost.media.ffmpeg_binary'), '-v', 'error', '-y', '-display_rotation:v:0', '90',
            '-i', $disk->path($media->path), '-c', 'copy', $disk->path('media/rotated.mp4'),
        ])->throw();
        $media->path = 'media/rotated.mp4';
        $media->sha256 = hash_file('sha256', $disk->path($media->path));

        $prepared = app(XCardMediaPreparer::class)->prepare($media);
        $streams = $this->streams($prepared);
        $this->assertSame('4:5', $streams[0]['display_aspect_ratio']);
        $this->assertSame(512, $streams[0]['width']);
        $this->assertSame(640, $streams[0]['height']);
        $this->assertEmpty($streams[0]['side_data_list'] ?? []);
    }

    public static function supportedDimensions(): array
    {
        return [[320, 180], [180, 320], [240, 240], [240, 300], [200, 300], [382, 200]];
    }

    #[Test]
    #[DataProvider('supportedDimensions')]
    public function supported_ratios_keep_the_original_video(int $width, int $height): void
    {
        $media = $this->video($width, $height);
        $this->assertSame($media, app(XCardMediaPreparer::class)->prepare($media));
        $this->assertSame(['media/source.mp4'], Storage::disk('local')->allFiles());
    }
}
