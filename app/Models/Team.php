<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'owner_id', 'timezone', 'account_quota', 'settings',
        // Null means no ceiling. A limit nobody chose, silently stopping a
        // campaign overnight, would be worse than none at all.
        'daily_budget_usd',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'account_quota' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(XAccount::class);
    }

    public function apps(): HasMany
    {
        return $this->hasMany(XApp::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function proxies(): HasMany
    {
        return $this->hasMany(Proxy::class);
    }

    public function accountGroups(): HasMany
    {
        return $this->hasMany(AccountGroup::class);
    }
}
