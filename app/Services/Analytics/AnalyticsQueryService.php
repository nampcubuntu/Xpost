<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\TargetStatus;
use App\Models\PostTarget;
use App\Support\TeamContext;
use Illuminate\Support\Carbon;

/**
 * Read path for the dashboard.
 *
 * ClickHouse answers the time-series questions; MySQL answers "what is the
 * state of things right now". When ClickHouse is off, the time-series calls
 * fall back to MySQL aggregates so the screens still render -- slower, but
 * never blank.
 */
final class AnalyticsQueryService
{
    public function __construct(private readonly ClickHouseConnection $clickhouse) {}

    /**
     * Publish volume per day, counted in destinations rather than attempts.
     *
     * The daily rollup sums one row per publish attempt, so a target that
     * failed twice and then landed contributes three. The tile beside this
     * chart counts post_targets, which is one row per destination -- and the
     * two read the same word on the same screen while disagreeing: fifty
     * against twenty-eight on this install.
     *
     * Counting distinct target_id makes the chart mean what the tile means.
     * It reads the raw events rather than the rollup because the rollup
     * stores sums, and a sum of attempts cannot be turned back into a count
     * of destinations. Attempts are still returned, separately and named as
     * what they are.
     */
    public function dailyVolume(Carbon $from, Carbon $to, ?int $postId = null): array
    {
        $teamId = TeamContext::id() ?? 0;

        if (! $this->clickhouse->enabled()) {
            return $this->dailyVolumeFromMysql($from, $to, $postId);
        }

        $filter = $postId ? 'AND post_id = :post_id' : '';

        return $this->clickhouse->select(<<<SQL
            SELECT
                toDate(event_time)                              AS day,
                uniqExactIf(target_id, status = 'published')    AS published,
                uniqExactIf(target_id, status = 'failed')       AS failed,
                count()                                         AS attempts,
                round(avg(duration_ms))                         AS avg_duration_ms
            FROM xpost_publish_events
            WHERE team_id = :team_id
              AND event_time BETWEEN :from AND :to
              {$filter}
            GROUP BY day
            ORDER BY day
        SQL, array_filter([
            'team_id' => $teamId,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'post_id' => $postId,
        ], static fn ($v) => $v !== null));
    }

    /** Top failure reasons, so an operator can see what is actually breaking. */
    public function failureBreakdown(Carbon $from, Carbon $to, int $limit = 10): array
    {
        $teamId = TeamContext::id() ?? 0;

        if (! $this->clickhouse->enabled()) {
            return PostTarget::query()
                ->where('status', TargetStatus::Failed->value)
                ->whereBetween('updated_at', [$from, $to])
                ->selectRaw('error_code, COUNT(*) as total')
                ->groupBy('error_code')
                ->orderByDesc('total')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => ['error_code' => $row->error_code, 'total' => (int) $row->total])
                ->all();
        }

        return $this->clickhouse->select(<<<'SQL'
            SELECT error_code, count() AS total
            FROM xpost_publish_events
            WHERE team_id = :team_id
              AND status = 'failed'
              AND event_time BETWEEN :from AND :to
            GROUP BY error_code
            ORDER BY total DESC
            LIMIT :limit
        SQL, [
            'team_id' => $teamId,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'limit' => $limit,
        ]);
    }

    /** Engagement for one post, summed across every account that carried it. */
    public function postEngagement(int $postId): array
    {
        if (! $this->clickhouse->enabled()) {
            return [];
        }

        $rows = $this->clickhouse->select(<<<'SQL'
            SELECT
                sum(impressions)         AS impressions,
                sum(likes)               AS likes,
                sum(retweets)            AS retweets,
                sum(replies)             AS replies,
                sum(quotes)              AS quotes,
                sum(bookmarks)           AS bookmarks,
                sum(url_link_clicks)     AS url_link_clicks,
                sum(user_profile_clicks) AS user_profile_clicks,
                sum(engagements)         AS engagements
            FROM (
                SELECT * FROM xpost_tweet_metrics
                WHERE team_id = :team_id AND post_id = :post_id
                ORDER BY snapshot_time DESC
                LIMIT 1 BY tweet_id
            )
        SQL, ['team_id' => TeamContext::id() ?? 0, 'post_id' => $postId]);

        return $rows[0] ?? [];
    }

    /**
     * Which accounts actually perform.
     *
     * Every figure here is read off the latest snapshot per tweet, never
     * summed across snapshots. The counters X returns are cumulative, and
     * this table is written hourly -- so adding the rows up counts the same
     * impression once per hour the post has existed. Measured on this
     * install: five tweets, ninety-two snapshots, sixteen impressions
     * reported as a hundred and seventy-five.
     */
    /*
     * The rate is computed one level out on purpose. `sum(x) AS x` shadows
     * the column, so an `if()` beside it reading `x` gets the alias and
     * ClickHouse refuses the query as an aggregate inside an aggregate --
     * which select() then swallowed, leaving an empty table and no reason.
     */
    public function topAccounts(Carbon $from, Carbon $to, int $limit = 20): array
    {
        if (! $this->clickhouse->enabled()) {
            return [];
        }

        return $this->clickhouse->select(<<<'SQL'
            SELECT
                account_id, posts, impressions, engagements, likes, profile_visits,
                if(impressions = 0, 0, round(engagements / impressions * 100, 2)) AS engagement_rate
            FROM (
                SELECT
                    account_id,
                    count()                  AS posts,
                    sum(impressions)         AS impressions,
                    sum(engagements)         AS engagements,
                    sum(likes)               AS likes,
                    sum(user_profile_clicks) AS profile_visits
                FROM (SELECT * FROM xpost_tweet_metrics
                      WHERE team_id = :team_id AND snapshot_time BETWEEN :from AND :to
                      ORDER BY snapshot_time DESC LIMIT 1 BY tweet_id)
                GROUP BY account_id
            )
            ORDER BY engagements DESC, impressions DESC
            LIMIT :limit
        SQL, [
            'team_id' => TeamContext::id() ?? 0,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'limit' => $limit,
        ]);
    }

    /**
     * The headline engagement figures, over whatever had metrics in the range.
     *
     * "Had metrics in the range" rather than "was published in it": the
     * counters X returns are running totals for the life of the post, so there
     * is no honest way to slice them into what a given day earned. The page has
     * to say which of the two it is showing rather than let a reader assume.
     */
    public function engagementTotals(Carbon $from, Carbon $to): array
    {
        if (! $this->clickhouse->enabled()) {
            return [];
        }

        $rows = $this->clickhouse->select(<<<'SQL'
            SELECT
                posts, impressions, engagements, likes, retweets, replies,
                quotes, bookmarks, profile_visits,
                if(impressions = 0, 0, round(engagements / impressions * 100, 2)) AS engagement_rate
            FROM (
                SELECT
                    count()                  AS posts,
                    sum(impressions)         AS impressions,
                    sum(engagements)         AS engagements,
                    sum(likes)               AS likes,
                    sum(retweets)            AS retweets,
                    sum(replies)             AS replies,
                    sum(quotes)              AS quotes,
                    sum(bookmarks)           AS bookmarks,
                    sum(user_profile_clicks) AS profile_visits
                FROM (SELECT * FROM xpost_tweet_metrics
                      WHERE team_id = :team_id AND snapshot_time BETWEEN :from AND :to
                      ORDER BY snapshot_time DESC LIMIT 1 BY tweet_id)
            )
        SQL, [
            'team_id' => TeamContext::id() ?? 0,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
        ]);

        return $rows[0] ?? [];
    }

    /**
     * Impressions and engagements earned per day, not carried into it.
     *
     * The counters X returns are running totals for the life of a post, so
     * plotting them straight gives a line that only ever climbs -- a shape that
     * says "we grew" on a week when nothing happened. What a reader wants, and
     * what X Premium's own chart shows, is the day's own gain.
     *
     * So: the highest reading each tweet had on each day, minus the reading it
     * had the day before. Clamped at zero, because X does revise counts down
     * and a negative day would read as impressions being taken away.
     *
     * The window opens a week before the range. Metrics are only collected for
     * the 72 hours after a post goes out, so a week is wide enough for every
     * tweet still being measured to have its earlier readings in view -- without
     * which its first day inside the range would show its whole life's total as
     * though it had all arrived at once.
     */
    public function dailyEngagement(Carbon $from, Carbon $to): array
    {
        if (! $this->clickhouse->enabled()) {
            return [];
        }

        return $this->clickhouse->select(<<<'SQL'
            SELECT
                day,
                sum(greatest(0, impressions_gain)) AS impressions,
                sum(greatest(0, engagements_gain)) AS engagements
            FROM (
                SELECT
                    day,
                    impressions - lagInFrame(impressions, 1, 0) OVER w AS impressions_gain,
                    engagements - lagInFrame(engagements, 1, 0) OVER w AS engagements_gain
                FROM (
                    SELECT
                        tweet_id,
                        toDate(snapshot_time) AS day,
                        max(impressions)      AS impressions,
                        max(engagements)      AS engagements
                    FROM xpost_tweet_metrics
                    WHERE team_id = :team_id AND snapshot_time >= :lookback
                    GROUP BY tweet_id, day
                )
                WINDOW w AS (PARTITION BY tweet_id ORDER BY day
                             ROWS BETWEEN 1 PRECEDING AND CURRENT ROW)
            )
            WHERE day BETWEEN :from AND :to
            GROUP BY day
            ORDER BY day
        SQL, [
            'team_id' => TeamContext::id() ?? 0,
            'lookback' => $from->copy()->subDays(7)->toDateTimeString(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    /**
     * One row per published tweet, which is the view X Premium actually shows.
     *
     * Per tweet rather than per post: a post fans out to many accounts, and the
     * whole reason to look at this screen is that the same text does not
     * perform the same from every handle.
     */
    public function topPosts(Carbon $from, Carbon $to, int $limit = 25): array
    {
        if (! $this->clickhouse->enabled()) {
            return [];
        }

        return $this->clickhouse->select(<<<'SQL'
            SELECT
                tweet_id,
                post_id,
                account_id,
                impressions,
                engagements,
                likes,
                retweets,
                replies,
                quotes,
                bookmarks,
                user_profile_clicks AS profile_visits,
                if(impressions = 0, 0,
                   round(engagements / impressions * 100, 2)) AS engagement_rate,
                snapshot_time AS measured_at
            FROM (SELECT * FROM xpost_tweet_metrics
                  WHERE team_id = :team_id AND snapshot_time BETWEEN :from AND :to
                  ORDER BY snapshot_time DESC LIMIT 1 BY tweet_id)
            ORDER BY impressions DESC, engagements DESC
            LIMIT :limit
        SQL, [
            'team_id' => TeamContext::id() ?? 0,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'limit' => $limit,
        ]);
    }

    private function dailyVolumeFromMysql(Carbon $from, Carbon $to, ?int $postId): array
    {
        return PostTarget::query()
            ->whereBetween('updated_at', [$from, $to])
            ->when($postId, fn ($q, $id) => $q->where('post_id', $id))
            ->selectRaw('DATE(updated_at) as day')
            ->selectRaw("SUM(status = 'published') as published")
            ->selectRaw("SUM(status = 'failed') as failed")
            ->selectRaw('ROUND(AVG(duration_ms)) as avg_duration_ms')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(static fn ($row): array => [
                'day' => (string) $row->day,
                'published' => (int) $row->published,
                'failed' => (int) $row->failed,
                'avg_duration_ms' => (int) $row->avg_duration_ms,
            ])
            ->all();
    }
}
