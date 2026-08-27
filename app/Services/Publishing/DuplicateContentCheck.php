<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostTarget;

/**
 * How much of this exact content has already gone out, and to how many accounts.
 *
 * X's rules are specific about this: posting duplicative or substantially
 * similar content across multiple accounts you control is "never allowed", with
 * or without automation, and explicitly whether or not the posts are staggered.
 * The stated enforcement is app suspension, API access revocation, or permanent
 * bans -- on every account involved, not only the newest.
 *
 * This does not hide anything or work around anything. It shows the operator
 * the same picture X can see, before the post goes out rather than after: the
 * same video and destination, how many accounts have already had it, and when.
 * What to do about it is a decision, and a decision needs the number in front
 * of it.
 */
final class DuplicateContentCheck
{
    /**
     * @param  list<int>  $mediaIds
     * @return array{
     *     accounts: int,
     *     posts: int,
     *     days: int,
     *     last_used_at: ?string,
     * }
     */
    public function for(array $mediaIds, ?string $websiteUrl, ?int $excludePostId = null): array
    {
        $days = max(1, (int) config('xpost.publishing.duplicate_window_days', 30));
        $since = now()->subDays($days);

        $empty = ['accounts' => 0, 'posts' => 0, 'days' => $days, 'last_used_at' => null];

        /*
         * Media and destination, not body text. The body is the part spintax
         * already varies; what makes two posts "substantially similar" to a
         * reader -- and to X -- is the same video pointing at the same place.
         */
        if ($mediaIds === [] && blank($websiteUrl)) {
            return $empty;
        }

        $posts = Post::query()
            ->where('created_at', '>=', $since)
            ->when($excludePostId, fn ($q, $id) => $q->whereKeyNot($id))
            ->when(filled($websiteUrl), fn ($q) => $q->where('website_url', $websiteUrl))
            ->when($mediaIds !== [], fn ($q) => $q->whereHas(
                'media',
                fn ($m) => $m->whereIn('post_media.id', $mediaIds),
            ))
            ->pluck('id');

        if ($posts->isEmpty()) {
            return $empty;
        }

        /*
         * Distinct accounts, not targets: the same account appearing in three
         * campaigns is one account that has seen this content three times, and
         * counting it three times overstates the reach that matters.
         *
         * Only targets that actually went out -- a cancelled campaign put
         * nothing in front of anyone.
         */
        $accounts = PostTarget::query()
            ->whereIn('post_id', $posts)
            ->where('status', TargetStatus::Published->value)
            ->distinct()
            ->count('x_account_id');

        $lastUsed = PostTarget::query()
            ->whereIn('post_id', $posts)
            ->where('status', TargetStatus::Published->value)
            ->max('published_at');

        return [
            'accounts' => $accounts,
            'posts' => $posts->count(),
            'days' => $days,
            'last_used_at' => $lastUsed,
        ];
    }
}
