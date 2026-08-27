<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\XAppTier;
use App\Models\Concerns\BelongsToTeam;
use Database\Factories\XAppFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One X developer app. Accounts are spread across a pool of these so that the
 * per-app POST /2/tweets cap stops being the ceiling on throughput.
 */
class XApp extends Model
{
    /** @use HasFactory<XAppFactory> */
    use BelongsToTeam, HasFactory;

    protected $table = 'x_apps';

    protected $fillable = [
        'team_id', 'name', 'tier', 'client_id', 'client_secret',
        // The numeric App ID from X's developer portal. Only ever read by a
        // human matching a row against mail from X, which names apps by it.
        'provider_app_id',
        'consumer_key', 'consumer_secret', 'callback_url', 'scopes',
        'daily_post_cap', 'monthly_post_cap', 'max_accounts', 'weight', 'is_active',
        // Set when the app is created and again by the monthly budget reset in
        // routes/console.php. Missing here, creating an app threw a
        // MassAssignmentException instead of saving.
        'usage_period_started_on',
    ];

    protected $hidden = ['client_secret', 'consumer_secret'];

    protected function casts(): array
    {
        return [
            'tier' => XAppTier::class,
            'client_secret' => 'encrypted',
            'consumer_secret' => 'encrypted',
            'scopes' => 'array',
            'is_active' => 'boolean',
            // null means never checked; false means X refused the Ads API.
            'ads_api_access' => 'boolean',
            'ads_api_checked_at' => 'datetime',
            'daily_post_cap' => 'integer',
            'monthly_post_cap' => 'integer',
            'monthly_posts_used' => 'integer',
            'max_accounts' => 'integer',
            'assigned_count' => 'integer',
            'weight' => 'integer',
            'usage_period_started_on' => 'date',
            'last_used_at' => 'datetime',
            'cooldown_until' => 'datetime',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(XAccount::class);
    }

    public function usage(): HasMany
    {
        return $this->hasMany(XAppUsage::class);
    }

    public function supportsOAuth2(): bool
    {
        return filled($this->client_id) && filled($this->client_secret);
    }

    public function supportsOAuth1(): bool
    {
        return filled($this->consumer_key) && filled($this->consumer_secret);
    }

    public function hasMonthlyBudgetLeft(): bool
    {
        return $this->monthly_posts_used < $this->monthly_post_cap;
    }

    public function isCoolingDown(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->isFuture();
    }

    /** Apps that can still take on more accounts. */
    public function scopeAcceptingAccounts(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereColumn('assigned_count', '<', 'max_accounts');
    }

    /** Apps that are healthy enough to publish through right now. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereColumn('monthly_posts_used', '<', 'monthly_post_cap')
            ->where(fn (Builder $q) => $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now()));
    }
}
