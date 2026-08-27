<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MediaCategory;
use App\Models\Concerns\BelongsToTeam;
use Database\Factories\PostMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class PostMedia extends Model
{
    /** @use HasFactory<PostMediaFactory> */
    use BelongsToTeam, HasFactory;

    protected $table = 'post_media';

    protected $fillable = [
        'team_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime',
        'category', 'size', 'width', 'height', 'duration_ms', 'sha256',
        'alt_text', 'is_ai_generated', 'x_media_ids',
    ];

    protected function casts(): array
    {
        return [
            'category' => MediaCategory::class,
            'x_media_ids' => 'array',
            'is_ai_generated' => 'boolean',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_media_post');
    }

    /**
     * Browser-facing URL.
     *
     * Points at an authenticated route rather than the storage disk: files live
     * on the private disk, and a team must not be able to read another team's
     * creatives by guessing a path.
     */
    public function url(): string
    {
        return route('media.file', $this);
    }

    /**
     * X media ids for this file, keyed by who uploaded it and in what form.
     *
     * X scopes an uploaded file to the uploading user, so these are per
     * account rather than per app -- a second account on the same app cannot
     * attach the first one's media. XMediaUploader owns the keys.
     */
    public function mediaIdFor(XAccount $account, string $kind = 'media'): ?string
    {
        return data_get($this->x_media_ids, $kind.':account:'.$account->id);
    }
}
