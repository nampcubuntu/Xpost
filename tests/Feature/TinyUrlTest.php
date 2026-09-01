<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ShortLink;
use App\Models\TinyLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shortening a short link again, through TinyURL.
 *
 * Every result is remembered: asking twice for the same URL mints a second
 * code, and a post already carrying the first becomes untraceable -- two live
 * links to the same place and nothing saying which is in use where.
 */
class TinyUrlTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$team, $this->user] = $this->tenant();

        // Per team, not config: a shared namespace is a shared store.
        $team->forceFill(['cloudflare_kv_namespace_id' => 'ns456'])->save();

        config([
            'xpost.links.account_id' => 'acc',
            'xpost.links.api_token' => 'tok',
            'xpost.links.tinyurl.enabled' => true,
            // Required now: without one the only endpoint left is the
            // anonymous one, and TinyURL sends those through a ten second
            // interstitial rather than to the destination.
            'xpost.links.tinyurl.token' => 'test-token',
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/link-domains', ['host' => 'twvideo.top']);
    }

    /**
     * Http::fake matches stubs in insertion order, so the catch-alls go last --
     * listed first they swallow every override before it is reached.
     */
    private function fake(array $overrides = []): void
    {
        Http::fake($overrides + [
            'api.cloudflare.com/*' => Http::response(['success' => true]),
            'api.tinyurl.com/*' => Http::response(['data' => ['tiny_url' => 'https://tinyurl.com/abcd']]),
        ]);
    }

    #[Test]
    public function adding_an_entry_shortens_its_link_in_the_same_step(): void
    {
        $this->fake();

        $this->actingAs($this->user)
            ->postJson('/api/v1/links', ['key' => '095Lrdja', 'value' => 'https://imgur.com/a/x'])
            ->assertCreated()
            ->assertJsonPath('data.tiny_url', 'https://tinyurl.com/abcd');

        // The prefix routes at the Worker, so the shortened target carries it.
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'tinyurl.com')
            || str_contains(urldecode($request->url()), 'https://twvideo.top/r/095Lrdja'));
    }

    #[Test]
    public function the_same_target_is_never_shortened_twice(): void
    {
        /*
         * TinyURL mints a new code per request. A second one for the same
         * target leaves two live links and nothing saying which is in use.
         */
        $this->fake();

        $this->actingAs($this->user)->postJson('/api/v1/links', ['key' => 'abc', 'value' => 'https://a|https://b']);
        $this->actingAs($this->user)->postJson('/api/v1/links', ['key' => 'abc', 'value' => 'https://a|https://b']);

        $this->assertSame(1, TinyLink::query()->count());
        Http::assertSentCount(3);   // two KV writes, one TinyURL
    }

    #[Test]
    public function tinyurl_being_down_does_not_lose_the_entry(): void
    {
        // Shortening runs after the KV write; a failure there must not undo
        // something that was saved correctly.
        $this->fake(['api.tinyurl.com/*' => Http::response(['errors' => ['down']], 500)]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/links', ['key' => 'abc', 'value' => 'https://a|https://b'])
            ->assertCreated()
            ->assertJsonPath('data.key', 'r:abc')
            ->assertJsonPath('data.tiny_url', null);
    }

    #[Test]
    public function without_a_token_nothing_is_minted_at_all(): void
    {
        /*
         * The anonymous endpoint is gone, and this is why. A link minted there
         * answers 302 to tinyurl.com/preview/deprecated/… -- an interstitial
         * that holds the visitor for ten seconds behind a "Continue to Your
         * Destination" button. Three of those were shipped before it was
         * noticed, because the fallback was silent.
         *
         * A link that does that is worse than no link, so there is no link.
         */
        config(['xpost.links.tinyurl.token' => null]);

        $this->fake();

        $this->actingAs($this->user)
            ->postJson('/api/v1/links', ['key' => 'abc', 'value' => 'https://a|https://b'])
            ->assertCreated()
            ->assertJsonPath('data.key', 'r:abc')
            ->assertJsonPath('data.tiny_url', null);

        $this->assertSame(0, TinyLink::query()->count());

        // And nothing was asked of TinyURL, rather than asked and discarded.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'tinyurl.com'));
    }

    #[Test]
    public function the_shorten_endpoint_says_so_rather_than_answering_empty(): void
    {
        // A page of empty cells looks like TinyURL being down. It is a missing
        // setting, and only the message can tell them apart.
        config(['xpost.links.tinyurl.token' => null]);

        $this->fake();

        $this->actingAs($this->user)
            ->postJson('/api/v1/links/shorten', ['keys' => ['r:abc']])
            ->assertStatus(422);
    }

    #[Test]
    public function a_token_switches_to_the_modern_api(): void
    {
        config(['xpost.links.tinyurl.token' => 'tiny-token']);

        $this->fake(['api.tinyurl.com/create' => Http::response([
            'data' => ['tiny_url' => 'https://tinyurl.com/named'],
        ])]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/links', ['key' => 'abc', 'value' => 'https://a|https://b'])
            ->assertCreated()
            ->assertJsonPath('data.tiny_url', 'https://tinyurl.com/named');

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'api.tinyurl.com')
            || $request->hasHeader('Authorization', 'Bearer tiny-token'));
    }

    #[Test]
    public function listing_shows_known_links_without_minting_new_ones(): void
    {
        /*
         * Listing is a read. Shortening every row a page happens to show would
         * leave a trail of unused codes behind each visit.
         */
        $this->fake([
            'api.cloudflare.com/*/keys*' => Http::response([
                'success' => true,
                'result' => [['name' => 'r:abc'], ['name' => 'r:def']],
                'result_info' => ['cursor' => ''],
            ]),
            'api.cloudflare.com/*/values/*' => Http::response('https://a|https://b'),
        ]);

        // The list orders from the index now, so the rows have to exist
        // there -- a faked key listing is no longer what the page reads.
        foreach (['r:abc' => 1, 'r:def' => 2] as $key => $age) {
            ShortLink::create([
                'team_id' => $this->user->current_team_id,
                'key' => $key,
                'created_at' => now()->subMinutes($age),
            ]);
        }

        // Stated rather than inherited: the tenant context is cleared when a
        // request ends, so nothing outside one has a team unless it says so.
        TinyLink::create([
            'team_id' => $this->user->current_team_id,
            'target_url' => 'https://twvideo.top/r/abc',
            'target_hash' => TinyLink::hashFor('https://twvideo.top/r/abc'),
            'tiny_url' => 'https://tinyurl.com/known',
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/links')
            ->assertOk()
            ->assertJsonPath('data.0.tiny_url', 'https://tinyurl.com/known')
            ->assertJsonPath('data.1.tiny_url', null)
            ->assertJsonPath('tinyurl_enabled', true);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'tinyurl.com'));
    }

    #[Test]
    public function rows_can_be_shortened_after_the_fact(): void
    {
        // For entries that predate the feature, and ones TinyURL refused.
        $this->fake();

        $this->actingAs($this->user)
            ->postJson('/api/v1/links/shorten', ['keys' => ['r:abc']])
            ->assertOk()
            ->assertJsonPath('data.r:abc', 'https://tinyurl.com/abcd');
    }

    #[Test]
    public function shortening_is_refused_while_it_is_switched_off(): void
    {
        config(['xpost.links.tinyurl.enabled' => false]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/links/shorten', ['keys' => ['r:abc']])
            ->assertStatus(422);
    }

    #[Test]
    public function nothing_is_shortened_while_it_is_switched_off(): void
    {
        $this->fake();
        config(['xpost.links.tinyurl.enabled' => false]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/links', ['key' => 'abc', 'value' => 'https://a|https://b'])
            ->assertCreated()
            ->assertJsonPath('data.tiny_url', null);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'tinyurl.com'));
    }
}
