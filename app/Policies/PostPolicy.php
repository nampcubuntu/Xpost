<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    /** Anyone on the team can read; only editors and up can change things. */
    private const EDITORS = ['owner', 'admin', 'editor'];

    private const ADMINS = ['owner', 'admin'];

    public function viewAny(User $user): bool
    {
        return $user->current_team_id !== null;
    }

    public function view(User $user, Post $post): bool
    {
        return $user->belongsToTeam($post->team_id);
    }

    public function create(User $user): bool
    {
        return $user->current_team_id !== null
            && $user->hasTeamRole($user->current_team_id, ...self::EDITORS);
    }

    public function update(User $user, Post $post): bool
    {
        return $user->hasTeamRole($post->team_id, ...self::EDITORS) && $post->isEditable();
    }

    public function delete(User $user, Post $post): bool
    {
        return $user->hasTeamRole($post->team_id, ...self::EDITORS);
    }

    /** Publishing spends real API budget, so it is an admin/editor action. */
    public function publish(User $user, Post $post): bool
    {
        return $user->hasTeamRole($post->team_id, ...self::EDITORS);
    }

    public function cancel(User $user, Post $post): bool
    {
        return $user->hasTeamRole($post->team_id, ...self::ADMINS);
    }
}
