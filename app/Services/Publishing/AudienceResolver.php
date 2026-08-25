<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Models\Post;
use App\Models\XAccount;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns the composer's audience selection into a concrete account query.
 *
 * The shape stored on Post::$audience is:
 *   {
 *     "mode": "all" | "groups" | "accounts" | "filter",
 *     "groups": [1,2], "accounts": [10,11], "exclude_accounts": [12],
 *     "filters": { "status": "active", "min_followers": 100, "search": "vn",
 *                  "app_ids": [1], "has_proxy": true },
 *     "limit": 5000, "shuffle": true
 *   }
 *
 * Returning a Builder rather than a collection matters: a 10k-account audience
 * is fanned out with chunkById, so it must never be materialised in one array.
 */
final class AudienceResolver
{
    public function query(Post $post): Builder
    {
        $audience = $post->audience ?? [];
        $mode = $audience['mode'] ?? 'all';

        $query = XAccount::query()
            ->where('team_id', $post->team_id)
            ->publishable();

        match ($mode) {
            'groups' => $query->whereHas(
                'groups',
                fn (Builder $q) => $q->whereIn('account_groups.id', $audience['groups'] ?? [])
            ),
            'accounts' => $query->whereIn('id', $audience['accounts'] ?? []),
            'filter' => $this->applyFilters($query, $audience['filters'] ?? []),
            default => null,
        };

        if ($exclude = $audience['exclude_accounts'] ?? []) {
            $query->whereNotIn('id', $exclude);
        }

        if ($excludeGroups = $audience['exclude_groups'] ?? []) {
            $query->whereDoesntHave(
                'groups',
                fn (Builder $q) => $q->whereIn('account_groups.id', $excludeGroups)
            );
        }

        return $query;
    }

    /** Cheap enough to call from the composer on every audience change. */
    public function count(Post $post): int
    {
        $limit = $post->audience['limit'] ?? null;
        $count = $this->query($post)->toBase()->getCountForPagination();

        return $limit ? min($count, (int) $limit) : $count;
    }

    /**
     * The ordered id stream used for fan-out. Shuffling matters when a limit is
     * set, otherwise the same low-id accounts carry every campaign.
     */
    public function idsFor(Post $post): BuilderContract
    {
        $query = $this->query($post)->select('x_accounts.id');

        if ($post->audience['shuffle'] ?? false) {
            $query->inRandomOrder();
        } else {
            $query->orderBy('x_accounts.id');
        }

        if ($limit = $post->audience['limit'] ?? null) {
            $query->limit((int) $limit);
        }

        return $query->toBase();
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn (Builder $q, string $s) => $q->search($s))
            ->when($filters['app_ids'] ?? null, fn (Builder $q, array $ids) => $q->whereIn('x_app_id', $ids))
            ->when($filters['group_ids'] ?? null, fn (Builder $q, array $ids) => $q->whereHas(
                'groups',
                fn (Builder $g) => $g->whereIn('account_groups.id', $ids)
            ))
            ->when(
                isset($filters['min_followers']),
                fn (Builder $q) => $q->where('followers_count', '>=', (int) $filters['min_followers'])
            )
            ->when(
                isset($filters['max_followers']),
                fn (Builder $q) => $q->where('followers_count', '<=', (int) $filters['max_followers'])
            )
            ->when(
                isset($filters['min_health']),
                fn (Builder $q) => $q->where('health_score', '>=', (int) $filters['min_health'])
            )
            ->when(
                array_key_exists('has_proxy', $filters),
                fn (Builder $q) => $filters['has_proxy']
                    ? $q->whereNotNull('proxy_id')
                    : $q->whereNull('proxy_id')
            )
            ->when(
                $filters['not_posted_since'] ?? null,
                fn (Builder $q, string $since) => $q->where(
                    fn (Builder $w) => $w->whereNull('last_posted_at')->orWhere('last_posted_at', '<', $since)
                )
            );
    }
}
