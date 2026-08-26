<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TargetStatus;
use App\Models\Concerns\BelongsToTeam;
use Database\Factories\PostTargetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One (post, account) pair. This is the unit of work the dispatcher hands to
 * the queue, and the row that records what X actually returned.
 */
class PostTarget extends Model
{
    /** @use HasFactory<PostTargetFactory> */
    use BelongsToTeam, HasFactory;

    protected $fillable = [
        'post_id', 'x_account_id', 'team_id', 'post_variant_id',
        'rendered_body', 'rendered_headline', 'rendered_url', 'rendered_media_ids', 'card_uri',
        'status', 'scheduled_at', 'available_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TargetStatus::class,
            'rendered_media_ids' => 'array',
            'scheduled_at' => 'datetime',
            'available_at' => 'datetime',
            'claimed_at' => 'datetime',
            'published_at' => 'datetime',
            'attempts' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(XAccount::class, 'x_account_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(PostVariant::class, 'post_variant_id');
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(XApp::class, 'x_app_id');
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->whereIn('status', TargetStatus::claimable())
            ->where('scheduled_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()));
    }

    public function markPublished(string $tweetId, string $username, int $durationMs): void
    {
        $this->forceFill([
            'status' => TargetStatus::Published,
            'x_tweet_id' => $tweetId,
            'permalink' => "https://x.com/{$username}/status/{$tweetId}",
            'published_at' => now(),
            'duration_ms' => min($durationMs, 65535),
            'error_code' => null,
            'error_message' => null,
        ])->save();
    }

    public function markFailed(string $code, string $message): void
    {
        $this->forceFill([
            'status' => TargetStatus::Failed,
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 2000),
        ])->save();
    }
}
