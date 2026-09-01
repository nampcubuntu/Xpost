<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CheckAccountVisibility;
use App\Models\Team;
use App\Models\VisibilityCheck;
use App\Services\X\ShadowbanChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whether X is quietly limiting a handle's reach.
 *
 * There is no shadowban endpoint anywhere, so the answer is composed from two
 * calls that exist: does X's people-search return the handle, and does
 * `from:handle` return any posts. What has to hold is that the three answers
 * stay three -- "not restricted" and "could not tell" are different things, and
 * collapsing them reports a clean bill of health for a handle never reached.
 *
 * Asked about handles rather than connected accounts, because the useful ones
 * include names this install has never posted from.
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

    /** @param  array<int, array<string, mixed>>  $users */
    private function fake(array $users, int $tweets, string $cursor = ''): void
    {
        Http::fake([
            'api.sorsa.io/v3/search-users' => Http::response(['users' => $users]),
            'api.sorsa.io/v3/search-tweets' => Http::response([
                'tweets' => array_fill(0, $tweets, ['id' => '1', 'is_replies_limited' => false]),
                // Empty string, not null, is what the last page carries.
                'next_cursor' => $cursor,
            ]),
        ]);
    }

    private function profile(array $overrides = []): array
    {
        return $overrides + [
            'username' => 'nancy_2z',
            'protected' => false,
            'possibly_sensitive' => false,
            'tweets_count' => 119,
        ];
    }

    private function row(array $attributes = []): VisibilityCheck
    {
        return VisibilityCheck::query()->create($attributes + [
            'team_id' => $this->team->id,
            'handle' => 'nancy_2z',
        ]);
    }

    // ── The composition ───────────────────────────────────────────────────

    #[Test]
    public function a_handle_x_is_not_limiting_reads_as_clear(): void
    {
        $this->fake([$this->profile()], 20);

        $result = app(ShadowbanChecker::class)->check('nancy_2z');

        $this->assertSame('ok', $result['state']);
        $this->assertSame('ok', $result['detail']['search']);
        $this->assertSame('ok', $result['detail']['search_suggestion']);
        $this->assertSame('ok', $result['detail']['replies']);
        $this->assertSame(20, $result['detail']['posts_found']);
    }

    #[Test]
    public function a_handle_search_will_not_return_is_a_suggestion_ban(): void
    {
        // Reached X, and the handle was not among the results -- which is the
        // classic search suggestion ban.
        $this->fake([$this->profile(['username' => 'someone_else'])], 20);

        $result = app(ShadowbanChecker::class)->check('nancy_2z');

        $this->assertSame('restricted', $result['state']);
        $this->assertSame('restricted', $result['detail']['search_suggestion']);
    }

    #[Test]
    public function posts_on_the_profile_and_none_in_search_is_a_search_ban(): void
    {
        $this->fake([$this->profile()], 0);

        $result = app(ShadowbanChecker::class)->check('nancy_2z');

        $this->assertSame('restricted', $result['state']);
        $this->assertSame('restricted', $result['detail']['search']);
    }

    #[Test]
    public function a_handle_that_never_posted_is_not_a_search_ban(): void
    {
        /*
         * Found by running against the live API rather than by thinking about
         * it. The first real handle tested returned nothing for `from:`, and
         * only its tweets_count settled whether that meant anything.
         *
         * Nothing came back is only a ban if there was something to come back.
         */
        $this->fake([$this->profile(['tweets_count' => 0])], 0);

        $result = app(ShadowbanChecker::class)->check('nancy_2z');

        $this->assertSame('unknown', $result['detail']['search'], 'a handle with no posts was called search-banned');
        $this->assertNotSame('restricted', $result['state']);
    }

    #[Test]
    public function an_unreadable_post_count_does_not_invent_the_alarming_answer(): void
    {
        // Guessing here would manufacture a shadowban out of a missing field.
        $this->fake([['username' => 'nancy_2z', 'protected' => false]], 0);

        $this->assertSame('unknown', app(ShadowbanChecker::class)->check('nancy_2z')['detail']['search']);
    }

    // ── Three states, never two ───────────────────────────────────────────

    #[Test]
    public function a_failed_call_is_unknown_and_never_clear(): void
    {
        /*
         * The whole point. A check that could not reach X has cleared nothing,
         * and reporting it as "no restrictions detected" is the same lie as a
         * proxy showing healthy from a check five days ago.
         */
        Http::fake(['api.sorsa.io/*' => Http::response(['message' => 'rate limited'], 429)]);

        $result = app(ShadowbanChecker::class)->check('nancy_2z');

        $this->assertSame('unknown', $result['state']);
        $this->assertSame('unknown', $result['detail']['search']);
        $this->assertSame('unknown', $result['detail']['search_suggestion']);
    }

    #[Test]
    public function one_call_failing_does_not_clear_the_other(): void
    {
        // A partial answer is still partial.
        Http::fake([
            'api.sorsa.io/v3/search-users' => Http::response(['users' => [$this->profile()]]),
            'api.sorsa.io/v3/search-tweets' => Http::response(['message' => 'boom'], 500),
        ]);

        $result = app(ShadowbanChecker::class)->check('nancy_2z');

        $this->assertSame('unknown', $result['state']);
        $this->assertSame('ok', $result['detail']['search_suggestion']);
        $this->assertSame('unknown', $result['detail']['search']);
    }

    #[Test]
    public function a_private_handle_is_not_reported_as_shadowbanned(): void
    {
        /*
         * The visibility tests cannot see a protected account by design, so an
         * empty search result there means nothing. Calling it a shadowban would
         * send someone hunting for something X never did.
         */
        $this->fake([$this->profile(['protected' => true])], 0);

        $result = app(ShadowbanChecker::class)->check('nancy_2z');

        $this->assertSame('protected', $result['state']);
        $this->assertTrue($result['detail']['protected']);
    }

    // ── Reading the response ──────────────────────────────────────────────

    #[Test]
    public function the_handle_is_matched_whatever_case_x_returns_it_in(): void
    {
        // The account is rarely the first result for its own name, and X does
        // not echo the case it was asked with.
        $this->fake([
            ['username' => 'nancy_other'],
            $this->profile(['username' => 'NANCY_2Z']),
        ], 5);

        $this->assertSame('ok', app(ShadowbanChecker::class)->check('nancy_2z')['state']);
    }

    #[Test]
    public function a_page_of_results_is_not_reported_as_a_total(): void
    {
        /*
         * The count is a page. The raw JSON for a real handle carried a
         * next_cursor, so twenty means twenty-and-more, and the page was
         * printing it as though that were all there was.
         */
        $this->fake([$this->profile()], 20, 'DAADDAABCgAB');

        $result = app(ShadowbanChecker::class)->check('nancy_2z');

        $this->assertSame(20, $result['detail']['posts_found']);
        $this->assertTrue($result['detail']['posts_more'], 'a page with more behind it was reported as the whole answer');
    }

    #[Test]
    public function replies_limited_by_x_is_its_own_restriction(): void
    {
        /*
         * is_replies_limited rides along on the posts the search already
         * returned, so it costs nothing -- and it is the one restriction
         * neither of the other tests can see.
         */
        Http::fake([
            'api.sorsa.io/v3/search-users' => Http::response(['users' => [$this->profile()]]),
            'api.sorsa.io/v3/search-tweets' => Http::response([
                'tweets' => [
                    ['id' => '1', 'is_replies_limited' => false],
                    ['id' => '2', 'is_replies_limited' => true],
                ],
                'next_cursor' => '',
            ]),
        ]);

        $result = app(ShadowbanChecker::class)->check('nancy_2z');

        $this->assertSame('restricted', $result['detail']['replies']);
        $this->assertSame(1, $result['detail']['replies_limited']);
        // And it carries the verdict: two clean tests do not make a limited
        // account unlimited.
        $this->assertSame('restricted', $result['state']);
    }

    #[Test]
    public function the_real_payload_maps_field_for_field(): void
    {
        /*
         * The response as the live API actually sent it for @MDN_Design,
         * trimmed to the fields this reads. Frozen because every earlier
         * mistake here came from assuming a shape rather than looking at one.
         */
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
                'tweets' => array_fill(0, 20, [
                    'id' => '2094682390765682991',
                    'is_replies_limited' => false,
                    'view_count' => 26,
                ]),
                'next_cursor' => 'DAADDAABCgABHRHOiUrawS8KAAIc4Lpz3ptgKw',
            ]),
        ]);

        $result = app(ShadowbanChecker::class)->check('MDN_Design');

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

    // ── The list ──────────────────────────────────────────────────────────

    #[Test]
    public function a_pasted_list_becomes_rows_and_jobs(): void
    {
        /*
         * The page is a list someone already has. Handles arrive as they were
         * copied: bare, with an @, as a profile URL, in whatever case -- and
         * all of those spellings are one handle, stored once.
         */
        Queue::fake();

        $this->actingAs($this->user->refresh())
            ->postJson('/api/v1/visibility', [
                'handles' => ['nancy_2z', '@MDN_DESIGN', 'https://x.com/GoodingAngie', '  nancy_2z  '],
            ])
            ->assertStatus(202)
            // The duplicate is one handle, not two.
            ->assertJsonPath('added', 3)
            ->assertJsonPath('invalid', []);

        $this->assertSame(
            ['goodingangie', 'mdn_design', 'nancy_2z'],
            VisibilityCheck::query()->orderBy('handle')->pluck('handle')->all(),
        );

        Queue::assertPushed(CheckAccountVisibility::class, 3);
    }

    #[Test]
    public function a_line_that_is_not_an_x_handle_is_named(): void
    {
        /*
         * "38 of 40 added" leaves someone holding two lines they cannot find in
         * a paste of forty -- the failure the proxy and link importers were
         * built to avoid. X handles are word characters, at most fifteen.
         */
        Queue::fake();

        $this->actingAs($this->user->refresh())
            ->postJson('/api/v1/visibility', [
                'handles' => ['nancy_2z', 'has spaces', 'way_too_long_to_be_a_handle'],
            ])
            ->assertStatus(202)
            ->assertJsonPath('added', 1)
            ->assertJsonPath('invalid', ['has spaces', 'way_too_long_to_be_a_handle']);
    }

    #[Test]
    public function adding_a_handle_already_on_the_list_keeps_its_last_verdict(): void
    {
        // Until the new check answers. Blanking it to "chưa kiểm tra" would
        // throw away the only answer on the page while the queue works.
        Queue::fake();

        $this->row(['state' => 'restricted', 'checked_at' => now()->subHour()]);

        $this->actingAs($this->user->refresh())
            ->postJson('/api/v1/visibility', ['handles' => ['@nancy_2z']])
            ->assertStatus(202);

        $this->assertSame(1, VisibilityCheck::query()->count());
        $this->assertSame('restricted', VisibilityCheck::query()->sole()->state);
    }

    #[Test]
    public function the_check_is_queued_rather_than_answered_inline(): void
    {
        /*
         * Two Sorsa calls per handle at three to five seconds each, so a list
         * of a hundred is minutes -- not something to hold an HTTP request
         * open for.
         */
        Queue::fake();

        $row = $this->row();

        $this->actingAs($this->user->refresh())
            ->postJson('/api/v1/visibility/check', ['ids' => [$row->id]])
            ->assertStatus(202)
            ->assertJsonPath('queued', 1);

        Queue::assertPushed(CheckAccountVisibility::class, 1);
    }

    #[Test]
    public function without_a_key_handles_are_still_added_but_nothing_is_asked(): void
    {
        /*
         * A column of blanks looks like every handle being fine. It is a
         * missing setting, and the page has to be able to say which.
         */
        config(['xpost.links.sorsa.key' => null]);
        Queue::fake();

        $this->actingAs($this->user->refresh())
            ->postJson('/api/v1/visibility', ['handles' => ['nancy_2z']])
            ->assertStatus(202)
            ->assertJsonPath('added', 1)
            ->assertJsonPath('queued', 0)
            ->assertJsonPath('configured', false);

        Queue::assertNothingPushed();

        $this->actingAs($this->user->refresh())
            ->postJson('/api/v1/visibility/check', ['ids' => [VisibilityCheck::query()->sole()->id]])
            ->assertStatus(422);
    }

    #[Test]
    public function the_verdict_is_stored_with_the_moment_it_was_reached(): void
    {
        // A verdict with no date is a claim about now made from whenever.
        $this->fake([$this->profile()], 12);

        $row = $this->row();

        (new CheckAccountVisibility($row->id, $this->team->id))->handle(app(ShadowbanChecker::class));

        $row->refresh();

        $this->assertSame('ok', $row->state);
        $this->assertNotNull($row->checked_at);
        $this->assertSame(12, $row->detail['posts_found']);
    }

    #[Test]
    public function the_tiles_add_up_to_the_list(): void
    {
        /*
         * They once said 2,046 unchecked out of 1,026 -- a number larger than
         * the list, sitting on the page as a fact. pluck turns a null column
         * into an empty-string key, so reading it by name counted twice.
         */
        $this->row(['handle' => 'a', 'state' => 'ok', 'checked_at' => now()]);
        $this->row(['handle' => 'b']);
        $this->row(['handle' => 'c']);

        $body = $this->actingAs($this->user->refresh())->getJson('/api/v1/visibility')->assertOk()->json();

        $this->assertSame(3, $body['total']);
        $this->assertSame(2, $body['by_state']['unchecked']);
        $this->assertSame(3, array_sum($body['by_state']), 'the tiles do not add up to the list');
    }

    #[Test]
    public function a_refusal_from_sorsa_comes_back_with_what_it_said(): void
    {
        /*
         * Fifty rows read "không hỏi được" and the page could not say why. The
         * answer was a flat 403 -- "request limit exceeded", the plan out of
         * requests -- thrown away one layer down, so the only thing on screen
         * pointed at X for a problem that was on this side of the wire.
         */
        Http::fake(['api.sorsa.io/*' => Http::response(['message' => 'request limit exceeded'], 403)]);

        $result = app(ShadowbanChecker::class)->check('nancy_2z');

        $this->assertSame('unknown', $result['state']);
        $this->assertSame('Sorsa 403: request limit exceeded', $result['detail']['error']);
    }

    #[Test]
    public function a_check_that_worked_carries_no_reason(): void
    {
        // Or every clean row would sit under an explanation for a failure that
        // did not happen.
        $this->fake([$this->profile()], 20);

        $this->assertArrayNotHasKey('error', app(ShadowbanChecker::class)->check('nancy_2z')['detail']);
    }

    #[Test]
    public function the_reason_does_not_leak_from_one_handle_to_the_next(): void
    {
        // The checker is reused across a sweep, so a reason left behind would
        // explain the previous handle's failure on this one's row.
        // One stub for both handles, so the second call is answered by a live
        // rule rather than by a stub registered after the first stub already won.
        Http::fake(function ($request) {
            if (str_contains((string) $request->body(), 'first_one')) {
                return Http::response(['message' => 'request limit exceeded'], 403);
            }

            return Http::response(str_contains($request->url(), 'search-users')
                ? ['users' => [$this->profile()]]
                : ['tweets' => [['id' => '1']], 'next_cursor' => '']);
        });

        $checker = app(ShadowbanChecker::class);

        $this->assertSame('Sorsa 403: request limit exceeded', $checker->check('first_one')['detail']['error']);
        $this->assertArrayNotHasKey('error', $checker->check('nancy_2z')['detail']);
    }

    #[Test]
    public function a_check_that_dies_leaves_the_row_saying_so(): void
    {
        /*
         * What the user actually hit: 143 jobs died, every row stayed blank,
         * and blank is exactly what a handle looks like before it is checked.
         * A failure was indistinguishable from waiting, so the page sat there
         * promising a result that was never coming.
         *
         * A dead check has to leave a verdict behind -- "chưa rõ" plus the
         * reason -- or nothing on screen can tell the two apart.
         */
        $row = $this->row();

        (new CheckAccountVisibility($row->id, $this->team->id))
            ->failed(new \RuntimeException('Sorsa từ chối: 402'));

        $row->refresh();

        $this->assertSame('unknown', $row->state);
        $this->assertNotNull($row->checked_at, 'a dead check left the row looking unchecked');
        $this->assertStringContainsString('402', $row->detail['error']);
        // And never as a clean bill of health.
        $this->assertSame('unknown', $row->detail['search']);
    }

    #[Test]
    public function the_reason_a_check_died_survives_to_the_page(): void
    {
        // The list is what the page reads, so a reason that never leaves the
        // database is a reason nobody sees.
        $this->row(['state' => 'unknown', 'checked_at' => now(), 'detail' => [
            'search' => 'unknown',
            'error' => 'RuntimeException: Sorsa từ chối: 402',
        ]]);

        $this->actingAs($this->user->refresh())
            ->getJson('/api/v1/visibility')
            ->assertOk()
            ->assertJsonPath('data.0.detail.error', 'RuntimeException: Sorsa từ chối: 402');
    }

    #[Test]
    public function a_spent_quota_stops_the_rest_of_the_sweep(): void
    {
        /*
         * The twelfth handle came back 403 -- the plan out of requests -- and
         * the remaining forty-nine each went on to spend two more against the
         * same wall. Ninety-eight requests that could not have worked, and the
         * allowance they burned is what the list needs after a reset.
         *
         * A 403 is Sorsa talking about the account, not about the handle, so it
         * settles every check behind it.
         */
        Http::fake(['api.sorsa.io/*' => Http::response(['message' => 'request limit exceeded'], 403)]);

        $first = $this->row(['handle' => 'first_one']);
        $second = $this->row(['handle' => 'second_one']);

        (new CheckAccountVisibility($first->id, $this->team->id))->handle(app(ShadowbanChecker::class));

        $spent = count(Http::recorded());

        (new CheckAccountVisibility($second->id, $this->team->id))->handle(app(ShadowbanChecker::class));

        $this->assertCount($spent, Http::recorded(), 'the sweep kept asking after the plan ran out');

        // And the row behind the wall still says why, rather than going blank
        // or looking like it was never checked.
        $second->refresh();
        $this->assertSame('unknown', $second->state);
        $this->assertNotNull($second->checked_at);
        $this->assertStringContainsString('request limit exceeded', $second->detail['error']);
    }

    #[Test]
    public function a_handle_that_simply_failed_does_not_stop_the_sweep(): void
    {
        // Only a spent quota is the account's problem. One handle timing out or
        // a 500 says nothing about the next one, and treating it as a wall
        // would abandon a list over a single blip.
        Http::fake(['api.sorsa.io/*' => Http::response(['message' => 'boom'], 500)]);

        $first = $this->row(['handle' => 'first_one']);
        $second = $this->row(['handle' => 'second_one']);

        (new CheckAccountVisibility($first->id, $this->team->id))->handle(app(ShadowbanChecker::class));

        $spent = count(Http::recorded());

        (new CheckAccountVisibility($second->id, $this->team->id))->handle(app(ShadowbanChecker::class));

        $this->assertGreaterThan($spent, count(Http::recorded()), 'a single failure stopped the whole list');
    }

    #[Test]
    public function another_teams_handles_are_not_listed(): void
    {
        // The list is per team: two teams watching the same handle are two
        // rows, and neither can see the other's verdict.
        $this->row(['handle' => 'mine']);

        [$other] = $this->tenant();
        VisibilityCheck::query()->create(['team_id' => $other->id, 'handle' => 'theirs']);

        $rows = $this->actingAs($this->user->refresh())->getJson('/api/v1/visibility')->assertOk()->json('data');

        $this->assertSame(['mine'], array_column($rows, 'handle'));
    }
}
