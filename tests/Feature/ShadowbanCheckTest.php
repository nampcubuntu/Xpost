<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CheckAccountVisibility;
use App\Models\Team;
use App\Models\XAccount;
use App\Services\X\ShadowbanChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whether X is quietly limiting an account's reach.
 *
 * There is no shadowban endpoint anywhere, so the answer is composed from two
 * calls that exist: does X's people-search return the handle, and does
 * `from:handle` return any posts. What has to hold is that the three answers
 * stay three -- "not restricted" and "could not tell" are different things, and
 * collapsing them reports a clean bill of health for an account never reached.
 */
class ShadowbanCheckTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $this->user] = $this->tenant();

        config([
            'xpost.links.sorsa.key' => 'test-key',
            'xpost.links.sorsa.base_url' => 'https://api.sorsa.io/v3',
        ]);
    }

    private function account(string $username = 'nancy_2z'): XAccount
    {
        return XAccount::factory()->create(['team_id' => $this->team->id, 'username' => $username]);
    }

    /** @param  array<int, array<string, mixed>>  $users */
    private function fake(array $users, int $tweets, array $extra = []): void
    {
        Http::fake([
            'api.sorsa.io/v3/search-users' => Http::response(['users' => $users]),
            'api.sorsa.io/v3/search-tweets' => Http::response([
                'tweets' => array_fill(0, $tweets, ['id' => '1', 'is_replies_limited' => false] + $extra),
                // Empty string, not null, is what the last page carries.
                'next_cursor' => $extra['next_cursor'] ?? '',
            ]),
        ]);
    }

    #[Test]
    public function an_account_x_is_not_limiting_reads_as_clear(): void
    {
        $this->fake([['username' => 'nancy_2z', 'protected' => false, 'possibly_sensitive' => false, 'tweets_count' => 40]], 20);

        $result = app(ShadowbanChecker::class)->check($this->account());

        $this->assertSame('ok', $result['state']);
        $this->assertSame('ok', $result['detail']['search']);
        $this->assertSame('ok', $result['detail']['search_suggestion']);
        $this->assertSame(20, $result['detail']['posts_found']);
    }

    #[Test]
    public function a_handle_search_will_not_return_is_a_suggestion_ban(): void
    {
        // Reached X, and the handle was not among the results -- which is the
        // classic search suggestion ban.
        $this->fake([['username' => 'someone_else', 'protected' => false]], 20);

        $result = app(ShadowbanChecker::class)->check($this->account());

        $this->assertSame('restricted', $result['state']);
        $this->assertSame('restricted', $result['detail']['search_suggestion']);
    }

    #[Test]
    public function no_posts_in_search_is_a_search_ban(): void
    {
        $this->fake([['username' => 'nancy_2z', 'protected' => false, 'tweets_count' => 40]], 0);

        $result = app(ShadowbanChecker::class)->check($this->account());

        $this->assertSame('restricted', $result['state']);
        $this->assertSame('restricted', $result['detail']['search']);
    }

    #[Test]
    public function a_failed_call_is_unknown_and_never_clear(): void
    {
        /*
         * The whole point of three states. A check that could not reach X has
         * cleared nothing, and reporting it as "no restrictions detected" is
         * the same lie as a proxy showing healthy from a check five days ago.
         */
        Http::fake(['api.sorsa.io/*' => Http::response(['message' => 'rate limited'], 429)]);

        $result = app(ShadowbanChecker::class)->check($this->account());

        $this->assertSame('unknown', $result['state']);
        $this->assertSame('unknown', $result['detail']['search']);
        $this->assertSame('unknown', $result['detail']['search_suggestion']);
    }

    #[Test]
    public function one_call_failing_does_not_clear_the_other(): void
    {
        // A partial answer is still partial. The suggestion test succeeded and
        // the search test did not, so the row cannot say "ok".
        Http::fake([
            'api.sorsa.io/v3/search-users' => Http::response([
                'users' => [['username' => 'nancy_2z', 'protected' => false, 'tweets_count' => 40]],
            ]),
            'api.sorsa.io/v3/search-tweets' => Http::response(['message' => 'boom'], 500),
        ]);

        $result = app(ShadowbanChecker::class)->check($this->account());

        $this->assertSame('unknown', $result['state']);
        $this->assertSame('ok', $result['detail']['search_suggestion']);
        $this->assertSame('unknown', $result['detail']['search']);
    }

    #[Test]
    public function a_private_account_is_not_reported_as_shadowbanned(): void
    {
        /*
         * The visibility tests cannot see a protected account by design, so an
         * empty search result there means nothing. Calling it a shadowban would
         * send someone hunting for something X never did.
         */
        $this->fake([['username' => 'nancy_2z', 'protected' => true, 'tweets_count' => 40]], 0);

        $result = app(ShadowbanChecker::class)->check($this->account());

        $this->assertSame('protected', $result['state']);
        $this->assertTrue($result['detail']['protected']);
    }

    #[Test]
    public function the_handle_is_matched_whatever_case_x_returns_it_in(): void
    {
        // The account is rarely the first result for its own name, and X does
        // not echo the case it was asked with.
        $this->fake([
            ['username' => 'nancy_other'],
            ['username' => 'NANCY_2Z', 'protected' => false, 'tweets_count' => 40],
        ], 5);

        $this->assertSame('ok', app(ShadowbanChecker::class)->check($this->account())['state']);
    }

    #[Test]
    public function a_sweep_is_queued_rather_than_answered_inline(): void
    {
        /*
         * Two Sorsa calls per account against a flat twenty a second, so a
         * thousand accounts is a hundred seconds at best -- not something to
         * hold an HTTP request open for.
         */
        Queue::fake();

        $ids = [$this->account('a')->id, $this->account('b')->id];

        $this->actingAs($this->user->refresh())
            ->postJson('/api/v1/accounts/visibility', ['account_ids' => $ids])
            ->assertStatus(202)
            ->assertJsonPath('queued', 2);

        Queue::assertPushed(CheckAccountVisibility::class, 2);
    }

    #[Test]
    public function without_a_key_the_page_is_told_rather_than_shown_empty_rows(): void
    {
        // A column of blanks looks like every account being fine. It is a
        // missing setting, and only the message can tell them apart.
        config(['xpost.links.sorsa.key' => null]);

        $this->actingAs($this->user->refresh())
            ->postJson('/api/v1/accounts/visibility', ['account_ids' => [$this->account()->id]])
            ->assertStatus(422);
    }

    #[Test]
    public function the_verdict_is_stored_with_the_moment_it_was_reached(): void
    {
        // A verdict with no date is a claim about now made from whenever.
        $this->fake([['username' => 'nancy_2z', 'protected' => false, 'tweets_count' => 40]], 12);

        $account = $this->account();

        (new CheckAccountVisibility($account->id, $this->team->id))->handle(app(ShadowbanChecker::class));

        $account->refresh();

        $this->assertSame('ok', $account->visibility_state);
        $this->assertNotNull($account->visibility_checked_at);
        $this->assertSame(12, $account->visibility_detail['posts_found']);
    }

    #[Test]
    public function an_account_that_never_posted_is_not_a_search_ban(): void
    {
        /*
         * Found by running against the live API rather than by thinking about
         * it. The first real account tested returned nothing for
         * `from:handle`, and only its tweets_count settled whether that meant
         * anything -- the comment in the code claimed this guard existed
         * before the code did.
         *
         * Nothing came back is only a ban if there was something to come back.
         */
        $this->fake([['username' => 'nancy_2z', 'protected' => false, 'tweets_count' => 0]], 0);

        $result = app(ShadowbanChecker::class)->check($this->account());

        $this->assertSame('unknown', $result['detail']['search'], 'an account with no posts was called search-banned');
        $this->assertNotSame('restricted', $result['state']);
    }

    #[Test]
    public function an_account_with_posts_and_none_in_search_is_a_search_ban(): void
    {
        // The other side of the same coin, and the real reading on the first
        // account tested: two posts on the profile, none findable.
        $this->fake([['username' => 'nancy_2z', 'protected' => false, 'tweets_count' => 2]], 0);

        $result = app(ShadowbanChecker::class)->check($this->account());

        $this->assertSame('restricted', $result['detail']['search']);
        $this->assertSame(2, $result['detail']['posts_total']);
    }

    #[Test]
    public function an_unreadable_post_count_does_not_invent_the_alarming_answer(): void
    {
        // Guessing here would manufacture a shadowban out of a missing field.
        $this->fake([['username' => 'nancy_2z', 'protected' => false]], 0);

        $this->assertSame('unknown', app(ShadowbanChecker::class)->check($this->account())['detail']['search']);
    }

    #[Test]
    public function a_page_of_results_is_not_reported_as_a_total(): void
    {
        /*
         * The count is a page. The response carries a next_cursor, and the raw
         * JSON for a real account showed one -- so twenty means twenty-and-more,
         * and the page was printing it as though the account had exactly that
         * many findable posts.
         */
        Http::fake([
            'api.sorsa.io/v3/search-users' => Http::response([
                'users' => [['username' => 'nancy_2z', 'protected' => false, 'tweets_count' => 119]],
            ]),
            'api.sorsa.io/v3/search-tweets' => Http::response([
                'tweets' => array_fill(0, 20, ['id' => '1']),
                'next_cursor' => 'DAADDAABCgAB',
            ]),
        ]);

        $result = app(ShadowbanChecker::class)->check($this->account());

        $this->assertSame(20, $result['detail']['posts_found']);
        $this->assertTrue($result['detail']['posts_more'], 'a page with more behind it was reported as the whole answer');
    }

    #[Test]
    public function replies_limited_by_x_is_its_own_restriction(): void
    {
        /*
         * is_replies_limited rides along on the posts the search already
         * returned, so it costs nothing -- and it is the one restriction
         * neither of the other tests can see: X limiting who can read an
         * account's replies shows up nowhere in whether its posts are findable.
         */
        Http::fake([
            'api.sorsa.io/v3/search-users' => Http::response([
                'users' => [['username' => 'nancy_2z', 'protected' => false, 'tweets_count' => 119]],
            ]),
            'api.sorsa.io/v3/search-tweets' => Http::response([
                'tweets' => [
                    ['id' => '1', 'is_replies_limited' => false],
                    ['id' => '2', 'is_replies_limited' => true],
                ],
                'next_cursor' => '',
            ]),
        ]);

        $result = app(ShadowbanChecker::class)->check($this->account());

        $this->assertSame('restricted', $result['detail']['replies']);
        $this->assertSame(1, $result['detail']['replies_limited']);
        // And it drags the account's overall verdict with it, since the other
        // two tests passing does not make a limited account unlimited.
        $this->assertSame('restricted', $result['state']);
    }

    #[Test]
    public function the_real_payload_maps_field_for_field(): void
    {
        /*
         * The response as the live API actually sent it for @MDN_Design,
         * trimmed to the fields this reads. Frozen here because every earlier
         * mistake in this file came from assuming a shape rather than looking
         * at one: the count that turned out to be a page, and the reply flag
         * that was sitting unread in every tweet.
         */
        $tweet = fn (bool $limited = false) => [
            'id' => '2094682390765682991',
            'full_text' => 'random meetup turned legendary',
            'is_replies_limited' => $limited,
            'is_reply' => false,
            'view_count' => 26,
            'user' => [
                'username' => 'MDN_Design',
                'tweets_count' => 119,
                'protected' => false,
                'possibly_sensitive' => false,
                'verified' => false,
            ],
        ];

        Http::fake([
            'api.sorsa.io/v3/search-users' => Http::response([
                'users' => [[
                    'id' => '600940892',
                    'username' => 'MDN_Design',
                    'display_name' => 'MDN Design Network',
                    'followers_count' => 80,
                    'tweets_count' => 119,
                    'verified' => false,
                    'protected' => false,
                    'possibly_sensitive' => false,
                ]],
                'next_cursor' => '',
            ]),
            'api.sorsa.io/v3/search-tweets' => Http::response([
                'tweets' => array_fill(0, 20, $tweet()),
                // The cursor the real response carried, which is what makes the
                // twenty a page rather than a total.
                'next_cursor' => 'DAADDAABCgABHRHOiUrawS8KAAIc4Lpz3ptgKw',
            ]),
        ]);

        $result = app(ShadowbanChecker::class)->check($this->account('MDN_Design'));

        $this->assertSame('ok', $result['state']);
        $this->assertSame('ok', $result['detail']['search']);
        $this->assertSame('ok', $result['detail']['search_suggestion']);
        $this->assertSame('ok', $result['detail']['replies']);
        $this->assertSame(20, $result['detail']['posts_found']);
        $this->assertTrue($result['detail']['posts_more']);
        $this->assertSame(119, $result['detail']['posts_total']);
        $this->assertSame(0, $result['detail']['replies_limited']);
        $this->assertFalse($result['detail']['protected']);
        $this->assertFalse($result['detail']['sensitive']);
    }

    #[Test]
    public function the_tiles_add_up_to_the_number_of_accounts(): void
    {
        /*
         * They did not. pluck turns a null column into an empty-string key, so
         * get('') and get(null) resolved to the same entry and adding them
         * reported 2,046 unchecked accounts out of 1,026 -- a number larger
         * than the pool, sitting on the page as a fact.
         */
        $this->fake([['username' => 'a', 'protected' => false, 'tweets_count' => 40]], 5);

        $checked = $this->account('checked');
        $this->account('never_checked_1');
        $this->account('never_checked_2');

        (new CheckAccountVisibility($checked->id, $this->team->id))->handle(app(ShadowbanChecker::class));

        $body = $this->actingAs($this->user->refresh())
            ->getJson('/api/v1/accounts/visibility/stats')
            ->assertOk()
            ->json();

        $this->assertSame(3, $body['total']);
        $this->assertSame(2, $body['by_state']['unchecked']);
        $this->assertSame(3, array_sum($body['by_state']), 'the tiles do not add up to the pool');
    }
}
