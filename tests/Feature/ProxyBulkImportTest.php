<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CheckProxy;
use App\Models\Proxy;
use App\Models\Team;
use App\Support\TeamContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pasting a supplier's delivery, and finding out where it comes out.
 */
class ProxyBulkImportTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $user] = $this->tenant();
        $this->actingAs($user->refresh());
    }

    /** On the acting team: the factory would otherwise mint one of its own, and TeamScope would hide it. */
    private function proxy(array $attributes = []): Proxy
    {
        return Proxy::factory()->create($attributes + ['team_id' => $this->team->id]);
    }

    #[Test]
    public function a_paste_becomes_proxies_whatever_order_the_columns_are_in(): void
    {
        $response = $this->postJson('/api/v1/proxies/bulk', [
            'text' => implode("\n", [
                '1.2.3.4:8080:user:pass',
                '5.6.7.8|3128|user2|pass2',
                '1080|9.10.11.12|user3|pass3',        // port first
                'user4|pass4|13.14.15.16|8888',       // credentials first
                'socks5://user5:pass5@17.18.19.20:1080',
                '21.22.23.24:8080',                   // IP-authorised, no credentials
            ]),
            'scheme' => 'http',
        ]);

        $response->assertCreated()
            ->assertJsonPath('created', 6)
            ->assertJsonPath('skipped', []);

        $this->assertSame(6, Proxy::query()->count());

        // The awkward ones, spelled out: these are the orders that would break
        // a positional parser.
        $portFirst = Proxy::query()->where('host', '9.10.11.12')->sole();
        $this->assertSame(1080, $portFirst->port);
        $this->assertSame('user3', $portFirst->username);

        $credentialsFirst = Proxy::query()->where('host', '13.14.15.16')->sole();
        $this->assertSame(8888, $credentialsFirst->port);
        $this->assertSame('pass4', $credentialsFirst->password);

        // A line naming its own scheme keeps it over the form's default.
        $this->assertSame('socks5', Proxy::query()->where('host', '17.18.19.20')->sole()->scheme->value);
        $this->assertSame('http', Proxy::query()->where('host', '1.2.3.4')->sole()->scheme->value);
    }

    #[Test]
    public function a_line_without_credentials_is_added_like_any_other(): void
    {
        // IP-authorised proxies are sold this way; refusing them would be
        // refusing a whole product.
        $this->postJson('/api/v1/proxies/bulk', ['text' => '1.2.3.4:8080'])
            ->assertCreated()
            ->assertJsonPath('created', 1);

        $proxy = Proxy::query()->sole();

        $this->assertNull($proxy->username);
        $this->assertNull($proxy->password);
    }

    #[Test]
    public function a_line_already_on_the_team_is_refreshed_rather_than_refused(): void
    {
        /*
         * Re-pasting the supplier's list is how a rotated password gets
         * applied. Reporting "đã tồn tại" on 900 of 1000 lines would make that
         * the one thing the import cannot do.
         */
        $this->postJson('/api/v1/proxies/bulk', ['text' => '1.2.3.4:8080:user:old']);

        $this->postJson('/api/v1/proxies/bulk', ['text' => '1.2.3.4:8080:user:new'])
            ->assertCreated()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('updated', 1);

        $this->assertSame(1, Proxy::query()->count());
        $this->assertSame('new', Proxy::query()->sole()->password);
    }

    #[Test]
    public function the_same_line_twice_in_one_paste_does_not_hit_the_unique_index(): void
    {
        // A supplier's file with a duplicated row is not the buyer's problem.
        $this->postJson('/api/v1/proxies/bulk', [
            'text' => "1.2.3.4:8080:user:pass\n1.2.3.4:8080:user:pass",
        ])->assertCreated();

        $this->assertSame(1, Proxy::query()->count());
    }

    #[Test]
    public function unreadable_lines_are_named_and_the_rest_still_land(): void
    {
        /*
         * Silently dropping them is how someone pastes 500 proxies, sees 498
         * appear, and has no way to find the two that did not.
         */
        $response = $this->postJson('/api/v1/proxies/bulk', [
            'text' => "1.2.3.4:8080\nkhông phải proxy\n5.6.7.8:3128",
        ]);

        $response->assertCreated()
            ->assertJsonPath('created', 2)
            ->assertJsonCount(1, 'skipped')
            ->assertJsonPath('skipped.0.line', 'không phải proxy');

        $this->assertNotEmpty($response->json('skipped.0.reason'));
    }

    #[Test]
    public function a_paste_beyond_the_limit_is_refused_whole(): void
    {
        // Rather than writing the first N and leaving the caller to work out
        // where it stopped.
        config(['xpost.proxies.bulk_limit' => 3]);

        $lines = collect(range(1, 5))->map(fn (int $n) => "10.0.0.{$n}:8080")->implode("\n");

        $this->postJson('/api/v1/proxies/bulk', ['text' => $lines])->assertStatus(422);

        $this->assertSame(0, Proxy::query()->count());
    }

    #[Test]
    public function checking_reads_the_location_off_the_exit_ip(): void
    {
        /*
         * The country column was typed by hand, which makes it a note rather
         * than a fact. The check is already talking to the internet through the
         * proxy, so it asks what that looks like from outside.
         */
        $proxy = $this->proxy(['country' => 'VN']);

        Http::fake([
            'api.x.com/*' => Http::response(['ok' => true]),
            'ipwho.is/*' => Http::response([
                'ip' => '203.0.113.9',
                'success' => true,
                'country_code' => 'us',
                'region' => 'California',
                'city' => 'Los Angeles',
            ]),
        ]);

        $this->postJson("/api/v1/proxies/{$proxy->id}/check")
            ->assertOk()
            ->assertJsonPath('exit_ip', '203.0.113.9')
            ->assertJsonPath('country', 'US')
            ->assertJsonPath('region', 'California')
            ->assertJsonPath('city', 'Los Angeles');

        $this->assertSame('US', $proxy->fresh()->country);
    }

    #[Test]
    public function a_geo_service_failing_does_not_change_the_health_verdict(): void
    {
        // Whether some third party is up says nothing about whether the proxy
        // works, and letting it decide would be the tail wagging the dog.
        $proxy = $this->proxy(['health_status' => 'unknown']);

        Http::fake([
            'api.x.com/*' => Http::response(['ok' => true]),
            'ipwho.is/*' => Http::response('', 503),
        ]);

        $this->postJson("/api/v1/proxies/{$proxy->id}/check")
            ->assertOk()
            ->assertJsonPath('health_status', 'healthy');

        $this->assertSame('healthy', $proxy->fresh()->health_status);
        $this->assertNull($proxy->fresh()->exit_ip);
    }

    #[Test]
    public function a_dead_proxy_is_not_geolocated(): void
    {
        // The lookup goes through the proxy, so it can only time out again --
        // fifteen more seconds to learn nothing.
        $proxy = $this->proxy();

        Http::fake([
            'api.x.com/*' => fn () => throw new \RuntimeException('connect timeout'),
            'ipwho.is/*' => Http::response(['ip' => '203.0.113.9', 'country_code' => 'US']),
        ]);

        $this->postJson("/api/v1/proxies/{$proxy->id}/check")
            ->assertOk()
            ->assertJsonPath('health_status', 'dead');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ipwho.is'));
    }

    #[Test]
    public function a_selection_is_queued_for_checking(): void
    {
        /*
         * Queued rather than checked inline: a dead proxy takes fifteen seconds
         * to admit it, so a selection of a hundred does not fit in a request.
         */
        Queue::fake();

        $ids = collect(range(1, 3))->map(fn () => $this->proxy()->id)->all();

        $this->postJson('/api/v1/proxies/check', ['ids' => $ids])
            ->assertStatus(202)
            ->assertJsonPath('queued', 3);

        Queue::assertPushed(CheckProxy::class, 3);

        /*
         * Marked here rather than in the job, so the row changes the moment the
         * click lands. A queue that is stopped would otherwise look exactly
         * like a click that did nothing.
         */
        $this->assertSame(3, Proxy::query()->whereNotNull('check_queued_at')->count());
    }

    #[Test]
    public function another_teams_proxy_cannot_be_queued_by_id(): void
    {
        // The ids arrive from a browser. One from another team must find
        // nothing rather than something.
        Queue::fake();

        [$other] = $this->tenant();
        $stranger = Proxy::factory()->create(['team_id' => $other->id]);

        // tenant() moved the context; put it back before acting as the caller.
        TeamContext::set($this->team->id);

        $this->postJson('/api/v1/proxies/check', ['ids' => [$stranger->id]])
            ->assertStatus(202)
            ->assertJsonPath('queued', 0);

        Queue::assertNothingPushed();
        $this->assertNull($stranger->fresh()->check_queued_at);
    }

    #[Test]
    public function a_selection_is_deleted_in_one_go(): void
    {
        $ids = collect(range(1, 3))->map(fn () => $this->proxy()->id)->all();

        $this->deleteJson('/api/v1/proxies', ['ids' => $ids])
            ->assertOk()
            ->assertJsonPath('deleted', 3);

        $this->assertSame(0, Proxy::query()->count());
    }

    #[Test]
    public function proxies_still_carrying_accounts_are_kept_and_named(): void
    {
        /*
         * Rather than refusing the whole request: a selection of fifty where
         * two are in use should delete forty-eight and say which two it kept.
         */
        $free = $this->proxy(['assigned_count' => 0]);
        $inUse = $this->proxy(['assigned_count' => 4, 'label' => 'Proxy đang dùng']);

        $this->deleteJson('/api/v1/proxies', ['ids' => [$free->id, $inUse->id]])
            ->assertOk()
            ->assertJsonPath('deleted', 1)
            ->assertJsonCount(1, 'skipped')
            ->assertJsonPath('skipped.0.label', 'Proxy đang dùng');

        $this->assertNull($free->fresh());
        $this->assertNotNull($inUse->fresh());
    }

    #[Test]
    public function a_bulk_action_needs_at_least_one_id(): void
    {
        // An empty selection is a bug in the caller, not a no-op to absorb.
        $this->deleteJson('/api/v1/proxies', ['ids' => []])->assertStatus(422);
    }
}
