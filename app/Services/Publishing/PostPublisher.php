<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\AccountStatus;
use App\Enums\TargetStatus;
use App\Exceptions\XApiException;
use App\Exceptions\XRateLimitException;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\Analytics\MetricsRecorder;
use App\Services\X\DTO\TweetPayload;
use App\Services\X\XApiClientFactory;
use App\Services\X\XAppSelector;
use App\Services\X\XMediaUploader;
use App\Services\X\XRateLimiter;
use Illuminate\Support\Facades\Log;

/**
 * Publishes exactly one target.
 *
 * Every outcome is one of three things, and the caller (the job) only needs to
 * distinguish them: Published, Deferred (retry later, with a delay), or Failed
 * (terminal). Nothing here throws for an expected condition -- a rate limit is
 * data, not an exception, by the time it leaves this class.
 */
final class PostPublisher
{
    public function __construct(
        private readonly XApiClientFactory $clients,
        private readonly XAppSelector $apps,
        private readonly XRateLimiter $limiter,
        private readonly XMediaUploader $media,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function publish(PostTarget $target): PublishOutcome
    {
        $target->loadMissing(['account.app', 'account.proxy', 'post']);
        $account = $target->account;

        if ($account === null) {
            return $this->fail($target, 'account_missing', 'The account was deleted after fan-out.');
        }

        if (! $account->status->isPublishable()) {
            return $this->fail($target, 'account_'.$account->status->value, 'Account is '.$account->status->value.'.');
        }

        if ($account->isCoolingDown()) {
            return $this->defer($target, (int) now()->diffInSeconds($account->cooldown_until, false), 'account_cooldown');
        }

        if (($wait = $this->limiter->reserveAccountSlot($account)) !== null) {
            return $this->defer($target, $wait, 'account_daily_cap');
        }

        /*
         * Try the account's own app first, then the rest of the pool.
         *
         * Without the loop an account whose app had spent its 24h allowance
         * waited for midnight even when a sibling app had thousands of slots
         * free -- which is how a 4,000-post day lost more than half its posts.
         */
        $burst = (int) config('xpost.publishing.app_burst_per_15min', 300);

        $app = null;
        $shortestWait = 900;
        $reason = 'no_app_capacity';

        foreach ($this->apps->candidatesFor($account) as $candidate) {
            $wait = $this->limiter->reserveAppSlot($candidate, $burst);

            if ($wait === null) {
                $app = $candidate;
                break;
            }

            $shortestWait = min($shortestWait, $wait);
            $reason = 'app_capacity';
        }

        if ($app === null) {
            // Hand the account's slot back; nothing was published with it.
            $this->limiter->releaseAccountSlot($account);

            return $this->defer($target, $shortestWait, $reason);
        }

        $startedAt = hrtime(true);

        try {
            $mediaIds = $this->resolveMedia($target, $account);

            $payload = new TweetPayload(
                text: $target->rendered_body,
                mediaIds: $mediaIds,
                replySettings: $target->post?->reply_settings,
                nullcast: (bool) $target->post?->nullcast || (bool) $target->post?->promoted_only,
            );

            $result = $this->clients->for($account)->createTweet($payload);

            $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

            $target->forceFill(['x_app_id' => $app->id])->save();
            $target->markPublished($result->id, $account->username, $durationMs);

            $this->onSuccess($account, $app);
            $this->metrics->recordPublish($target, $durationMs, true);

            return PublishOutcome::published($result->id);
        } catch (XRateLimitException $e) {
            $this->metrics->recordPublish($target, 0, false, 'rate_limited');

            return $this->defer($target, $e->retryAfterSeconds(), 'x_rate_limited');
        } catch (XApiException $e) {
            $this->metrics->recordPublish($target, 0, false, $e->errorCode);

            if ($e->requiresReauth()) {
                $this->markAccountNeedsReauth($account, $e->getMessage());

                return $this->fail($target, $e->errorCode, $e->getMessage());
            }

            if ($e->isRetryable() && $target->attempts < 5) {
                return $this->defer($target, $this->backoffSeconds($target->attempts), $e->errorCode);
            }

            $this->onFailure($account);

            return $this->fail($target, $e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            Log::channel('xpost')->error('Unexpected publish failure', [
                'target_id' => $target->id,
                'account_id' => $account->id,
                'exception' => $e->getMessage(),
            ]);

            $this->metrics->recordPublish($target, 0, false, 'internal_error');

            if ($target->attempts < 3) {
                return $this->defer($target, $this->backoffSeconds($target->attempts), 'internal_error');
            }

            return $this->fail($target, 'internal_error', $e->getMessage());
        }
    }

    /** @return list<string> */
    private function resolveMedia(PostTarget $target, XAccount $account): array
    {
        $ids = $target->rendered_media_ids ?? [];

        if ($ids === []) {
            return [];
        }

        return PostMedia::query()
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (PostMedia $media) => $this->media->ensureUploaded($media, $account))
            ->values()
            ->all();
    }

    private function defer(PostTarget $target, int $seconds, string $reason): PublishOutcome
    {
        $seconds = max(5, min($seconds, 86400));

        $target->forceFill([
            'status' => TargetStatus::Pending,
            'attempts' => $target->attempts + 1,
            'available_at' => now()->addSeconds($seconds),
            'claimed_at' => null,
            'claimed_by' => null,
            'error_code' => $reason,
        ])->save();

        return PublishOutcome::deferred($seconds, $reason);
    }

    private function fail(PostTarget $target, string $code, string $message): PublishOutcome
    {
        $target->forceFill(['attempts' => $target->attempts + 1])->save();
        $target->markFailed($code, $message);

        return PublishOutcome::failed($code, $message);
    }

    /** Exponential backoff with jitter, capped at an hour. */
    private function backoffSeconds(int $attempts): int
    {
        return min(3600, (2 ** max(0, $attempts)) * 30 + random_int(0, 30));
    }

    private function onSuccess(XAccount $account, XApp $app): void
    {
        $account->forceFill([
            'last_posted_at' => now(),
            'consecutive_failures' => 0,
            'health_score' => min(100, $account->health_score + 1),
            'last_error' => null,
        ])->save();

        $app->forceFill([
            'last_used_at' => now(),
            'monthly_posts_used' => $app->monthly_posts_used + 1,
        ])->save();
    }

    /** Repeated failures pull the account out of rotation before X does it for us. */
    private function onFailure(XAccount $account): void
    {
        $failures = $account->consecutive_failures + 1;

        $account->forceFill([
            'consecutive_failures' => $failures,
            'health_score' => max(0, $account->health_score - 5),
            'last_error_at' => now(),
            'cooldown_until' => $failures >= 3 ? now()->addMinutes(30 * $failures) : null,
            'status' => $failures >= 10 ? AccountStatus::Error : $account->status,
        ])->save();
    }

    private function markAccountNeedsReauth(XAccount $account, string $reason): void
    {
        $account->forceFill([
            'status' => AccountStatus::NeedsReauth,
            'last_error' => $reason,
            'last_error_at' => now(),
        ])->save();
    }
}
