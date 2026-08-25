<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * DDL for the analytics store, applied by `php artisan xpost:clickhouse-migrate`.
 *
 * Kept as plain SQL rather than a migration abstraction because the parts that
 * matter here -- ORDER BY keys, partitioning, TTL, the aggregating views -- have
 * no equivalent in Laravel's schema builder, and hiding them would hide the
 * only decisions in this file that affect query speed.
 */
final class ClickHouseSchema
{
    public static function statements(string $database): array
    {
        return [
            // One row per publish attempt. Partitioned by month, ordered so the
            // common filter (team, then time) is a prefix of the sort key.
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS xpost_publish_events (
                event_time   DateTime,
                team_id      UInt32,
                post_id      UInt64,
                target_id    UInt64,
                account_id   UInt64,
                app_id       UInt32,
                status       LowCardinality(String),
                error_code   LowCardinality(String),
                duration_ms  UInt32,
                tweet_id     String
            )
            ENGINE = MergeTree
            PARTITION BY toYYYYMM(event_time)
            ORDER BY (team_id, event_time, post_id)
            TTL event_time + INTERVAL 18 MONTH
            SETTINGS index_granularity = 8192
            SQL,

            // Engagement snapshots. ReplacingMergeTree keyed on the snapshot
            // hour so re-syncing the same window overwrites instead of double
            // counting.
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS xpost_tweet_metrics (
                snapshot_time       DateTime,
                team_id             UInt32,
                post_id             UInt64,
                account_id          UInt64,
                tweet_id            String,
                impressions         UInt64,
                likes               UInt32,
                retweets            UInt32,
                replies             UInt32,
                quotes              UInt32,
                bookmarks           UInt32,
                url_link_clicks     UInt32,
                user_profile_clicks UInt32,
                engagements         UInt32
            )
            ENGINE = ReplacingMergeTree(snapshot_time)
            PARTITION BY toYYYYMM(snapshot_time)
            ORDER BY (team_id, tweet_id, toStartOfHour(snapshot_time))
            TTL snapshot_time + INTERVAL 24 MONTH
            SQL,

            // Pre-aggregated daily rollup. The dashboard reads this, not the
            // raw events -- it is the difference between scanning a month of
            // rows and reading a few hundred.
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS xpost_daily_stats (
                day            Date,
                team_id        UInt32,
                post_id        UInt64,
                published      AggregateFunction(sum, UInt64),
                failed         AggregateFunction(sum, UInt64),
                avg_duration   AggregateFunction(avg, UInt32)
            )
            ENGINE = AggregatingMergeTree
            PARTITION BY toYYYYMM(day)
            ORDER BY (team_id, day, post_id)
            SQL,

            <<<'SQL'
            CREATE MATERIALIZED VIEW IF NOT EXISTS xpost_daily_stats_mv
            TO xpost_daily_stats
            AS SELECT
                toDate(event_time) AS day,
                team_id,
                post_id,
                sumState(toUInt64(status = 'published')) AS published,
                sumState(toUInt64(status = 'failed'))    AS failed,
                avgState(duration_ms)                    AS avg_duration
            FROM xpost_publish_events
            GROUP BY day, team_id, post_id
            SQL,
        ];
    }
}
