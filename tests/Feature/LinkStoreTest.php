<?php

declare(strict_types=1);

namespace Tests\Feature;

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
            // An empty string is Cloudflare's last page, not a cursor.
            ->assertJsonPath('cursor', null);
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

            return $request->data()[0] === [
                'key' => 'r:abc',
                'value' => 'https://imgur.com/a/one|https://twvideo.top/',
            ];
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
}
