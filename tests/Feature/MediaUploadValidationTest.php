<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Catching a video with no video in it, at upload.
 *
 * An .mp4 carrying only an AAC track passes extension, mime type and size
 * checks -- they are all a video's. It fails hours later inside a publish
 * worker as "Media transcoding failed: unknown", against a post whose account
 * looks misconfigured. By then nothing on screen connects the error to the
 * file, which is exactly what happened before this existed.
 */
class MediaUploadValidationTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->user] = $this->tenant();

        Storage::fake(config('filesystems.default'));
    }

    /** A minimal ISO base media file holding one track of the given handler. */
    private function mp4(string $handler, int $width = 0, int $height = 0): UploadedFile
    {
        $box = fn (string $type, string $payload) => pack('N', strlen($payload) + 8).$type.$payload;

        $tkhd = $box('tkhd', str_repeat("\x00", 4 + 20 + 8 + 8 + 36)
            .pack('N', $width << 16).pack('N', $height << 16));
        $hdlr = $box('hdlr', str_repeat("\x00", 8).$handler.str_repeat("\x00", 12));
        $trak = $box('trak', $tkhd.$box('mdia', $hdlr));

        $path = tempnam(sys_get_temp_dir(), 'upload').'.mp4';
        file_put_contents($path, $box('ftyp', 'isom'.str_repeat("\x00", 8)).$box('moov', $trak));

        return new UploadedFile($path, 'clip.mp4', 'video/mp4', null, true);
    }

    #[Test]
    public function an_audio_only_mp4_is_refused(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/media', ['file' => $this->mp4('soun')])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'Tệp này không có luồng video (có thể chỉ chứa âm thanh). X sẽ từ chối khi đăng.');

        $this->assertDatabaseCount('post_media', 0);
    }

    #[Test]
    public function a_real_video_is_accepted_and_measured(): void
    {
        /*
         * Dimensions come from the file rather than from the browser PATCHing
         * back what a <video> element reported: that answer arrives late, can
         * fail to arrive, and never arrives for an API upload.
         */
        $this->actingAs($this->user)
            ->postJson('/api/v1/media', ['file' => $this->mp4('vide', 1080, 1920)])
            ->assertCreated()
            ->assertJsonPath('data.width', 1080)
            ->assertJsonPath('data.height', 1920);
    }

    #[Test]
    public function an_image_is_left_to_getimagesize(): void
    {
        // The probe is for the ISO container only; images already had an answer.
        $this->actingAs($this->user)
            ->postJson('/api/v1/media', ['file' => UploadedFile::fake()->image('a.jpg', 800, 600)])
            ->assertCreated()
            ->assertJsonPath('data.width', 800)
            ->assertJsonPath('data.height', 600);
    }
}
