<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\VideoProbe;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reading a video track out of an ISO base media file.
 *
 * Uploads are limited to MP4 and QuickTime, which share this container, so
 * this covers every video the app accepts without needing ffmpeg on the host.
 */
class VideoProbeTest extends TestCase
{
    /**
     * Build a minimal MP4: ftyp, then moov holding one trak whose tkhd carries
     * the given size and whose mdia/hdlr declares the handler type.
     */
    private function file(string $handler, int $width = 0, int $height = 0, int $version = 0): string
    {
        $tkhdBody = chr($version).str_repeat("\x00", 3)
            .str_repeat("\x00", $version === 1 ? 32 : 20)   // times, id, duration
            .str_repeat("\x00", 8)                          // reserved
            .str_repeat("\x00", 8)                          // layer, group, volume, reserved
            .str_repeat("\x00", 36)                         // matrix
            .pack('N', $width << 16).pack('N', $height << 16);

        $hdlrBody = str_repeat("\x00", 8).$handler.str_repeat("\x00", 12);

        $mdia = $this->box('mdia', $this->box('hdlr', $hdlrBody));
        $trak = $this->box('trak', $this->box('tkhd', $tkhdBody).$mdia);

        $path = tempnam(sys_get_temp_dir(), 'probe').'.mp4';
        file_put_contents($path, $this->box('ftyp', 'isom'.str_repeat("\x00", 8)).$this->box('moov', $trak));

        return $path;
    }

    private function box(string $type, string $payload): string
    {
        return pack('N', strlen($payload) + 8).$type.$payload;
    }

    #[Test]
    public function it_reads_the_dimensions_of_a_video_track(): void
    {
        $this->assertSame(
            ['width' => 1080, 'height' => 1920],
            VideoProbe::inspect($this->file('vide', 1080, 1920)),
        );
    }

    #[Test]
    public function it_reads_a_version_one_track_header(): void
    {
        // v1 pushes width and height twelve bytes further in.
        $this->assertSame(
            ['width' => 640, 'height' => 360],
            VideoProbe::inspect($this->file('vide', 640, 360, version: 1)),
        );
    }

    #[Test]
    public function an_audio_only_file_reports_no_video(): void
    {
        /*
         * The case this exists for: an .mp4 whose only track is AAC. Extension,
         * mime type and size are all a video's, and X refuses it at transcode
         * hours later with a message that names nothing.
         */
        $this->assertNull(VideoProbe::inspect($this->file('soun')));
    }

    #[Test]
    public function a_video_track_without_dimensions_is_still_a_video(): void
    {
        // The handler is the answer; the size is a bonus.
        $this->assertSame(['width' => 0, 'height' => 0], VideoProbe::inspect($this->file('vide')));
    }

    #[Test]
    public function a_file_it_cannot_parse_is_not_treated_as_proof(): void
    {
        // Deciding "no video" from a failed parse would reject valid uploads
        // for a container this does not understand.
        $path = tempnam(sys_get_temp_dir(), 'probe');
        file_put_contents($path, 'not an mp4 at all');

        $this->assertNull(VideoProbe::inspect($path));
    }

    #[Test]
    public function a_missing_file_does_not_throw(): void
    {
        $this->assertNull(VideoProbe::inspect(sys_get_temp_dir().'/definitely-not-here.mp4'));
    }
}
