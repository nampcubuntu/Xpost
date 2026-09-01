<?php

declare(strict_types=1);

return [

    'account_status' => [
        'active' => 'Active',
        'needs_reauth' => 'Needs re-authorisation',
        'rate_limited' => 'Rate limited',
        'suspended' => 'Suspended by X',
        'disabled' => 'Disabled',
        'error' => 'Error',
    ],

    'post_status' => [
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'publishing' => 'Publishing',
        'published' => 'Published',
        'partially_failed' => 'Partially failed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],

    'target_status' => [
        'pending' => 'Pending',
        'queued' => 'Queued',
        'publishing' => 'Sending',
        'published' => 'Published',
        'failed' => 'Failed',
        'skipped' => 'Skipped',
        'cancelled' => 'Cancelled',
    ],

    'reply_settings' => [
        'everyone' => 'Everyone can reply',
        'following' => 'Accounts you follow',
        'mentionedUsers' => 'Only accounts you mention',
        'subscribers' => 'Subscribers',
        'verified' => 'Verified accounts',
    ],

    'errors' => [
        'post_not_editable' => 'This post is ":status" and can no longer be edited or republished.',
        'app_has_accounts' => 'This app still has :count account(s) attached. Move them first.',
        'media_in_use' => 'This file is used by an existing post.',
        'not_a_member' => 'You are not a member of that team.',
        'no_app_capacity' => 'No X app in the pool has budget left. Add another app or raise the tier.',
    ],

];
