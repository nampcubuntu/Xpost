<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\CredentialType;
use App\Enums\TargetStatus;
use App\Models\Concerns\BelongsToTeam;
use Database\Factories\XAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single authorised X account. Tokens are stored with encrypted casts, so a
 * database dump on its own is not enough to post as anyone.
 */
class XAccount extends Model
{
    /** @use HasFactory<XAccountFactory> */
    use BelongsToTeam, HasFactory, SoftDeletes;

    protected $table = 'x_accounts';

    protected $fillable = [
        'team_id', 'x_app_id', 'proxy_id', 'x_user_id', 'username', 'name', 'avatar_url',
        'followers_count', 'following_count', 'tweet_count', 'verified_type',
        'location', 'timezone', 'timezone_source', 'ads_account_id',
        'credential_type', 'access_token', 'refresh_token', 'token_secret', 'scopes',
        'token_expires_at', 'status', 'daily_post_cap', 'meta',
        // Written by the OAuth upsert and the token refresher. Missing here,
        // connecting an account died with a MassAssignmentException after the
        // authorization code had already been spent.
        'token_refreshed_at', 'last_synced_at',
    ];

    protected $hidden = ['access_token', 'refresh_token', 'token_secret'];

    protected function casts(): array
    {
        return [
            'credential_type' => CredentialType::class,
            'status' => AccountStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_secret' => 'encrypted',
            'scopes' => 'array',
            'meta' => 'array',
            'token_expires_at' => 'datetime',
            'token_refreshed_at' => 'datetime',
            'last_error_at' => 'datetime',
            'last_posted_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'cooldown_until' => 'datetime',
            'followers_count' => 'integer',
            'following_count' => 'integer',
            'tweet_count' => 'integer',
            'health_score' => 'integer',
            'daily_post_cap' => 'integer',
            'consecutive_failures' => 'integer',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(XApp::class, 'x_app_id');
    }

    public function proxy(): BelongsTo
    {
        return $this->belongsTo(Proxy::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(AccountGroup::class, 'account_group_x_account');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    public function handle(): string
    {
        return '@'.$this->username;
    }

    public function tokenIsExpiring(int $withinSeconds = 300): bool
    {
        return $this->credential_type === CredentialType::OAuth2
            && $this->token_expires_at !== null
            && $this->token_expires_at->subSeconds($withinSeconds)->isPast();
    }

    public function isCoolingDown(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->isFuture();
    }

    /** Ready to be handed a publish job right now. */
    public function canPublish(): bool
    {
        return $this->status->isPublishable()
            && ! $this->isCoolingDown()
            && $this->x_app_id !== null;
    }

    public function scopePublishable(Builder $query): Builder
    {
        return $query->whereIn('status', array_column(AccountStatus::publishable(), 'value'))
            ->whereNotNull('x_app_id')
            ->where(fn (Builder $q) => $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now()));
    }

    public function scopeNeedingTokenRefresh(Builder $query, int $withinSeconds = 900): Builder
    {
        return $query->where('credential_type', CredentialType::OAuth2->value)
            ->whereNotNull('refresh_token')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addSeconds($withinSeconds))
            ->where('status', AccountStatus::Active->value);
    }

    /**
     * Narrow a refresh run to accounts with work coming up.
     *
     * An OAuth 2.0 token lasts two hours, so refreshing every account that
     * holds one means refreshing the whole pool twelve times a day whether or
     * not it publishes -- ten million accounts would need fourteen hundred
     * refreshes a second, sustained, to keep tokens nobody is going to use.
     *
     * An account that posts four times a day needs four working tokens, not
     * twelve. XOAuth2Service::freshAccessToken() already refreshes on use, so
     * this pre-warm exists only to keep that refresh off the publish path --
     * which makes it worth doing for accounts about to publish, and pure waste
     * for the rest.
     */
    public function scopeWithWorkDueWithin(Builder $query, int $minutes): Builder
    {
        return $query->whereExists(function ($sub) use ($minutes): void {
            $sub->selectRaw('1')
                ->from('post_targets')
                ->whereColumn('post_targets.x_account_id', 'x_accounts.id')
                ->whereIn('post_targets.status', TargetStatus::claimable())
                ->where('post_targets.scheduled_at', '<=', now()->addMinutes($minutes));
        });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        /*
         * The UI shows handles as "@goodboy_9x" and the search box invites the
         * same, but the column stores them bare -- so pasting a handle in
         * matched nothing at all.
         *
         * `_` and `%` are escaped because a handle legitimately contains an
         * underscore, which LIKE would otherwise read as "any character".
         */
        $term = ltrim(trim((string) $term), '@');

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        /*
         * ESCAPE is spelled out because it is only implicit on MySQL. SQLite --
         * what the tests run on -- has no default escape character, so the
         * backslashes above would be matched literally and find nothing.
         */
        return $query->where(fn (Builder $w) => $w
            ->whereRaw('username LIKE ? ESCAPE ?', [$like, '\\'])
            ->orWhereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
            ->orWhere('x_user_id', $term)
        );
    }
}
