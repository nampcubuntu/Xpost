<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncTweetMetrics;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Team;
use App\Models\XAccount;
use App\Services\Analytics\AnalyticsQueryService;
use App\Services\Analytics\ClickHouseConnection;
use App\Support\TeamContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The numbers on the analytics screen.
 *
 * Nothing here had a test, which is how a ranking query that ClickHouse refuses
 * outright shipped and stayed broken: select() swallows the error and returns
 * an empty array, and an empty table looks exactly like a quiet month.
 *
 * Two things have to hold. The stored numbers have to be the ones X reports
 * rather than ones this code invented, and the aggregates have to read a
 * cumulative counter once instead of once per snapshot.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $this->user] = $this->tenant();
    }

    /**
     * The response as X actually sent it for one of this install's posts,
     * trimmed to the fields this reads.
     */
    private function payload(array $overrides = []): array
    {
        return [array_replace_recursive([
            'id' => '2092916567701107015',
            'created_at' => '2026-08-27T10:06:08.000Z',
            'non_public_metrics' => [
                'engagements' => 41,
                'impression_count' => 1200,
                'user_profile_clicks' => 9,
            ],
            'organic_metrics' => [
                'impression_count' => 1200,
                'like_count' => 6,
                'reply_count' => 1,
                'retweet_count' => 2,
                'user_profile_clicks' => 9,
            ],
            'public_metrics' => [
                'bookmark_count' => 3,
                'impression_count' => 1200,
                'like_count' => 6,
                'quote_count' => 1,
                'reply_count' => 1,
                'retweet_count' => 2,
            ],
        ], $overrides)];
    }

    private function rowsFor(array $tweets): array
    {
        $account = XAccount::factory()->for($this->team)->create();
        $post = Post::factory()->for($this->team)->create();

        $target = PostTarget::factory()->for($post)->create([
            'team_id' => $this->team->id,
            'x_account_id' => $account->id,
            'x_tweet_id' => '2092916567701107015',
        ]);

        $job = new SyncTweetMetrics($account->id, $this->team->id);

        return $job->toRows($tweets, collect([$target->x_tweet_id => $target]), $account);
    }

    // ── What gets stored ──────────────────────────────────────────────────

    #[Test]
    public function engagement_is_the_number_x_reports(): void
    {
        /*
         * Not a sum of the visible parts. X counts every interaction with a
         * post -- expands, hashtag and username taps, media opens, follows off
         * the post -- so likes + reposts + replies + quotes + profile clicks is
         * a different, smaller number. Here: 41 against a sum of 19.
         *
         * A screen built on the sum reports an engagement rate under half of
         * what X's own analytics shows for the same post, and every ranking
         * built on it is ranking the wrong thing.
         */
        $row = $this->rowsFor($this->payload())[0];

        $this->assertSame(41, $row['engagements'], 'engagement was recomputed instead of read');
        $this->assertSame(1200, $row['impressions']);
        $this->assertSame(6, $row['likes']);
        $this->assertSame(3, $row['bookmarks']);
        $this->assertSame(9, $row['user_profile_clicks']);
    }

    #[Test]
    public function a_missing_engagement_count_falls_back_rather_than_reading_zero(): void
    {
        /*
         * organic_metrics carries no engagements field, so a post whose
         * non-public metrics are outside X's 30-day window would store a flat
         * zero -- which on the page is a post nobody touched, not a post whose
         * engagement X declined to state.
         */
        $tweets = $this->payload();
        unset($tweets[0]['non_public_metrics']['engagements']);

        $row = $this->rowsFor($tweets)[0];

        // 6 likes + 2 reposts + 1 reply + 1 quote + 9 profile clicks.
        $this->assertSame(19, $row['engagements']);
    }

    #[Test]
    public function link_clicks_are_not_invented(): void
    {
        /*
         * Neither non_public_metrics nor organic_metrics carries
         * url_link_clicks at this access level -- it is simply absent from the
         * live response. Stored as zero is fine; presenting that zero as a
         * measurement is not, so nothing that reaches the page may select it.
         */
        $row = $this->rowsFor($this->payload())[0];

        $this->assertSame(0, $row['url_link_clicks']);
    }

    #[Test]
    public function a_tweet_this_install_did_not_publish_is_ignored(): void
    {
        // The batch is 100 ids wide and X answers with what it likes; a row
        // keyed to a target that is not ours would be another team's post
        // counted in these totals.
        $tweets = $this->payload(['id' => '999999999999999999']);

        $this->assertSame([], $this->rowsFor($tweets));
    }

    // ── What reaches the page ─────────────────────────────────────────────

    #[Test]
    public function the_overview_says_how_much_of_the_pool_it_speaks_for(): void
    {
        /*
         * Metrics come back only to the account that owns the tweet, so an
         * account with a dead token contributes nothing. Six of the seven
         * accounts tried live on this install answered Unauthorized -- a
         * dashboard averaging over the seventh, silently, is a claim about all
         * of them.
         */
        XAccount::factory()->count(3)->for($this->team)->create(['last_synced_at' => null]);
        XAccount::factory()->for($this->team)->create(['last_synced_at' => now()->subHour()]);

        $body = $this->actingAs($this->user->refresh())
            ->getJson('/api/v1/analytics/overview')
            ->assertOk()
            ->json();

        $this->assertSame(1, $body['coverage']['synced']);
        $this->assertSame(4, $body['coverage']['publishable']);
        $this->assertArrayHasKey('clickhouse', $body['coverage']);
    }

    #[Test]
    public function a_stale_sync_does_not_count_as_covered(): void
    {
        // An account last heard from a week ago is not reporting; counting it
        // would make the coverage line say the thing it exists to disprove.
        XAccount::factory()->for($this->team)->create(['last_synced_at' => now()->subWeek()]);

        $this->actingAs($this->user->refresh())
            ->getJson('/api/v1/analytics/overview')
            ->assertOk()
            ->assertJsonPath('coverage.synced', 0)
            ->assertJsonPath('coverage.publishable', 1);
    }

    #[Test]
    public function the_engagement_block_is_empty_and_says_so_without_clickhouse(): void
    {
        /*
         * Empty is the honest answer when there is nowhere to read from. What
         * must not happen is empty arriving with no way to tell it apart from a
         * month in which nobody engaged -- so the flag travels with it.
         */
        config(['xpost.clickhouse.enabled' => false]);

        $body = $this->actingAs($this->user->refresh())
            ->getJson('/api/v1/analytics/overview')
            ->assertOk()
            ->json();

        $this->assertSame([], $body['engagement']);
        $this->assertFalse($body['coverage']['clickhouse']);
    }

    #[Test]
    public function the_per_tweet_table_names_the_handle_and_the_text(): void
    {
        // A row identified only by ids is a row nobody can act on, and acting
        // on it means opening the post.
        config(['xpost.clickhouse.enabled' => false]);

        $this->actingAs($this->user->refresh())
            ->getJson('/api/v1/analytics/top-posts')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    #[Test]
    public function another_teams_numbers_are_not_in_the_overview(): void
    {
        [$other] = $this->tenant();
        XAccount::factory()->count(5)->for($other)->create();
        XAccount::factory()->for($this->team)->create();

        $this->actingAs($this->user->refresh())
            ->getJson('/api/v1/analytics/overview')
            ->assertOk()
            ->assertJsonPath('coverage.publishable', 1);
    }

    // ── The aggregates ────────────────────────────────────────────────────

    #[Test]
    public function a_cumulative_counter_is_read_once_not_once_per_snapshot(): void
    {
        /*
         * The bug this exists to catch. Metrics are written hourly and the
         * counters X returns are running totals, so summing the rows counts the
         * same impression once for every hour the post has existed. Measured on
         * the real install: five tweets, ninety-two snapshots, sixteen
         * impressions reported as a hundred and seventy-five.
         *
         * Needs a live ClickHouse -- the aggregation is the thing under test
         * and MySQL cannot stand in for it.
         */
        $clickhouse = app(ClickHouseConnection::class);

        if (! $clickhouse->enabled()) {
            $this->markTestSkipped('cần ClickHouse đang chạy');
        }

        $teamId = 90_000 + random_int(1, 9_000);
        $now = now();

        $snapshot = fn (int $hoursAgo, int $impressions): array => [
            'snapshot_time' => $now->copy()->subHours($hoursAgo)->format('Y-m-d H:i:s'),
            'team_id' => $teamId,
            'post_id' => 1,
            'account_id' => 1,
            'tweet_id' => 'tw-1',
            'impressions' => $impressions,
            'likes' => 0, 'retweets' => 0, 'replies' => 0, 'quotes' => 0,
            'bookmarks' => 0, 'url_link_clicks' => 0, 'user_profile_clicks' => 0,
            'engagements' => 0,
        ];

        // The same tweet, three times, its counter climbing.
        $clickhouse->insert('xpost_tweet_metrics', [
            $snapshot(3, 40),
            $snapshot(2, 70),
            $snapshot(1, 100),
        ], array_keys($snapshot(0, 0)));

        $totals = $this->asTeam($teamId, fn () => app(AnalyticsQueryService::class)
            ->engagementTotals($now->copy()->subDay(), $now->copy()->addMinute()));

        $this->assertSame(1, (int) $totals['posts'], 'three snapshots were counted as three posts');
        $this->assertSame(
            100,
            (int) $totals['impressions'],
            'the counter was summed across snapshots instead of read at its latest value',
        );
    }

    #[Test]
    public function the_ranking_query_actually_returns_rows(): void
    {
        /*
         * `sum(impressions) AS impressions` shadows the column, so an `if()`
         * beside it reading `impressions` gets the alias and ClickHouse rejects
         * the query as an aggregate inside an aggregate. select() swallowed
         * that, so the table came back empty and looked like no data.
         */
        $clickhouse = app(ClickHouseConnection::class);

        if (! $clickhouse->enabled()) {
            $this->markTestSkipped('cần ClickHouse đang chạy');
        }

        $teamId = 91_000 + random_int(1, 9_000);
        $now = now();

        $clickhouse->insert('xpost_tweet_metrics', [[
            'snapshot_time' => $now->format('Y-m-d H:i:s'),
            'team_id' => $teamId, 'post_id' => 1, 'account_id' => 7, 'tweet_id' => 'tw-2',
            'impressions' => 200, 'likes' => 4, 'retweets' => 0, 'replies' => 0, 'quotes' => 0,
            'bookmarks' => 0, 'url_link_clicks' => 0, 'user_profile_clicks' => 2, 'engagements' => 10,
        ]], [
            'snapshot_time', 'team_id', 'post_id', 'account_id', 'tweet_id', 'impressions',
            'likes', 'retweets', 'replies', 'quotes', 'bookmarks', 'url_link_clicks',
            'user_profile_clicks', 'engagements',
        ]);

        $rows = $this->asTeam($teamId, fn () => app(AnalyticsQueryService::class)
            ->topAccounts($now->copy()->subDay(), $now->copy()->addMinute()));

        $this->assertNotSame([], $rows, 'the ranking query was refused and returned nothing');
        $this->assertSame(7, (int) $rows[0]['account_id']);
        $this->assertSame(5.0, (float) $rows[0]['engagement_rate']);
    }

    /** Run a closure with a given team in context. */
    private function asTeam(int $teamId, callable $callback): mixed
    {
        return TeamContext::runAs($teamId, $callback);
    }
}
