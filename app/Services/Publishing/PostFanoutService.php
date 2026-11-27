<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Enums\VariantStrategy;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostVariant;
use App\Models\XAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Expands one Post into one PostTarget per account.
 *
 * Everything the publisher will need is resolved here and frozen onto the row:
 * the rendered text, the chosen variant, the exact scheduled instant. That way
 * a publish job needs no template evaluation, a later edit to the post cannot
 * rewrite what was already sent, and a stuck target can be inspected for
 * exactly what it was going to say.
 *
 * Writes go out in chunked bulk inserts -- 10k individual Eloquent saves would
 * mean 10k round trips and 10k model instantiations for no benefit.
 */
final class PostFanoutService
{
    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly SchedulePlanner $planner,
        private readonly SpintaxRenderer $spintax,
    ) {}

    /**
     * @return int number of targets created
     */
    public function fanOut(Post $post): int
    {
        $post->loadMissing(['variants', 'media']);

        $accountIds = $this->audience->idsFor($post)->pluck('id')->all();
        $total = count($accountIds);

        if ($total === 0) {
            return 0;
        }

        $variants = $post->variants->sortByDesc('weight')->values();
        $mediaIds = $post->media->pluck('id')->all();
        $existing = $this->existingAccountIds($post);

        $created = 0;
        $index = 0;

        foreach (array_chunk($accountIds, self::INSERT_CHUNK) as $chunk) {
            $accounts = XAccount::query()
                ->whereIn('id', $chunk)
                ->get(['id', 'username', 'name', 'followers_count', 'timezone'])
                ->keyBy('id');

            $rows = [];

            foreach ($chunk as $accountId) {
                $position = $index++;

                if (isset($existing[$accountId])) {
                    continue;
                }

                $account = $accounts->get($accountId);

                if ($account === null) {
                    continue;
                }

                $variant = $this->pickVariant($post, $variants, $position);

                $rows[] = $this->buildRow($post, $account, $variant, $position, $total, $mediaIds);
            }

            if ($rows !== []) {
                PostTarget::insert($rows);
                $created += count($rows);
            }
        }

        $this->refreshCounters($post, $created);

        return $created;
    }

    /** @param  Collection<int, PostVariant>  $variants */
    private function pickVariant(Post $post, Collection $variants, int $position): ?PostVariant
    {
        if ($variants->isEmpty() || ! $post->variant_strategy->usesVariantTable()) {
            return null;
        }

        return match ($post->variant_strategy) {
            VariantStrategy::RoundRobin,
            VariantStrategy::PerAccount => $variants[$position % $variants->count()],
            VariantStrategy::Random => $this->weightedPick($variants),
            default => null,
        };
    }

    /** @param  Collection<int, PostVariant>  $variants */
    private function weightedPick(Collection $variants): PostVariant
    {
        $totalWeight = (int) $variants->sum('weight') ?: $variants->count();
        $roll = random_int(1, $totalWeight);
        $running = 0;

        foreach ($variants as $variant) {
            $running += max(1, $variant->weight);

            if ($roll <= $running) {
                return $variant;
            }
        }

        return $variants->last();
    }

    private function buildRow(
        Post $post,
        XAccount $account,
        ?PostVariant $variant,
        int $position,
        int $total,
        array $mediaIds,
    ): array {
        $template = $variant?->body ?? (string) $post->body;

        // Seeded on (post, account) so re-running fan-out reproduces the same
        // text rather than quietly generating a second version.
        $seed = crc32($post->id.':'.$account->id);

        $body = $post->variant_strategy === VariantStrategy::Same
            ? $this->spintax->substitute($template, $account)
            : $this->spintax->renderFor($template, $account, $seed);

        $slot = $this->planner->slotFor($post, $position, $total, $account->timezone);

        $now = now();

        return [
            'post_id' => $post->id,
            'x_account_id' => $account->id,
            'team_id' => $post->team_id,
            'post_variant_id' => $variant?->id,
            'rendered_body' => $body,
            'rendered_headline' => $variant?->headline ?? $post->headline,
            'rendered_url' => $variant?->website_url ?? $post->website_url,
            'rendered_media_ids' => json_encode($variant?->media_ids ?? $mediaIds),
            'status' => TargetStatus::Pending->value,
            'scheduled_at' => $slot,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** Re-running fan-out must be idempotent; the unique index is the backstop. */
    private function existingAccountIds(Post $post): array
    {
        return PostTarget::query()
            ->where('post_id', $post->id)
            ->pluck('x_account_id')
            ->flip()
            ->all();
    }

    private function refreshCounters(Post $post, int $created): void
    {
        $counts = PostTarget::query()
            ->where('post_id', $post->id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $post->forceFill([
            'targets_total' => (int) $counts->sum(),
            'targets_pending' => (int) $counts->get(TargetStatus::Pending->value, 0),
            'targets_published' => (int) $counts->get(TargetStatus::Published->value, 0),
            'targets_failed' => (int) $counts->get(TargetStatus::Failed->value, 0),
            'status' => $created > 0 && $post->status === PostStatus::Draft
                ? PostStatus::Scheduled
                : $post->status,
        ])->save();
    }

    /** Cancel everything not yet sent. Already-published targets are left alone. */
    public function cancel(Post $post): int
    {
        return DB::transaction(function () use ($post): int {
            $cancelled = PostTarget::query()
                ->where('post_id', $post->id)
                // An upload can still be running when the user cancels or
                // deletes the post. Mark it too, so the publisher sees the
                // cancellation before sending the tweet.
                ->whereIn('status', [
                    TargetStatus::Pending->value,
                    TargetStatus::Queued->value,
                    TargetStatus::Publishing->value,
                ])
                ->update(['status' => TargetStatus::Cancelled->value, 'updated_at' => now()]);

            $post->forceFill([
                'status' => $post->targets_published > 0 ? PostStatus::PartiallyFailed : PostStatus::Cancelled,
                'targets_pending' => 0,
            ])->save();

            return $cancelled;
        });
    }
}
