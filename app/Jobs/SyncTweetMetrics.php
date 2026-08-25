<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TargetStatus;
use App\Exceptions\XApiException;
use App\Models\PostTarget;
use App\Models\XAccount;
use App\Services\Analytics\MetricsRecorder;
use App\Services\X\XApiClientFactory;
use App\Support\TeamContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Pulls engagement numbers for tweets published by one account.
 *
 * Batched per account rather than per tweet: GET /2/tweets takes up to 100 ids
 * per call, and non-public metrics are only returned to the account that owns
 * the tweet, so the account is the natural unit of work.
 */
class SyncTweetMetrics implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $accountId,
        public readonly int $teamId,
        public readonly int $lookbackHours = 72,
    ) {
        $this->onQueue('metrics');
    }

    public function handle(XApiClientFactory $clients, MetricsRecorder $recorder): void
    {
        TeamContext::runAs($this->teamId, function () use ($clients, $recorder): void {
            $account = XAccount::withoutGlobalScopes()->find($this->accountId);

            if ($account === null || ! $account->status->isPublishable()) {
                return;
            }

            $targets = PostTarget::withoutGlobalScopes()
                ->where('x_account_id', $account->id)
                ->where('status', TargetStatus::Published->value)
                ->whereNotNull('x_tweet_id')
                ->where('published_at', '>=', now()->subHours($this->lookbackHours))
                ->get(['id', 'post_id', 'x_tweet_id'])
                ->keyBy('x_tweet_id');

            if ($targets->isEmpty()) {
                return;
            }

            foreach ($targets->keys()->chunk(100) as $ids) {
                try {
                    $tweets = $clients->for($account)->tweetMetrics($ids->all());
                } catch (XApiException) {
                    // A metrics gap is not worth failing the job over; the next
                    // scheduled run will pick the same window up again.
                    continue;
                }

                $recorder->recordSnapshots($this->toRows($tweets, $targets, $account));
            }

            $account->forceFill(['last_synced_at' => now()])->save();
        });
    }

    /** @param  Collection<string, PostTarget>  $targets */
    private function toRows(array $tweets, Collection $targets, XAccount $account): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($tweets as $tweet) {
            $tweetId = (string) ($tweet['id'] ?? '');
            $target = $targets->get($tweetId);

            if ($target === null) {
                continue;
            }

            $public = $tweet['public_metrics'] ?? [];
            $nonPublic = $tweet['non_public_metrics'] ?? [];
            $organic = $tweet['organic_metrics'] ?? [];

            $impressions = (int) ($nonPublic['impression_count'] ?? $organic['impression_count'] ?? 0);

            $engagements = (int) ($public['like_count'] ?? 0)
                + (int) ($public['retweet_count'] ?? 0)
                + (int) ($public['reply_count'] ?? 0)
                + (int) ($public['quote_count'] ?? 0)
                + (int) ($nonPublic['url_link_clicks'] ?? 0)
                + (int) ($nonPublic['user_profile_clicks'] ?? 0);

            $rows[] = [
                'snapshot_time' => $now,
                'team_id' => $this->teamId,
                'post_id' => $target->post_id,
                'account_id' => $account->id,
                'tweet_id' => $tweetId,
                'impressions' => $impressions,
                'likes' => (int) ($public['like_count'] ?? 0),
                'retweets' => (int) ($public['retweet_count'] ?? 0),
                'replies' => (int) ($public['reply_count'] ?? 0),
                'quotes' => (int) ($public['quote_count'] ?? 0),
                'bookmarks' => (int) ($public['bookmark_count'] ?? 0),
                'url_link_clicks' => (int) ($nonPublic['url_link_clicks'] ?? 0),
                'user_profile_clicks' => (int) ($nonPublic['user_profile_clicks'] ?? 0),
                'engagements' => $engagements,
            ];
        }

        return $rows;
    }
}
