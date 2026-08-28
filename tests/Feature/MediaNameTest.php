<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PostMedia;
use App\Models\Team;
use App\Support\MediaName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What an uploaded clip is filed under.
 *
 * Video arrives as `video.mp4` or `0.21.21.2.21.21.mp4` -- whatever the phone
 * or the downloader called it -- so the one label the picker shows under each
 * tile is the one label that cannot tell two clips apart.
 */
class MediaNameTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $user] = $this->tenant();
        $this->actingAs($user->refresh());
        Storage::fake(config('filesystems.default'));
    }

    #[Test]
    public function a_generated_name_is_a_number_after_a_prefix(): void
    {
        // The shape X files its own video under, which is the shape being
        // matched -- their numbers index something inside X, not anything here.
        $this->assertMatchesRegularExpression('/^13_\d{15,}$/', MediaName::next());
    }

    #[Test]
    public function two_names_taken_in_the_same_instant_differ(): void
    {
        /*
         * The point of the whole exercise. Time alone repeats inside a
         * millisecond, which is exactly when two files from one drag are
         * uploaded.
         */
        $names = collect(range(1, 500))->map(fn () => MediaName::next());

        $this->assertCount(500, $names->unique());
    }

    #[Test]
    public function names_sort_by_the_order_they_were_taken(): void
    {
        // Time in the high bits, which is what anyone scanning a column of
        // numbers is using them for.
        $first = MediaName::next();
        usleep(2000);
        $second = MediaName::next();

        $this->assertTrue($second > $first, "{$second} should sort after {$first}");
    }

    #[Test]
    public function a_name_already_taken_is_not_handed_out_again(): void
    {
        // Checked rather than assumed: "cannot collide" is a claim, and this is
        // the assertion behind it.
        $taken = MediaName::next();

        PostMedia::factory()->create(['team_id' => $this->team->id, 'original_name' => $taken]);

        $this->assertNotSame($taken, MediaName::next());
    }

    /**
     * The smallest MP4 that VideoProbe will accept as carrying video.
     *
     * UploadedFile::fake() writes a file of the right size and no structure, so
     * the probe rejects it -- correctly, that is the check it exists for. The
     * boxes below are the ones it actually reads: a tkhd for the dimensions and
     * an mdia/hdlr saying the track is `vide`.
     */
    private function realVideo(string $name): UploadedFile
    {
        $box = fn (string $type, string $payload) => pack('N', 8 + strlen($payload)).$type.$payload;

        // version+flags(4) times(20) reserved(8) layer..volume(8) matrix(36),
        // then width and height as 16.16 fixed point.
        $tkhd = $box('tkhd', str_repeat("\0", 76).pack('N', 1920 << 16).pack('N', 1080 << 16));
        // version+flags(4) pre_defined(4) handler_type(4)
        $hdlr = $box('hdlr', str_repeat("\0", 8).'vide');

        $path = tempnam(sys_get_temp_dir(), 'xpost').'.mp4';

        file_put_contents(
            $path,
            $box('ftyp', 'isom'.pack('N', 512).'isomiso2avc1mp41')
            .$box('moov', $box('trak', $tkhd.$box('mdia', $hdlr)))
            // Padding the probe skips, so two calls are not byte-identical --
            // identical bytes dedupe on sha256 and return the first row, which
            // is right and is a different test.
            .$box('free', random_bytes(16))
        );

        return new UploadedFile($path, $name, 'video/mp4', null, true);
    }

    #[Test]
    public function an_uploaded_video_is_renamed(): void
    {
        $response = $this->postJson('/api/v1/media', [
            'file' => $this->realVideo('0.21.21.2.21.21.mp4'),
        ])->assertCreated();

        // The name it arrived with is gone, and what replaced it is a number.
        $this->assertMatchesRegularExpression('/^13_\d{15,}$/', $response->json('data.original_name'));
        $this->assertNotSame('0.21.21.2.21.21.mp4', $response->json('data.original_name'));
    }

    #[Test]
    public function two_clips_with_the_same_arriving_name_do_not_collide(): void
    {
        /*
         * Which is the case this exists for. A downloader hands out `video.mp4`
         * every time, so the library filled with rows nothing could tell apart.
         */
        $first = $this->postJson('/api/v1/media', ['file' => $this->realVideo('video.mp4')])
            ->assertCreated()->json('data.original_name');

        $second = $this->postJson('/api/v1/media', ['file' => $this->realVideo('video.mp4')])
            ->assertCreated()->json('data.original_name');

        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function an_uploaded_photo_is_renamed_the_same_way(): void
    {
        /*
         * Same scheme as video. Two schemes in one column would mean reading
         * each row to work out which kind of name it is, and the category is
         * already a field of its own.
         */
        $name = $this->postJson('/api/v1/media', ['file' => UploadedFile::fake()->image('IMG_0042.jpg')])
            ->assertCreated()
            ->json('data.original_name');

        $this->assertMatchesRegularExpression('/^13_\d{15,}$/', $name);
    }

    #[Test]
    public function a_photo_and_a_video_are_told_apart_by_category_not_by_name(): void
    {
        // Which is the reason one scheme is enough: the kind is a column, and
        // the name is only ever an identifier.
        $photo = $this->postJson('/api/v1/media', ['file' => UploadedFile::fake()->image('a.jpg')])
            ->assertCreated();
        $video = $this->postJson('/api/v1/media', ['file' => $this->realVideo('b.mp4')])
            ->assertCreated();

        $this->assertNotSame($photo->json('data.original_name'), $video->json('data.original_name'));
        $this->assertSame('tweet_image', $photo->json('data.category'));
        $this->assertSame('tweet_video', $video->json('data.category'));
    }
}
