<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

/**
 * Everything a team is publishing, live.
 *
 * Per team rather than per post: an operator watching the list is watching all
 * of them, and a subscription per row would mean a hundred channels on one page
 * and a resubscribe on every filter change.
 *
 * Membership is the whole check, and it has to be one -- a channel name is just
 * a string the browser sends, so without this any signed-in user could name any
 * team and watch its campaigns go out.
 */
Broadcast::channel('team.{teamId}', function (User $user, int $teamId) {
    return $user->belongsToTeam($teamId);
});
