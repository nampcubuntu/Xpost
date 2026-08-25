<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\PostStatus;
use App\Enums\ScheduleMode;
use App\Jobs\FanOutPost;
use App\Models\Post;
use App\Models\PostVariant;
use Illuminate\Support\Facades\DB;

/**
 * Turns validated composer input into a Post, and schedules it.
 *
 * Kept out of the controller because the same operation is driven from three
 * places: the SPA, the REST API used by automation, and the console.
 */
final class ComposerService
{
    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly SchedulePlanner $planner,
    ) {}

    public function create(array $data, int $userId): Post
    {
        return DB::transaction(function () use ($data, $userId): Post {
            $post = Post::create($this->attributes($data) + ['created_by' => $userId]);

            $this->syncVariants($post, $data['variants'] ?? []);
            $this->syncMedia($post, $data['media_ids'] ?? []);

            if (($data['status'] ?? PostStatus::Draft->value) === PostStatus::Scheduled->value) {
                $this->schedule($post);
            }

            return $post->fresh(['variants', 'media', 'campaign']);
        });
    }

    public function update(Post $post, array $data): Post
    {
        return DB::transaction(function () use ($post, $data): Post {
            $post->fill($this->attributes($data))->save();

            if (array_key_exists('variants', $data)) {
                $this->syncVariants($post, $data['variants']);
            }

            if (array_key_exists('media_ids', $data)) {
                $this->syncMedia($post, $data['media_ids']);
            }

            return $post->fresh(['variants', 'media', 'campaign']);
        });
    }

    /**
     * Move a draft to scheduled and kick off fan-out.
     *
     * Fan-out is queued rather than inline so the caller gets an answer in
     * milliseconds regardless of whether the audience is 10 accounts or 10,000.
     */
    public function schedule(Post $post): Post
    {
        $post->forceFill([
            'status' => PostStatus::Scheduled,
            'scheduled_at' => $post->schedule_mode === ScheduleMode::Immediate
                ? now()
                : $post->scheduled_at,
        ])->save();

        FanOutPost::dispatch($post->id, $post->team_id);

        return $post;
    }

    /**
     * What the composer shows before anyone commits: how many accounts this
     * will hit, and when the last one goes out.
     *
     * @return array{accounts:int, first_at:string, last_at:string, duration_seconds:int, permutations:int}
     */
    public function preview(Post $post): array
    {
        $count = $this->audience->count($post);
        $window = $this->planner->summarise($post, max(1, $count));

        return [
            'accounts' => $count,
            'first_at' => $window['first']->toIso8601String(),
            'last_at' => $window['last']->toIso8601String(),
            'duration_seconds' => $window['duration_seconds'],
            'permutations' => (new SpintaxRenderer)->permutationCount((string) $post->body),
        ];
    }

    private function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'body', 'campaign_id', 'reply_settings', 'destination_type',
            'website_url', 'headline', 'app_store_url', 'app_country_code',
            'is_ai_generated', 'promoted_only', 'variant_strategy',
            'schedule_mode', 'timezone', 'scheduled_at', 'window_ends_at',
            'jitter_seconds', 'per_minute_cap', 'audience',
        ])) + [
            // "Promoted only" is what nullcast means on the wire; keeping both
            // columns lets the UI stay in the advertiser's vocabulary.
            'nullcast' => (bool) ($data['promoted_only'] ?? false),
        ];
    }

    private function syncVariants(Post $post, array $variants): void
    {
        $post->variants()->delete();

        if ($variants === []) {
            return;
        }

        $now = now();

        PostVariant::insert(array_map(static fn (array $variant): array => [
            'post_id' => $post->id,
            'body' => $variant['body'],
            'headline' => $variant['headline'] ?? null,
            'website_url' => $variant['website_url'] ?? null,
            'media_ids' => json_encode($variant['media_ids'] ?? null),
            'weight' => $variant['weight'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $variants));
    }

    private function syncMedia(Post $post, array $mediaIds): void
    {
        $post->media()->sync(
            collect($mediaIds)
                ->values()
                ->mapWithKeys(static fn ($id, $position) => [(int) $id => ['position' => $position]])
                ->all()
        );
    }
}
