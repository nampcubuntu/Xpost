<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TargetStatus;
use App\Models\Concerns\BelongsToTeam;
use Database\Factories\PostTargetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One (post, account) pair. This is the unit of work the dispatcher hands to
 * the queue, and the row that records what X actually returned.
 *
 * A work item, not a ledger. Every publish is already written to ClickHouse by
 * MetricsRecorder -- partitioned by month, ordered by team, expiring on its own
 * after eighteen months -- so once a target is finished the row here has done
 * its job. Left to accumulate it is the one table that grows with the whole
 * pool: a thousand teams of ten thousand accounts posting four times a day is
 * forty million rows a day, which no amount of partitioning makes queryable.
 */
class PostTarget extends Model
{
    /** @use HasFactory<PostTargetFactory> */
    use BelongsToTeam, HasFactory, Prunable;

    /**
     * Deleting a day's work forty million rows at a time is the point; the
     * default of a thousand per query would still be running tomorrow.
     */
    protected int $prunableChunkSize = 5_000;

    /**
     * Finished work, past the window anyone looks at it.
     *
     * Only terminal states. A pending or queued row is work not yet done, and
     * an old scheduled_at is exactly what a backlog looks like -- pruning those
     * would silently cancel a campaign.
     *
     * The window is measured from when the row was last touched rather than
     * from published_at, because a failed target never has one.
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('xpost.publishing.target_retention_days', 30));

        return static::query()
            ->withoutGlobalScopes()
            ->whereIn('status', TargetStatus::terminal())
            ->where('updated_at', '<', now()->subDays($days));
    }

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
