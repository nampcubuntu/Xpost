<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProxyScheme;
use App\Models\Concerns\BelongsToTeam;
use Database\Factories\ProxyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proxy extends Model
{
    /** @use HasFactory<ProxyFactory> */
    use BelongsToTeam, HasFactory;

    protected $table = 'proxies';

    protected $fillable = [
        'team_id', 'label', 'scheme', 'host', 'port', 'username', 'password',
        'country', 'max_accounts', 'is_active',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'scheme' => ProxyScheme::class,
            'password' => 'encrypted',
            'is_active' => 'boolean',
            'port' => 'integer',
            'max_accounts' => 'integer',
            'assigned_count' => 'integer',
            'latency_ms' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(XAccount::class);
    }

    /** Guzzle-compatible proxy URI, credentials inlined. */
    public function toUri(): string
    {
        $auth = $this->username
            ? rawurlencode($this->username).':'.rawurlencode((string) $this->password).'@'
            : '';

        return "{$this->scheme->value}://{$auth}{$this->host}:{$this->port}";
    }

    public function scopeAssignable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereIn('health_status', ['healthy', 'unknown'])
            ->whereColumn('assigned_count', '<', 'max_accounts');
    }
}
