<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TeamScope;
use App\Models\Team;
use App\Support\TeamContext;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy(TeamScope::class)]
trait BelongsToTeam
{
    public static function bootBelongsToTeam(): void
    {
        static::creating(function ($model): void {
            if ($model->team_id === null && ($teamId = TeamContext::id()) !== null) {
                $model->team_id = $teamId;
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
