<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Database\Factories\ProxyGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProxyGroup extends Model
{
    /** @use HasFactory<ProxyGroupFactory> */
    use BelongsToTeam, HasFactory;

    protected $fillable = ['team_id', 'name', 'color', 'description'];

    protected function casts(): array
    {
        return ['proxies_count' => 'integer'];
    }

    public function proxies(): BelongsToMany
    {
        return $this->belongsToMany(Proxy::class, 'proxy_group_proxy');
    }

    /**
     * Recount from the pivot.
     *
     * Recounted rather than incremented because the ways membership changes are
     * a sync of a whole selection and a cascade from a deleted proxy, and only
     * one of those passes through here. A count over one group's pivot rows is
     * cheap; a counter that quietly drifts is not.
     */
    public function syncCount(): void
    {
        $this->newQuery()->whereKey($this->getKey())
            ->update(['proxies_count' => $this->proxies()->count()]);
    }
}
