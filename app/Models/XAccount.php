<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\CredentialType;
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
        'location', 'timezone', 'timezone_source',
        'credential_type', 'access_token', 'refresh_token', 'token_secret', 'scopes',
        'token_expires_at', 'status', 'daily_post_cap', 'meta',
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
            ->whereIn('status', [AccountStatus::Active->value, AccountStatus::RateLimited->value]);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, fn (Builder $q, string $t) => $q->where(
            fn (Builder $w) => $w->where('username', 'like', "%{$t}%")
                ->orWhere('name', 'like', "%{$t}%")
                ->orWhere('x_user_id', $t)
        ));
    }
}
