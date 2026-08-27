<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PostMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Media has to be readable from a host that did not receive the upload.
 *
 * This is what decides whether a second server is useful at all: a video
 * uploaded through host A lives on host A's drive, and a worker on host B picks
 * up the job, finds nothing, and fails the publish. Sharing Redis does not
 * help, because the file is not in Redis.
 *
 * Nothing here needs R2 to run. What it proves is that the application does not
 * care which disk it is on -- so the day a second host appears, the change is
 * one line of .env rather than a hunt through the code.
 */
class MediaPortabilityTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->user] = $this->tenant();
    }

    #[Test]
    public function an_upload_records_the_disk_it_landed_on(): void
    {
        /*
         * Per row, not per install. Switching the default must not orphan
         * everything uploaded before the switch -- those files are still where
         * they were, and the row remembers.
         */
        Storage::fake('r2');
        config(['filesystems.default' => 'r2']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/media', ['file' => UploadedFile::fake()->image('a.jpg', 800, 600)])
            ->assertCreated();

        $media = PostMedia::withoutGlobalScopes()->findOrFail($response->json('data.id'));

        $this->assertSame('r2', $media->disk);
        Storage::disk('r2')->assertExists($media->path);
    }

    #[Test]
    public function files_from_before_a_switch_are_still_read_from_their_own_disk(): void
    {
        // The case that breaks a naive migration: the default moved, the file
        // did not.
        Storage::fake('local');
        Storage::fake('r2');

        config(['filesystems.default' => 'local']);

        $old = $this->actingAs($this->user)
            ->postJson('/api/v1/media', ['file' => UploadedFile::fake()->image('old.jpg', 100, 100)])
            ->json('data.id');

        config(['filesystems.default' => 'r2']);

        // Different dimensions, so different bytes: identical files are
        // deduplicated by hash and would reuse the first row, disk and all.
        $new = $this->actingAs($this->user)
            ->postJson('/api/v1/media', ['file' => UploadedFile::fake()->image('new.jpg', 120, 90)])
            ->json('data.id');

        $this->assertSame('local', PostMedia::withoutGlobalScopes()->find($old)->disk);
        $this->assertSame('r2', PostMedia::withoutGlobalScopes()->find($new)->disk);
    }

    #[Test]
    public function a_remote_disk_hands_the_browser_a_signed_url(): void
    {
        /*
         * Streaming a video through PHP on every view costs the whole file in
         * bandwidth and a worker for its duration. A remote disk can sign a URL
         * and stay out of the data path.
         */
        Storage::fake('r2');
        config(['filesystems.default' => 'r2']);

        $id = $this->actingAs($this->user)
            ->postJson('/api/v1/media', ['file' => UploadedFile::fake()->image('a.jpg', 100, 100)])
            ->json('data.id');

        $this->actingAs($this->user)
            ->get("/media/{$id}/file")
            ->assertRedirect();
    }

    #[Test]
    public function a_local_disk_still_streams_the_file_itself(): void
    {
        // One host, no signing service: the old path has to keep working.
        config(['filesystems.default' => 'local']);

        $id = $this->actingAs($this->user)
            ->postJson('/api/v1/media', ['file' => UploadedFile::fake()->image('a.jpg', 100, 100)])
            ->json('data.id');

        $this->actingAs($this->user)
            ->get("/media/{$id}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        Storage::disk('local')->delete(PostMedia::withoutGlobalScopes()->find($id)->path);
    }

    #[Test]
    public function media_missing_from_its_disk_is_reported_rather_than_guessed_at(): void
    {
        /*
         * The exact symptom of a second host reading a first host's local disk.
         * It has to name the disk, or the failure reads as "the file is gone"
         * when the file is fine and the host is wrong.
         */
        $media = PostMedia::factory()->create([
            'team_id' => $this->user->current_team_id,
            'disk' => 'local',
            'path' => 'media/nowhere.mp4',
            'mime' => 'video/mp4',
        ]);

        $this->actingAs($this->user)
            ->get("/media/{$media->id}/file")
            ->assertNotFound();
    }
}
