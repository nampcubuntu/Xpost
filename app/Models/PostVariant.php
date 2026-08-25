<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PostVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostVariant extends Model
{
    /** @use HasFactory<PostVariantFactory> */
    use HasFactory;

    protected $fillable = ['post_id', 'body', 'headline', 'website_url', 'media_ids', 'weight'];

    protected function casts(): array
    {
        return [
            'media_ids' => 'array',
            'weight' => 'integer',
            'usage_count' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
