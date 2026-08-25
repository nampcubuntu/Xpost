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
 * Media is served through the app, not from a public disk.
 *
 * This exists because the first implementation used Storage::url() against the
 * private local disk: the picker rendered broken thumbnails and every file
 * returned 403. Serving through a route fixes that without making one team's
 * creatives readable to another, and both halves of that are pinned here.
 */
class MediaServingTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        [$this->team, $this->user] = $this->tenant();
    }

    private function upload(string $name = 'creative.jpg'): PostMedia
    {
        $path = UploadedFile::fake()->image($name, 800, 600)->store('media', 'local');

        return PostMedia::create([
            'team_id' => $this->team->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $name,
            'mime' => 'image/jpeg',
            'size' => 1024,
            'width' => 800,
            'height' => 600,
            'sha256' => hash('sha256', $name),
        ]);
    }

    #[Test]
    public function an_owner_can_load_their_media(): void
    {
        $media = $this->upload();

        $this->actingAs($this->user)
            ->get(route('media.file', $media))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    #[Test]
    public function another_teams_media_is_not_reachable(): void
    {
        $media = $this->upload();

        // A second tenant with a valid session must still not see this file.
        [, $outsider] = $this->tenant();

        $this->actingAs($outsider)
            ->get(route('media.file', $media))
            ->assertNotFound();
    }

    #[Test]
    public function a_guest_is_redirected_rather_than_erroring(): void
    {
        $media = $this->upload();

        // The login screen is a SPA route; without redirectGuestsTo this threw
        // a RouteNotFoundException and surfaced as a 500.
        $this->get(route('media.file', $media))
            ->assertRedirect('/login');
    }

    #[Test]
    public function a_missing_file_on_disk_is_a_404_not_a_crash(): void
    {
        $media = $this->upload();
        Storage::disk('local')->delete($media->path);

        $this->actingAs($this->user)
            ->get(route('media.file', $media))
            ->assertNotFound();
    }

    #[Test]
    public function the_library_can_be_filtered_by_type(): void
    {
        $this->upload('photo.jpg');

        PostMedia::create([
            'team_id' => $this->team->id,
            'disk' => 'local',
            'path' => 'media/clip.mp4',
            'original_name' => 'clip.mp4',
            'mime' => 'video/mp4',
            'size' => 2048,
            'sha256' => hash('sha256', 'clip'),
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/media')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->user)
            ->getJson('/api/v1/media?type=video')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_name', 'clip.mp4');

        $this->actingAs($this->user)
            ->getJson('/api/v1/media?type=photo')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_name', 'photo.jpg');
    }

    #[Test]
    public function the_resource_exposes_an_app_url_not_a_disk_path(): void
    {
        $media = $this->upload();

        $this->actingAs($this->user)
            ->getJson('/api/v1/media')
            ->assertOk()
            ->assertJsonPath('data.0.url', route('media.file', $media));
    }
}
