<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\TeamContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Every tenant-owned query is constrained to the active team unless the caller
 * opts out with ->withoutGlobalScope(TeamScope::class). Queue workers set the
 * team explicitly via TeamContext::runAs(), so jobs are scoped too.
 */
final class TeamScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (($teamId = TeamContext::id()) !== null) {
            $builder->where($model->qualifyColumn('team_id'), $teamId);
        }
    }
}
