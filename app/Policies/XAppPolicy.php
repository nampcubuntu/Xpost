<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\XApp;

/**
 * App credentials are the keys to every account in the pool, so only owners
 * and admins ever see or change them.
 */
class XAppPolicy
{
    private const MANAGERS = ['owner', 'admin'];

    public function viewAny(User $user): bool
    {
        return $user->current_team_id !== null
            && $user->hasTeamRole($user->current_team_id, ...self::MANAGERS);
    }

    public function view(User $user, XApp $app): bool
    {
        return $user->hasTeamRole($app->team_id, ...self::MANAGERS);
    }

    public function create(User $user): bool
    {
        return $user->current_team_id !== null
            && $user->hasTeamRole($user->current_team_id, ...self::MANAGERS);
    }

    public function update(User $user, XApp $app): bool
    {
        return $user->hasTeamRole($app->team_id, ...self::MANAGERS);
    }

    public function delete(User $user, XApp $app): bool
    {
        return $user->hasTeamRole($app->team_id, 'owner');
    }
}
