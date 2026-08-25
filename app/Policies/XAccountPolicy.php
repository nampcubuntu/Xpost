<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\XAccount;

class XAccountPolicy
{
    private const MANAGERS = ['owner', 'admin'];

    public function viewAny(User $user): bool
    {
        return $user->current_team_id !== null;
    }

    public function view(User $user, XAccount $account): bool
    {
        return $user->belongsToTeam($account->team_id);
    }

    public function create(User $user): bool
    {
        return $user->current_team_id !== null
            && $user->hasTeamRole($user->current_team_id, ...self::MANAGERS);
    }

    public function update(User $user, XAccount $account): bool
    {
        return $user->hasTeamRole($account->team_id, ...self::MANAGERS);
    }

    public function delete(User $user, XAccount $account): bool
    {
        return $user->hasTeamRole($account->team_id, 'owner');
    }
}
