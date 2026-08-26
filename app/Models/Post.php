<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DestinationType;
use App\Enums\PostStatus;
use App\Enums\ReplySettings;
use App\Enums\ScheduleMode;
use App\Enums\VariantStrategy;
use App\Models\Concerns\BelongsToTeam;
use App\Services\Publishing\TweetTextWeigher;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use BelongsToTeam, HasFactory, SoftDeletes;

    protected $fillable = [
        'team_id', 'campaign_id', 'created_by', 'name', 'body', 'reply_settings',
        'destination_type', 'website_url', 'headline', 'app_store_url', 'app_country_code',
        'is_ai_generated', 'promoted_only', 'variant_strategy',
        'schedule_mode', 'timezone', 'scheduled_at', 'window_ends_at',
        'jitter_seconds', 'per_minute_cap', 'audience', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'reply_settings' => ReplySettings::class,
            'destination_type' => DestinationType::class,
            'status' => PostStatus::class,
            'variant_strategy' => VariantStrategy::class,
            'schedule_mode' => ScheduleMode::class,
            'is_ai_generated' => 'boolean',
            'promoted_only' => 'boolean',
            'audience' => 'array',
            'settings' => 'array',
            'scheduled_at' => 'datetime',
            'window_ends_at' => 'datetime',
            'published_at' => 'datetime',
            'occurrence_at' => 'datetime',
            'jitter_seconds' => 'integer',
            'per_minute_cap' => 'integer',
            'targets_total' => 'integer',
            'targets_pending' => 'integer',
            'targets_published' => 'integer',
            'targets_failed' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** Set when this post was stamped out by a recurring schedule. */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PostSchedule::class, 'post_schedule_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(PostVariant::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(PostMedia::class, 'post_media_post')
            ->withPivot('position')
            ->orderBy('position');
    }

    /** X counts URLs as 23 characters and CJK glyphs as 2. */
    public function weightedLength(): int
    {
        return TweetTextWeigher::weight((string) $this->body);
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Scheduled->value)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }

    public function scopeStatus(Builder $query, PostStatus|string|null $status): Builder
    {
        return $query->when($status, fn (Builder $q, $s) => $q->where('status', $s instanceof PostStatus ? $s->value : $s));
    }

    /**
     * Organic or promoted-only, the way X Ads Manager splits its post list.
     *
     * A promoted-only post is live and has a real id, it just never appears on
     * the author's timeline -- so telling the two apart matters more than the
     * publishing status does when you are looking for something.
     */
    public function scopeType(Builder $query, ?string $type): Builder
    {
        return match ($type) {
            'promoted' => $query->where('promoted_only', true),
            'organic' => $query->where('promoted_only', false),
            default => $query,
        };
    }

    /**
     * One target standing in for the whole fan-out, for list views.
     *
     * A post here goes to many accounts, unlike Ads Manager where a post is one
     * account -- so the list shows the earliest target plus a count rather than
     * pretending there is a single author. Loaded as a relation so 25 rows cost
     * one extra query, not 25.
     */
    public function sampleTarget(): HasOne
    {
        return $this->hasOne(PostTarget::class)->ofMany('id', 'min');
    }
}
