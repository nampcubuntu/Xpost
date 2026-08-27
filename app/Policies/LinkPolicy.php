<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\User;

/**
 * Who may read and change the short-link store.
 *
 * These keys are read by a Worker serving live traffic, and Xpost is one writer
 * of a store shared with everything already published. Deleting a key does not
 * unpublish anything -- it makes a link in a live post stop resolving, quietly,
 * for everyone who clicks it. That is shared plumbing, not campaign content.
 *
 * The controller shipped with no authorization at all, so any member of a team
 * could rewrite or empty it.
 */
class LinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_team_id !== null;
    }

    public function manage(User $user): bool
    {
        return $user->isAtLeast($user->current_team_id, TeamRole::Admin);
    }
}
