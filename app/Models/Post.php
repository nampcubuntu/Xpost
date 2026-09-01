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
    /**
     * The one dropdown Ads Manager puts a post's kind behind.
     *
     * Promoted and organic are a column; scheduled and drafts are a status.
     * They sit together because that is the question being asked -- "which of
     * my posts" -- and splitting it across two controls made the caller pick a
     * combination that has no rows in it.
     */
    public function scopeType(Builder $query, ?string $type): Builder
    {
        return match ($type) {
            'promoted' => $query->where('promoted_only', true),
            'organic' => $query->where('promoted_only', false),
            'scheduled' => $query->where('status', PostStatus::Scheduled->value),
            'drafts' => $query->where('status', PostStatus::Draft->value),
            default => $query,
        };
    }

    /**
     * Posts that went to one particular account.
     *
     * Through the fan-out rather than off the post: a post here has no single
     * account, which is the whole difference from Ads Manager. `whereHas` and
     * not a join, so a post reaching an account twice cannot come back twice.
     */
    public function scopeForAccount(Builder $query, ?int $accountId): Builder
    {
        return $query->when($accountId, fn (Builder $q, int $id) => $q->whereHas(
            'targets', fn (Builder $w) => $w->where('x_account_id', $id)
        ));
    }

    /**
     * Posts carrying a photo, a video, or nothing.
     *
     * Matched on the mime prefix rather than the category enum: the category
     * says how the file was uploaded to X (amplify_video, tweet_image), which
     * is a detail of the upload and not what someone filtering a list means.
     *
     * @param  list<string>  $kinds  any of photo, video, none
     */
    public function scopeWithMedia(Builder $query, array $kinds): Builder
    {
        $kinds = array_values(array_intersect($kinds, ['photo', 'video', 'none']));

        // Nothing ticked and everything ticked are the same question.
        if ($kinds === [] || count($kinds) === 3) {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($kinds): void {
            foreach ($kinds as $kind) {
                if ($kind === 'none') {
                    $outer->orWhereDoesntHave('media');

                    continue;
                }

                $prefix = $kind === 'photo' ? 'image/' : 'video/';
                $outer->orWhereHas('media', fn (Builder $w) => $w->where('mime', 'like', $prefix.'%'));
            }
        });
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

    /**
     * One failure standing in for the rest.
     *
     * The list said "1 lỗi" and stopped there, so reading what went wrong meant
     * opening the post -- and on a fan-out where every target failed for the
     * same reason, opening it to read the same sentence again. A thousand
     * targets refused by one dead proxy is one fact, and the row can hold it.
     *
     * The latest rather than the first: a retry that failed differently is the
     * one worth reading.
     */
    public function lastFailedTarget(): HasOne
    {
        return $this->hasOne(PostTarget::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->whereNotNull('error_code'));
    }
}
