<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\User;

/**
 * Who may see and change the team's membership.
 *
 * Seeing who else is on the team is ordinary: it explains whose campaigns these
 * are. Changing it is not -- adding a member hands someone the whole account,
 * and changing a role hands them more of it.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_team_id !== null;
    }

    public function create(User $user): bool
    {
        return $user->isAtLeast($user->current_team_id, TeamRole::Admin);
    }

    public function update(User $user, User $member): bool
    {
        return $user->isAtLeast($user->current_team_id, TeamRole::Admin);
    }

    public function delete(User $user, User $member): bool
    {
        return $user->isAtLeast($user->current_team_id, TeamRole::Admin);
    }
}
