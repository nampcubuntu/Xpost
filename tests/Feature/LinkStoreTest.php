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

        [, $this->user] = $this->tenant();

        config([
            'xpost.links.account_id' => 'acc123',
            'xpost.links.namespace_id' => 'ns456',
            'xpost.links.api_token' => 'token789',
            'xpost.links.base_url' => 'https://l.example.com',
        ]);
    }

    #[Test]
    public function it_lists_keys_with_their_values(): void
    {
        // KV lists keys without values, so each row costs a second read.
        Http::fake([
            'api.cloudflare.com/*/keys*' => Http::response([
                'success' => true,
                'result' => [['name' => 'r:abc'], ['name' => 'r:def']],
                'result_info' => ['cursor' => ''],
            ]),
            'api.cloudflare.com/*/values/r%3Aabc' => Http::response('https://img/1|https://dest/'),
            'api.cloudflare.com/*/values/r%3Adef' => Http::response('https://img/2|https://dest/'),
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/links')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'r:abc')
            ->assertJsonPath('data.0.value', 'https://img/1|https://dest/')
            ->assertJsonPath('base_url', 'https://l.example.com')
            // An empty string is Cloudflare's last page, not a cursor.
            ->assertJsonPath('cursor', null);
    }

    #[Test]
    public function a_missing_value_reads_as_null_rather_than_failing(): void
    {
        // KV reports an absent key as a 404, which is an answer, not an error.
        Http::fake([
            'api.cloudflare.com/*/keys*' => Http::response([
                'success' => true,
                'result' => [['name' => 'r:gone']],
                'result_info' => ['cursor' => ''],
            ]),
            'api.cloudflare.com/*/values/*' => Http::response('', 404),
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
