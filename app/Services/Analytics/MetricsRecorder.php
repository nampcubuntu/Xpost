<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\PostTarget;
use Illuminate\Support\Facades\Redis;

/**
 * Write path for analytics.
 *
 * Publish events are buffered in a Redis list and flushed to ClickHouse in
 * batches by the xpost:flush-metrics command. A single-row INSERT per publish
 * would create one ClickHouse part per row, and merges would never keep up at
 * 10k publishes a run.
 */
final class MetricsRecorder
{
    private const BUFFER_KEY = 'xpost:metrics:publish-buffer';

    public const PUBLISH_COLUMNS = [
        'event_time', 'team_id', 'post_id', 'target_id', 'account_id', 'app_id',
        'status', 'error_code', 'duration_ms', 'tweet_id',
    ];

    /** Pop up to ARGV[1] entries off the head of the list, atomically. */
    private const DRAIN = <<<'LUA'
        local out = {}
        local want = tonumber(ARGV[1])
        for _ = 1, want do
            local value = redis.call('LPOP', KEYS[1])
            if not value then break end
            out[#out + 1] = value
        end
        return out
    LUA;

    public function __construct(private readonly ClickHouseConnection $clickhouse) {}

    public function recordPublish(PostTarget $target, int $durationMs, bool $success, ?string $errorCode = null): void
    {
        if (! $this->clickhouse->enabled()) {
            return;
        }

        Redis::rpush(self::BUFFER_KEY, json_encode([
            'event_time' => now()->format('Y-m-d H:i:s'),
            'team_id' => $target->team_id,
            'post_id' => $target->post_id,
            'target_id' => $target->id,
            'account_id' => $target->x_account_id,
            'app_id' => $target->x_app_id ?? 0,
            'status' => $success ? 'published' : 'failed',
            'error_code' => $errorCode ?? '',
            'duration_ms' => $durationMs,
            'tweet_id' => (string) ($target->x_tweet_id ?? ''),
        ]));
    }

    /**
     * Drain the buffer into ClickHouse.
     *
     * @return int rows flushed
     */
    public function flush(int $max = 5000): int
    {
        if (! $this->clickhouse->enabled()) {
            return 0;
        }

        $rows = [];

        /*
         * Drain up to $max entries in one atomic step, so two schedulers
         * overlapping cannot hand the same rows to ClickHouse twice.
         *
         * Done in Lua rather than with `LPOP key count`: predis does not forward
         * the count argument and Redis rejects the call with "wrong number of
         * arguments", which only shows up once the app is pointed at a real
         * Redis rather than the array cache used in tests.
         */
        $payloads = Redis::eval(self::DRAIN, 1, self::BUFFER_KEY, $max) ?: [];

        foreach ((array) $payloads as $payload) {
            $decoded = json_decode((string) $payload, true);

            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        if ($rows === []) {
            return 0;
        }

        $this->clickhouse->insert('xpost_publish_events', $rows, self::PUBLISH_COLUMNS);

        return count($rows);
    }

    public function bufferDepth(): int
    {
        return (int) Redis::llen(self::BUFFER_KEY);
    }

    public const SNAPSHOT_COLUMNS = [
        'snapshot_time', 'team_id', 'post_id', 'account_id', 'tweet_id',
        'impressions', 'likes', 'retweets', 'replies', 'quotes', 'bookmarks',
        'url_link_clicks', 'user_profile_clicks', 'engagements',
    ];

    /**
     * Metric snapshots go straight in: the sync job already batches up to 100
     * tweets per API call, so the write is naturally chunky.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function recordSnapshots(array $rows): void
    {
        $this->clickhouse->insert('xpost_tweet_metrics', $rows, self::SNAPSHOT_COLUMNS);
    }
}
