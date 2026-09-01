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
use App\Services\X\XCardService;
use App\Services\X\XMediaUploader;
use App\Services\X\XRateLimiter;
use App\Support\ProxyFailure;
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
    /** @var array<int, PostMedia|null> keyed by target id */
    private array $mediaCache = [];

    /** What refused the last card build, when something did. */
    private ?string $cardError = null;

    public function __construct(
        private readonly XApiClientFactory $clients,
        private readonly XAppSelector $apps,
        private readonly XRateLimiter $limiter,
        private readonly XMediaUploader $media,
        private readonly MetricsRecorder $metrics,
        private readonly XCardService $cards,
        private readonly PublishingHalt $halt,
        private readonly SpendGuard $spend,
    ) {}

    public function publish(PostTarget $target): PublishOutcome
    {
        // The instance is reused across targets in a worker; a reason left over
        // from the last one would be reported against this one.
        $this->cardError = null;

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

        /*
         * The team's own ceiling, reserved before anything is spent.
         *
         * Checked here rather than at fan-out because that is where the money
         * is actually committed: a post fanned out this morning is only charged
         * when it goes out, and a budget that counted intentions would stop
         * campaigns that never cost anything.
         */
        $costCents = $this->spend->costCentsFor($target);

        if (! $this->spend->reserve((int) $target->team_id, $costCents)) {
            $this->limiter->releaseAccountSlot($account);
            $this->limiter->releaseAppSlot($app);

            // Deferred, not failed: tomorrow's budget publishes it unchanged.
            return $this->defer($target, $this->secondsUntilMidnight(), 'team_budget_spent');
        }

        $startedAt = hrtime(true);
        $published = false;

        try {
            $cardUri = $this->resolveCard($target, $account);

            /*
             * Under card_only a post that wanted a card and did not get one is
             * a failure, not something to publish a lesser version of. The
             * whole point of the mode is that one campaign looks the same
             * wherever it appears, and a silent downgrade would break exactly
             * that -- invisibly, on whichever accounts happen to lack an ads
             * account.
             */
            if ($cardUri === null && $this->cardRequiredFor($target)) {
                $this->limiter->releaseAccountSlot($account);
                $this->limiter->releaseAppSlot($app);

                return $this->fail($target, 'card_unavailable', $this->cardFailureReason($account));
            }

            // A card supplies its own media; uploading it twice would cost an
            // extra transcode per account for a file the post never references.
            $mediaIds = $cardUri === null ? $this->resolveMedia($target, $account) : [];

            $payload = new TweetPayload(
                text: (string) $target->rendered_body,
                mediaIds: $mediaIds,
                replySettings: $target->post?->reply_settings,
                // `nullcast` is what X calls it; `promoted_only` is what the
                // advertiser-facing UI calls it. One column, one meaning.
                nullcast: (bool) $target->post?->promoted_only,
                // The payload decides where these land: a call to action on the
                // media when there is media, appended to the text when there is not.
                websiteUrl: $target->rendered_url,
                title: $target->rendered_headline,
                mediaCta: (string) config('xpost.publishing.media_cta', 'visit_site'),
                cardUri: $cardUri,
            );

            $result = $this->clients->for($account)->createTweet($payload);

            // Past this line X has charged for the post, whatever happens next.
            $published = true;

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

            /*
             * No money, not a bad post. Every account is refused at once, so
             * the queue stops rather than the target failing -- deferring keeps
             * the campaign intact, and a top-up resumes it with nothing lost.
             */
            if ($e->isOutOfCredit()) {
                $this->limiter->releaseAccountSlot($account);
                $this->limiter->releaseAppSlot($app);
                $this->halt->engage((int) $target->team_id, $e->getMessage());

                return $this->defer($target, 900, 'out_of_credit');
            }

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

            /*
             * Named for the hop that failed, when it can be.
             *
             * This catch is where anything unforeseen lands, and "HTTP request
             * returned status code 407" landed here -- a proxy's refusal filed
             * as an internal error, with nothing saying which proxy, or that X
             * had never seen the request.
             */
            $explained = ProxyFailure::fromThrowable($e, $account->proxy);

            return $this->fail(
                $target,
                $explained === null ? 'internal_error' : 'proxy_rejected',
                $explained ?? $e->getMessage()
            );
        } finally {
            /*
             * Refund unless the post actually went out.
             *
             * Seven paths leave this method without publishing -- a refused
             * card, a rate limit, a spent balance, a reauth, a retry, a
             * terminal failure, an unexpected throw -- and every one of them
             * costs X nothing. Refunding at each site would work until someone
             * adds an eighth, at which point the budget quietly drifts down
             * until a team is stopped by money it never spent.
             */
            if (! $published) {
                $this->spend->release((int) $target->team_id, $costCents);
            }
        }
    }

    /** A budget is spent for the day, so retrying before then is pointless. */
    private function secondsUntilMidnight(): int
    {
        return max(60, (int) now()->diffInSeconds(now()->endOfDay(), false) + 1);
    }

    /**
     * A video_website card, when this account can build one and the post wants
     * a destination behind its video.
     *
     * The card is what produces the tappable "From <domain>" row under a video
     * -- the format X Ads Manager makes -- while the post itself still goes out
     * through POST /2/tweets and so stays on the timeline.
     *
     * Deliberately best-effort: a card needs the Ads API, an ads account and
     * OAuth 1.0a, and if any of that is missing the post should still publish
     * the ordinary way rather than fail. The fallback is a call to action,
     * which is a button instead of a caption -- worse looking, not broken.
     */
    private function resolveCard(PostTarget $target, XAccount $account): ?string
    {
        /*
         * A card this target already paid for.
         *
         * publish() runs again on every retry, and the card is minted before
         * the call to X -- so a target deferred by a rate limit used to mint a
         * fresh card on each attempt and abandon the last one in the ads
         * account. The content behind the card is frozen at fan-out, so the
         * card stays valid however many attempts it takes.
         */
        if (filled($target->card_uri)) {
            return (string) $target->card_uri;
        }

        $url = trim((string) $target->rendered_url);

        if ($url === '' || ! $this->cards->isAvailableFor($account)) {
            return null;
        }

        $media = $this->cardMedia($target);

        if ($media === null) {
            return null;
        }

        try {
            $cardUri = $this->cards->videoWebsiteCard($account, $media, $url, $target->rendered_headline);

            $target->forceFill(['card_uri' => $cardUri])->save();

            return $cardUri;
        } catch (\Throwable $e) {
            // Through the explainer first: a card is minted seconds before the
            // post that carries it and over the same proxy, so this is one of
            // the likeliest places for a proxy's refusal to surface.
            $this->cardError = ProxyFailure::fromThrowable($e, $account->proxy) ?? $e->getMessage();

            Log::channel('xpost')->warning('Card build failed, publishing without one', [
                'target_id' => $target->id,
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Why the card never happened, in the words of whatever refused it.
     *
     * Worth the extra branch: the message reaches the failed column, and one
     * that blames a missing ads account for a video X could not transcode
     * sends whoever reads it to the wrong place entirely. That is not
     * hypothetical -- it happened, against an account that had every
     * credential the message said it lacked.
     */
    private function cardFailureReason(XAccount $account): string
    {
        if ($this->cardError !== null) {
            return 'Không tạo được card cho '.$account->handle().': '.$this->cardError;
        }

        return 'Bài này cấu hình chỉ đăng dạng card, nhưng '.$account->handle()
            .' chưa có Ads account + OAuth 1.0a nên không tạo được card.';
    }

    /**
     * The video a card would be built from, or null when there is not one.
     *
     * Both callers need the same lookup, and asking twice per target is two
     * queries for a value that cannot change: the media ids were frozen at
     * fan-out.
     */
    private function cardMedia(PostTarget $target): ?PostMedia
    {
        if (array_key_exists($target->id, $this->mediaCache)) {
            return $this->mediaCache[$target->id];
        }

        $mediaIds = $target->rendered_media_ids ?? [];
        $media = $mediaIds === [] ? null : PostMedia::query()->find($mediaIds[0]);

        // Cards are a video format; an image post has nothing to gain here.
        if ($media !== null && ! str_starts_with((string) $media->mime, 'video/')) {
            $media = null;
        }

        return $this->mediaCache[$target->id] = $media;
    }

    /**
     * Whether this target had to produce a card.
     *
     * Only posts that actually wanted one: a video plus a destination. A post
     * with no media has nothing to build a card from, and refusing to publish
     * a plain link post would help nobody.
     */
    private function cardRequiredFor(PostTarget $target): bool
    {
        if (config('xpost.publishing.destination_render') !== 'card_only') {
            return false;
        }

        if (trim((string) $target->rendered_url) === '') {
            return false;
        }

        return $this->cardMedia($target) !== null;
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
