<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Proxy;
use App\Models\Team;
use App\Models\XAccount;
use App\Support\TeamContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which IP each account walks out of.
 *
 * The whole reason for holding proxies is that a thousand accounts do not leave
 * from one address, so the operation that matters is sharing them out -- not
 * putting one account on one proxy.
 */
class ProxyAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $user] = $this->tenant();
        $this->actingAs($user->refresh());
    }

    private function proxy(array $attributes = []): Proxy
    {
        return Proxy::factory()->create($attributes + ['team_id' => $this->team->id]);
    }

    private function accounts(int $count): array
    {
        return collect(range(1, $count))
            ->map(fn () => XAccount::factory()->create(['team_id' => $this->team->id, 'proxy_id' => null])->id)
            ->all();
    }

    private function bulk(string $action, array $extra = [], ?array $ids = null)
    {
        return $this->postJson('/api/v1/accounts/bulk', [
            'action' => $action,
            'account_ids' => $ids ?? $this->accounts(0),
        ] + $extra);
    }

    #[Test]
    public function a_selection_is_spread_evenly_over_the_pool(): void
    {
        /*
         * Evenly, because everything landing on the first proxy is the same as
         * having no proxies -- which is the failure mode nobody notices until X
         * clusters the accounts.
         */
        $this->proxy(['max_accounts' => 50]);
        $this->proxy(['max_accounts' => 50]);
        $ids = $this->accounts(6);

        $this->bulk('spread_proxy', [], $ids)
            ->assertOk()
            ->assertJsonPath('unassigned', 0);

        $counts = Proxy::query()->pluck('assigned_count')->sort()->values()->all();

        $this->assertSame([3, 3], $counts);
        $this->assertSame(0, XAccount::query()->whereNull('proxy_id')->count());
    }

    #[Test]
    public function spreading_fills_the_emptiest_proxy_first(): void
    {
        // A proxy already carrying accounts is not an equal candidate: evenness
        // is about the total, not about this batch.
        $busy = $this->proxy(['max_accounts' => 50, 'assigned_count' => 4]);
        $empty = $this->proxy(['max_accounts' => 50, 'assigned_count' => 0]);

        $this->bulk('spread_proxy', [], $this->accounts(4))->assertOk();

        $this->assertSame(4, $empty->fresh()->assigned_count);
        $this->assertSame(4, $busy->fresh()->assigned_count);
    }

    #[Test]
    public function accounts_left_over_when_the_pool_is_full_are_reported(): void
    {
        /*
         * Silence here would read as "done" on accounts that still share one
         * IP, which is exactly the state the feature exists to prevent.
         */
        $this->proxy(['max_accounts' => 2]);
        $ids = $this->accounts(5);

        $this->bulk('spread_proxy', [], $ids)
            ->assertOk()
            ->assertJsonPath('unassigned', 3);

        $this->assertSame(3, XAccount::query()->whereNull('proxy_id')->count());
    }

    #[Test]
    public function a_dead_proxy_is_never_spread_onto(): void
    {
        // Assigning to one would look like it worked and fail at publish.
        $this->proxy(['health_status' => 'dead', 'max_accounts' => 50]);
        $this->proxy(['is_active' => false, 'max_accounts' => 50]);

        $this->bulk('spread_proxy', [], $this->accounts(2))
            ->assertOk()
            ->assertJsonPath('unassigned', 2);
    }

    #[Test]
    public function one_proxy_can_be_pinned_to_a_selection(): void
    {
        // For the accounts that have to sit on a particular exit.
        $proxy = $this->proxy();
        $ids = $this->accounts(3);

        $this->bulk('assign_proxy', ['proxy_id' => $proxy->id], $ids)->assertOk();

        $this->assertSame(3, XAccount::query()->where('proxy_id', $proxy->id)->count());
        // XAccountObserver keeps the counter the pool sorts on.
        $this->assertSame(3, $proxy->fresh()->assigned_count);
    }

    #[Test]
    public function a_proxy_can_be_taken_off_again(): void
    {
        $proxy = $this->proxy();
        $ids = $this->accounts(2);
        $this->bulk('assign_proxy', ['proxy_id' => $proxy->id], $ids);

        $this->bulk('clear_proxy', [], $ids)->assertOk();

        $this->assertSame(2, XAccount::query()->whereNull('proxy_id')->count());
        $this->assertSame(0, $proxy->fresh()->assigned_count);
    }

    #[Test]
    public function another_teams_proxy_cannot_be_assigned(): void
    {
        /*
         * The id arrives from a browser. `exists` on the table alone would let
         * one team route its accounts through another team's IP -- and bill
         * them for the traffic.
         */
        [$other] = $this->tenant();
        $stranger = Proxy::factory()->create(['team_id' => $other->id]);

        TeamContext::set($this->team->id);
        $ids = $this->accounts(1);

        $this->bulk('assign_proxy', ['proxy_id' => $stranger->id], $ids)
            ->assertStatus(422)
            ->assertJsonValidationErrors('proxy_id');
    }

    #[Test]
    public function assigning_without_naming_a_proxy_is_refused(): void
    {
        // Otherwise it silently means clear_proxy, which is the opposite.
        $this->bulk('assign_proxy', [], $this->accounts(1))->assertStatus(422);
    }

    #[Test]
    public function accounts_are_spread_over_the_proxies_picked_on_the_proxy_page(): void
    {
        /*
         * The same operation as spread_proxy, entered from the other end: a set
         * of proxies is in hand and the accounts are chosen to go behind them.
         * Only the picked proxies take accounts -- a third one sitting idle in
         * the pool is not part of the answer.
         */
        $first = $this->proxy(['max_accounts' => 50]);
        $second = $this->proxy(['max_accounts' => 50]);
        $untouched = $this->proxy(['max_accounts' => 50]);

        $this->postJson('/api/v1/proxies/assign', [
            'ids' => [$first->id, $second->id],
            'account_ids' => $this->accounts(4),
        ])
            ->assertOk()
            ->assertJsonPath('assigned', 4)
            ->assertJsonPath('unassigned', 0);

        $this->assertSame(2, $first->fresh()->assigned_count);
        $this->assertSame(2, $second->fresh()->assigned_count);
        $this->assertSame(0, $untouched->fresh()->assigned_count);
    }

    #[Test]
    public function accounts_beyond_the_picked_proxies_headroom_are_reported(): void
    {
        // The picked proxies are the whole pool for this operation, so running
        // out of them is a real outcome and not a reason to reach for others.
        $this->proxy(['max_accounts' => 50]);
        $small = $this->proxy(['max_accounts' => 1]);

        $this->postJson('/api/v1/proxies/assign', [
            'ids' => [$small->id],
            'account_ids' => $this->accounts(3),
        ])
            ->assertOk()
            ->assertJsonPath('assigned', 1)
            ->assertJsonPath('unassigned', 2);
    }

    #[Test]
    public function another_teams_accounts_are_not_reachable_from_the_proxy_page(): void
    {
        $proxy = $this->proxy();

        [$other] = $this->tenant();
        $stranger = XAccount::factory()->create(['team_id' => $other->id, 'proxy_id' => null]);

        TeamContext::set($this->team->id);

        $this->postJson('/api/v1/proxies/assign', [
            'ids' => [$proxy->id],
            'account_ids' => [$stranger->id],
        ])
            ->assertOk()
            ->assertJsonPath('assigned', 0);

        $this->assertNull($stranger->fresh()->proxy_id);
    }

    #[Test]
    public function the_picker_can_ask_for_only_the_accounts_without_a_proxy(): void
    {
        // Which is what the picker opens on: the accounts still leaving from
        // the server's own IP are nearly always the ones being placed.
        $proxy = $this->proxy();
        XAccount::factory()->create(['team_id' => $this->team->id, 'proxy_id' => $proxy->id]);
        XAccount::factory()->create(['team_id' => $this->team->id, 'proxy_id' => null]);

        $this->getJson('/api/v1/accounts?without_proxy=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
