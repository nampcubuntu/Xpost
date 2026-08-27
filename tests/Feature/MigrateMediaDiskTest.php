<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PostMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Moving stored media between disks.
 *
 * Switching FILESYSTEM_DISK only changes where new uploads land. Everything
 * already stored stays on the disk its row names -- and stays invisible to a
 * second host, which is the whole reason for moving it.
 */
class MigrateMediaDiskTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team] = $this->tenant();

        Storage::fake('local');
        Storage::fake('r2');
    }

    private function storedOnLocal(string $body = 'video bytes'): PostMedia
    {
        $path = 'media/2026/08/'.uniqid().'.mp4';
        Storage::disk('local')->put($path, $body);

        return PostMedia::factory()->create([
            'team_id' => $this->team->id,
            'disk' => 'local',
            'path' => $path,
            'mime' => 'video/mp4',
        ]);
    }

    #[Test]
    public function it_copies_the_file_and_repoints_the_row(): void
    {
        $media = $this->storedOnLocal();

        $this->artisan('xpost:migrate-media --from=local --to=r2')->assertSuccessful();

        Storage::disk('r2')->assertExists($media->path);
        $this->assertSame('r2', $media->fresh()->disk);
    }

    #[Test]
    public function the_original_is_removed_once_the_copy_is_verified(): void
    {
        $media = $this->storedOnLocal();

        $this->artisan('xpost:migrate-media --from=local --to=r2')->assertSuccessful();

        Storage::disk('local')->assertMissing($media->path);
    }

    #[Test]
    public function keep_source_leaves_the_original_alone(): void
    {
        // For a first pass on live data, where having two copies briefly is
        // worth more than the space.
        $media = $this->storedOnLocal();

        $this->artisan('xpost:migrate-media --from=local --to=r2 --keep-source')->assertSuccessful();

        Storage::disk('local')->assertExists($media->path);
        Storage::disk('r2')->assertExists($media->path);
        $this->assertSame('r2', $media->fresh()->disk);
    }

    #[Test]
    public function pretend_changes_nothing(): void
    {
        $media = $this->storedOnLocal();

        $this->artisan('xpost:migrate-media --from=local --to=r2 --pretend')->assertSuccessful();

        Storage::disk('r2')->assertMissing($media->path);
        $this->assertSame('local', $media->fresh()->disk);
    }

    #[Test]
    public function a_row_pointing_at_a_missing_file_is_reported_not_fatal(): void
    {
        /*
         * A half-finished earlier attempt looks exactly like this, and failing
         * the run would mean it could never be finished.
         */
        $gone = PostMedia::factory()->create([
            'team_id' => $this->team->id,
            'disk' => 'local',
            'path' => 'media/nowhere.mp4',
        ]);

        $good = $this->storedOnLocal();

        $this->artisan('xpost:migrate-media --from=local --to=r2')->assertSuccessful();

        $this->assertSame('local', $gone->fresh()->disk);
        $this->assertSame('r2', $good->fresh()->disk);
    }

    #[Test]
    public function moving_a_disk_onto_itself_is_refused(): void
    {
        $this->artisan('xpost:migrate-media --from=local --to=local')->assertFailed();
    }

    #[Test]
    public function it_moves_media_from_every_team(): void
    {
        // Storage is not a tenant's concern; a run scoped to one team would
        // leave the rest unreachable from the new host.
        $mine = $this->storedOnLocal();

        [$other] = $this->tenant();
        $path = 'media/other.mp4';
        Storage::disk('local')->put($path, 'x');
        $theirs = PostMedia::factory()->create(['team_id' => $other->id, 'disk' => 'local', 'path' => $path]);

        $this->artisan('xpost:migrate-media --from=local --to=r2')->assertSuccessful();

        $this->assertSame('r2', $mine->fresh()->disk);
        $this->assertSame('r2', PostMedia::withoutGlobalScopes()->find($theirs->id)->disk);
    }
}
