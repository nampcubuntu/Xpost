<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TinyLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The short-link store, held in Cloudflare KV rather than this database.
 *
 * A Worker reads these keys, so Xpost is one writer of someone else's store and
 * never mirrors it: a stale copy would show keys that no longer resolve and
 * hide ones written from anywhere else.
 */
class LinkStoreTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$team, $this->user] = $this->tenant();

        /*
         * The namespace belongs to the team, not to config: a shared one lets
         * any admin list and delete another team's links. A team without one
         * has no short-link store at all.
         */
        $team->forceFill(['cloudflare_kv_namespace_id' => 'ns456'])->save();

        config([
            'xpost.links.account_id' => 'acc123',
            'xpost.links.api_token' => 'token789',
            'xpost.links.base_url' => 'https://l.example.com',
        ]);
    }

    #[Test]
    public function it_lists_keys_with_their_values(): void
    {
        /*
         * KV lists keys without values, so the page needs the values too --
         * fetched in one bulk read rather than a request per row, which at
         * half a second each turned an eighty-key page into a minute.
         */
        Http::fake([
            'api.cloudflare.com/*/keys*' => Http::response([
                'success' => true,
                'result' => [['name' => 'r:abc'], ['name' => 'r:def']],
                'result_info' => ['cursor' => ''],
            ]),
            'api.cloudflare.com/*/bulk/get' => Http::response([
                'success' => true,
                'result' => ['values' => [
                    'r:abc' => 'https://img/1|https://dest/',
                    'r:def' => 'https://img/2|https://dest/',
                ]],
            ]),
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/links')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'r:abc')
            ->assertJsonPath('data.0.value', 'https://img/1|https://dest/')
            /*
             * Pages by number now, not by cursor. This asserted `cursor` was
             * null, which stayed green when the field went away entirely --
             * data_get on a missing key is also null. The count is the part
             * that cannot pass by absence.
             */
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.complete', true);
    }

    #[Test]
    public function the_old_single_domain_setting_seeds_the_first_row(): void
    {
        // An install that already had XPOST_LINK_BASE_URL keeps working without
        // anyone re-entering it.
        Http::fake(['api.cloudflare.com/*' => Http::response([
            'success' => true, 'result' => [], 'result_info' => ['cursor' => ''],
        ])]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/links')
            ->assertOk()
            ->assertJsonPath('domains.0.host', 'l.example.com')
            ->assertJsonPath('domains.0.is_default', true);
    }

    #[Test]
    public function a_domain_is_reduced_to_its_host(): void
    {
        /*
         * People paste what they have. Two rows for the same domain differing
         * by a scheme or a trailing slash look identical on screen and defeat
         * the unique index.
         */
        $this->actingAs($this->user)
            ->postJson('/api/v1/link-domains', ['host' => 'https://Link.Example.com/abc'])
            ->assertCreated()
            ->assertJsonPath('data.host', 'link.example.com');
    }

    #[Test]
    public function the_first_domain_is_the_default_whether_or_not_it_was_asked_for(): void
    {
        // A list with entries and no default has nothing to build a URL from.
        $this->actingAs($this->user)
            ->postJson('/api/v1/link-domains', ['host' => 'a.example.com'])
            ->assertCreated()
            ->assertJsonPath('data.is_default', true);
    }

    #[Test]
    public function only_one_domain_is_ever_the_default(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/link-domains', ['host' => 'a.example.com']);
        $second = $this->actingAs($this->user)
            ->postJson('/api/v1/link-domains', ['host' => 'b.example.com', 'is_default' => true])
            ->json('data.id');

        $domains = $this->actingAs($this->user)->getJson('/api/v1/link-domains')->json('data');

        $this->assertSame([$second], collect($domains)->where('is_default', true)->pluck('id')->all());
    }

    #[Test]
    public function deleting_the_default_promotes_another(): void
    {
        // Every short URL is built from the default; a list with none silently
        // stops producing links.
        $first = $this->actingAs($this->user)
            ->postJson('/api/v1/link-domains', ['host' => 'a.example.com'])->json('data.id');
        $this->actingAs($this->user)->postJson('/api/v1/link-domains', ['host' => 'b.example.com']);

        $this->actingAs($this->user)->deleteJson("/api/v1/link-domains/{$first}")->assertNoContent();

        $this->assertSame(
            'b.example.com',
            $this->actingAs($this->user)->getJson('/api/v1/link-domains')->json('data.0.host'),
        );
        $this->assertTrue($this->actingAs($this->user)->getJson('/api/v1/link-domains')->json('data.0.is_default'));
    }

    #[Test]
    public function the_same_domain_cannot_be_added_twice(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/link-domains', ['host' => 'a.example.com']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/link-domains', ['host' => 'https://a.example.com/'])
            ->assertStatus(422);
    }

    #[Test]
    public function something_that_is_not_a_domain_is_refused(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/link-domains', ['host' => 'localhost'])
            ->assertStatus(422);
    }

    #[Test]
    public function a_missing_value_reads_as_null_rather_than_failing(): void
    {
        /*
         * A bulk read simply omits a key it could not find. A key listed a
         * moment ago and gone by the read is a race, not an error -- an empty
         * cell says that better than a failed page.
         */
        Http::fake([
            'api.cloudflare.com/*/keys*' => Http::response([
                'success' => true,
                'result' => [['name' => 'r:gone']],
                'result_info' => ['cursor' => ''],
            ]),
            'api.cloudflare.com/*/bulk/get' => Http::response([
                'success' => true,
                'result' => ['values' => []],
            ]),
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/links')
            ->assertOk()
            ->assertJsonPath('data.0.value', null);
    }

    #[Test]
    public function adding_a_pair_sends_the_value_as_a_form_field(): void
    {
        /*
         * The endpoint takes the value as a multipart field. Sending JSON
         * stores the quoted, escaped text -- which reads back as a valid string
         * and only looks wrong at the Worker.
         */
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/links', ['key' => 'r:abc', 'value' => 'https://a|https://b'])
            ->assertCreated()
            ->assertJsonPath('data.key', 'r:abc');

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), 'values/r%3Aabc')
            && str_contains((string) $request->body(), 'https://a|https://b'));
    }

    #[Test]
    public function a_key_typed_without_its_prefix_gets_one(): void
    {
        /*
         * Nobody types the prefix, and a key saved without it is invisible to
         * every prefix filter -- which is how a namespace ends up holding
         * entries nobody can find again.
         */
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/links', ['key' => '095Lrdja', 'value' => 'https://a|https://b'])
            ->assertCreated()
            ->assertJsonPath('data.key', 'r:095Lrdja');
    }

    #[Test]
    public function a_key_that_already_has_a_prefix_is_left_alone(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/links', ['key' => 'x:custom', 'value' => 'https://a|https://b'])
            ->assertCreated()
            ->assertJsonPath('data.key', 'x:custom');
    }

    #[Test]
    public function one_url_gets_the_default_domain_appended(): void
    {
        // Every existing entry pairs with it, and typing it a hundred times is
        // how a typo gets in.
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);
        $this->actingAs($this->user)->postJson('/api/v1/link-domains', ['host' => 'twvideo.top']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/links', ['value' => 'https://imgur.com/a/l8qLXZa'])
            ->assertCreated()
            ->assertJsonPath('data.value', 'https://imgur.com/a/l8qLXZa|https://twvideo.top/');
    }

    #[Test]
    public function a_value_that_already_has_both_halves_is_left_alone(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);
        $this->actingAs($this->user)->postJson('/api/v1/link-domains', ['host' => 'twvideo.top']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/links', ['value' => 'https://img|https://elsewhere.com/'])
            ->assertCreated()
            ->assertJsonPath('data.value', 'https://img|https://elsewhere.com/');
    }

    #[Test]
    public function a_bulk_paste_is_shaped_the_same_way(): void
    {
        // A hundred keys landing without their prefix is exactly what a paste
        // would otherwise do.
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);
        $this->actingAs($this->user)->postJson('/api/v1/link-domains', ['host' => 'twvideo.top']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/links/bulk', ['pairs' => [
                ['key' => 'abc', 'value' => 'https://imgur.com/a/one'],
            ]])
            ->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/bulk')) {
                return false;
            }

            $pair = $request->data()[0];

            /*
             * The metadata is asserted rather than ignored: it is where the
             * creation time lives, and it is the only place it can. KV lists
             * lexicographically and has no notion of when a key was written, so
             * a paste that skipped the stamp would sort below every hand-added
             * link for good.
             */
            return $pair['key'] === 'r:abc'
                && $pair['value'] === 'https://imgur.com/a/one|https://twvideo.top/'
                && is_int($pair['metadata']['created_at'] ?? null);
        });
    }

    #[Test]
    public function a_key_left_blank_is_generated(): void
    {
        // The key is a lookup handle; nobody should invent one at a time.
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/links', ['value' => 'https://a|https://b'])
            ->assertCreated();

        $key = $response->json('data.key');

        $this->assertStringStartsWith('r:', $key);
        // No characters that are read or copied wrong: no 0/O, no 1/l/I.
        $this->assertMatchesRegularExpression('/^r:[a-zA-HJ-NP-Z2-9]{8}$/', $key);
    }

    #[Test]
    public function many_pairs_go_out_in_one_request(): void
    {
        // The reason this screen exists: the dashboard takes them one click at
        // a time, and this pool needs hundreds.
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        $pairs = [];

        for ($i = 0; $i < 250; $i++) {
            $pairs[] = ['key' => "r:key{$i}", 'value' => "https://a/{$i}|https://b"];
        }

        $this->actingAs($this->user)
            ->postJson('/api/v1/links/bulk', ['pairs' => $pairs])
            ->assertOk()
            ->assertJsonPath('written', 250);

        Http::assertSentCount(1);
    }

    #[Test]
    public function deleting_something_already_gone_is_not_an_error(): void
    {
        // A 404 here is the state the caller asked for.
        Http::fake(['api.cloudflare.com/*' => Http::response('', 404)]);

        $this->actingAs($this->user)
            ->deleteJson('/api/v1/links', ['key' => 'r:gone'])
            ->assertNoContent();
    }

    #[Test]
    public function a_cloudflare_error_behind_a_200_is_still_an_error(): void
    {
        // Cloudflare answers 200 with success:false for some failures, so the
        // status alone is not the answer.
        Http::fake(['api.cloudflare.com/*' => Http::response([
            'success' => false,
            'errors' => [['message' => 'Invalid namespace']],
        ])]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/links', ['value' => 'https://a|https://b'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid namespace');
    }

    #[Test]
    public function missing_credentials_say_which_ones(): void
    {
        /*
         * Nothing on the page works without them, and the failure is otherwise
         * a generic 422 on every action with nothing naming the cause.
         */
        config(['xpost.links.api_token' => null]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/links')
            ->assertStatus(422)
            ->assertJsonPath('configured', false)
            ->assertJsonFragment(['data' => []]);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/links')->assertUnauthorized();
    }

    #[Test]
    public function the_newest_link_is_the_first_one_listed(): void
    {
        /*
         * The whole reason the creation time is stamped at all. KV lists
         * lexicographically and offers a cursor, nothing else -- so without a
         * stamp in the metadata "newest first" is not a question it can answer,
         * and a link made today lands wherever its name happens to fall.
         */
        Http::fake([
            'api.cloudflare.com/*/keys*' => Http::response(['success' => true, 'result' => [
                ['name' => 'r:aaa', 'metadata' => ['created_at' => 1000]],
                ['name' => 'r:zzz', 'metadata' => ['created_at' => 9000]],
                ['name' => 'r:mmm'],
            ], 'result_info' => ['cursor' => '']]),
            'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['values' => []]]),
        ]);

        $rows = $this->actingAs($this->user)->getJson('/api/v1/links')->assertOk()->json('data');

        // Newest first, and the one with no stamp last rather than guessed at.
        $this->assertSame(['r:zzz', 'r:aaa', 'r:mmm'], array_column($rows, 'key'));
        $this->assertNotNull($rows[0]['created_at']);
        $this->assertNull($rows[2]['created_at']);
    }

    #[Test]
    public function a_namespace_too_large_to_sort_says_so(): void
    {
        /*
         * Past the sweep's ceiling the sort can only see what it read, so
         * "newest first" becomes newest-of-some -- which looks exactly like the
         * truth. The page is told, rather than being handed a quiet truncation.
         */
        Http::fake([
            'api.cloudflare.com/*/keys*' => Http::response(['success' => true, 'result' => [
                ['name' => 'r:aaa', 'metadata' => ['created_at' => 1000]],
            ], 'result_info' => ['cursor' => 'more-to-come']]),
            'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['values' => []]]),
        ]);

        $meta = $this->actingAs($this->user)->getJson('/api/v1/links')->assertOk()->json('meta');

        $this->assertFalse($meta['complete']);
    }

    #[Test]
    public function a_page_is_shortened_in_one_go_rather_than_one_at_a_time(): void
    {
        /*
         * Measured at 315ms per call against the live endpoint, so a page of
         * fifty held the request for sixteen seconds -- and `artisan serve`
         * handles one request at a time, which made that sixteen seconds of the
         * whole application.
         */
        config(['xpost.links.tinyurl.enabled' => true, 'xpost.links.tinyurl.token' => '']);

        Http::fake([
            'tinyurl.com/api-create.php*' => Http::sequence()
                ->push('https://tinyurl.com/aaa')
                ->push('https://tinyurl.com/bbb'),
            'api.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/link-domains', ['host' => 'twvideo.top']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/links/shorten', ['keys' => ['r:one', 'r:two']])
            ->assertOk()
            ->assertJsonPath('data.r:one', 'https://tinyurl.com/aaa')
            ->assertJsonPath('data.r:two', 'https://tinyurl.com/bbb');
    }

    #[Test]
    public function a_link_already_shortened_is_not_shortened_again(): void
    {
        /*
         * Asking twice mints a second code, and a post already carrying the
         * first becomes untraceable -- two live links to the same place and
         * nothing saying which is in use where.
         */
        config(['xpost.links.tinyurl.enabled' => true, 'xpost.links.tinyurl.token' => '']);

        Http::fake([
            'tinyurl.com/api-create.php*' => Http::response('https://tinyurl.com/once'),
            'api.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/link-domains', ['host' => 'twvideo.top']);

        $first = $this->actingAs($this->user)
            ->postJson('/api/v1/links/shorten', ['keys' => ['r:same']])->json('data.r:same');

        $second = $this->actingAs($this->user)
            ->postJson('/api/v1/links/shorten', ['keys' => ['r:same']])->json('data.r:same');

        $this->assertSame($first, $second);
        $this->assertSame(1, TinyLink::query()->count(), 'a second code was minted for the same link');
    }

    #[Test]
    public function one_refusal_does_not_take_the_rest_of_the_page_with_it(): void
    {
        // The endpoint answers the word "Error" with a 200, so the body has to
        // be read rather than the status -- and one bad answer is one empty
        // cell, not a failed page.
        config(['xpost.links.tinyurl.enabled' => true, 'xpost.links.tinyurl.token' => '']);

        Http::fake([
            'tinyurl.com/api-create.php*' => Http::sequence()
                ->push('Error')
                ->push('https://tinyurl.com/fine'),
            'api.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/link-domains', ['host' => 'twvideo.top']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/links/shorten', ['keys' => ['r:bad', 'r:good']])
            ->assertOk()
            ->assertJsonPath('data.r:bad', null)
            ->assertJsonPath('data.r:good', 'https://tinyurl.com/fine');

        // The failure left no row, so the next attempt tries again rather than
        // caching the absence.
        $this->assertSame(1, TinyLink::query()->count());
    }
}
