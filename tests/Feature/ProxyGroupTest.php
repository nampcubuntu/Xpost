<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Proxy;
use App\Models\ProxyGroup;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Names for batches of proxies.
 *
 * A supplier's delivery is hundreds of lines whose only distinguishing feature
 * is the host, which is exactly what nobody remembers. The thing that has to
 * work is not the naming -- it is finding the batch again afterwards, in a list
 * that is now paginated.
 */
class ProxyGroupTest extends TestCase
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

    private function group(array $attributes = []): ProxyGroup
    {
        return ProxyGroup::factory()->create($attributes + ['team_id' => $this->team->id]);
    }

    #[Test]
    public function a_group_is_created_and_listed(): void
    {
        $this->postJson('/api/v1/proxy-groups', ['name' => 'Nhà cung cấp A', 'color' => '#ff0000'])
            ->assertCreated()
            ->assertJsonPath('name', 'Nhà cung cấp A');

        $this->getJson('/api/v1/proxy-groups')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Nhà cung cấp A')
            ->assertJsonPath('data.0.proxies_count', 0);
    }

    #[Test]
    public function two_groups_on_one_team_cannot_share_a_name(): void
    {
        // Two batches both called "VN" is the state that makes the grouping
        // useless, and it is easier to hit than it sounds when a second person
        // imports a delivery.
        $this->group(['name' => 'VN dân cư']);

        $this->postJson('/api/v1/proxy-groups', ['name' => 'VN dân cư'])->assertStatus(422);
    }

    #[Test]
    public function another_team_may_use_the_same_name(): void
    {
        // The uniqueness is per team, not global -- every team will want a
        // group called "VN".
        ProxyGroup::factory()->create(['name' => 'VN dân cư']);

        $this->postJson('/api/v1/proxy-groups', ['name' => 'VN dân cư'])->assertCreated();
    }

    #[Test]
    public function a_selection_is_added_and_removed_in_one_call(): void
    {
        // Attach and detach together, so an edit of a group's membership is one
        // save rather than one request per proxy that moved.
        $group = $this->group();
        $stays = $this->proxy();
        $goes = $this->proxy();

        $group->proxies()->attach($goes->id);

        $this->postJson("/api/v1/proxy-groups/{$group->id}/sync", [
            'attach' => [$stays->id],
            'detach' => [$goes->id],
        ])->assertOk()->assertJsonPath('proxies_count', 1);

        $this->assertSame([$stays->id], $group->proxies()->pluck('proxies.id')->all());
    }

    #[Test]
    public function attaching_the_same_proxy_twice_is_not_an_error(): void
    {
        /*
         * "Chọn tất cả" then "Gán nhóm" on a page where half are already in it
         * is the normal way this gets used, and it must not hit the pivot's
         * unique index.
         */
        $group = $this->group();
        $proxy = $this->proxy();

        $this->postJson("/api/v1/proxy-groups/{$group->id}/sync", ['attach' => [$proxy->id]])->assertOk();
        $this->postJson("/api/v1/proxy-groups/{$group->id}/sync", ['attach' => [$proxy->id]])
            ->assertOk()
            ->assertJsonPath('proxies_count', 1);
    }

    #[Test]
    public function a_proxy_can_be_in_more_than_one_group(): void
    {
        // A line is both "Nhà cung cấp A" and "VN dân cư". Being forced to pick
        // one is how a grouping stops getting used.
        $proxy = $this->proxy();
        $supplier = $this->group(['name' => 'Nhà cung cấp A']);
        $country = $this->group(['name' => 'VN dân cư']);

        $this->postJson("/api/v1/proxy-groups/{$supplier->id}/sync", ['attach' => [$proxy->id]])->assertOk();
        $this->postJson("/api/v1/proxy-groups/{$country->id}/sync", ['attach' => [$proxy->id]])->assertOk();

        $this->assertSame(2, $proxy->groups()->count());
    }

    #[Test]
    public function another_teams_proxy_cannot_be_added(): void
    {
        /*
         * The ids come from the browser and the pivot's foreign key would take
         * a stranger's proxy without complaint -- which would put another
         * team's line on this team's page.
         */
        $group = $this->group();
        $stranger = Proxy::factory()->create();

        $this->postJson("/api/v1/proxy-groups/{$group->id}/sync", ['attach' => [$stranger->id]])
            ->assertOk()
            ->assertJsonPath('proxies_count', 0);
    }

    #[Test]
    public function the_list_can_be_filtered_down_to_one_group(): void
    {
        /*
         * The point of the whole feature. Filtering in the browser would search
         * the fifty rows already downloaded, so a group of two hundred spread
         * over four pages would come back looking like fifty.
         */
        $group = $this->group();
        $member = $this->proxy(['host' => '203.0.113.7']);
        $this->proxy(['host' => '198.51.100.4']);

        $group->proxies()->attach($member->id);

        $rows = $this->getJson("/api/v1/proxies?group={$group->id}")->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('203.0.113.7', $rows[0]['host']);
    }

    #[Test]
    public function a_row_carries_the_groups_it_belongs_to(): void
    {
        // So the chip can render without a request per row.
        $group = $this->group(['name' => 'Nhà cung cấp A', 'color' => '#ff0000']);
        $proxy = $this->proxy();
        $group->proxies()->attach($proxy->id);

        $this->getJson('/api/v1/proxies')
            ->assertOk()
            ->assertJsonPath('data.0.groups.0.name', 'Nhà cung cấp A')
            ->assertJsonPath('data.0.groups.0.color', '#ff0000');
    }

    #[Test]
    public function a_page_of_rows_with_groups_is_still_two_queries(): void
    {
        /*
         * The chips are the kind of addition that turns one query into fifty-one
         * without anything looking wrong. Pinned, because the list was
         * deliberately paginated to survive a supplier's two thousand lines.
         */
        $group = $this->group();

        collect(range(1, 20))->each(function (int $n) use ($group): void {
            $group->proxies()->attach($this->proxy(['host' => "10.0.0.{$n}"])->id);
        });

        DB::enableQueryLog();
        $this->getJson('/api/v1/proxies?per_page=20')->assertOk();
        $reads = collect(DB::getQueryLog())
            ->filter(fn (array $entry) => str_contains($entry['query'], 'proxies')
                || str_contains($entry['query'], 'proxy_group'))
            ->count();
        DB::disableQueryLog();

        // A count for the pager, the page itself, and one for every group on it.
        $this->assertLessThanOrEqual(3, $reads, 'the group chips are being loaded per row');
    }

    #[Test]
    public function deleting_a_group_keeps_the_proxies(): void
    {
        // A mis-click on "Xoá nhóm" must not be a re-import of a supplier's
        // whole delivery.
        $group = $this->group();
        $proxy = $this->proxy();
        $group->proxies()->attach($proxy->id);

        $this->deleteJson("/api/v1/proxy-groups/{$group->id}")->assertNoContent();

        $this->assertNotNull($proxy->fresh());
        $this->assertSame(0, ProxyGroup::query()->count());
    }

    #[Test]
    public function deleting_a_proxy_takes_its_memberships_with_it(): void
    {
        // Otherwise the count on the group keeps promising a row that is gone.
        $group = $this->group();
        $proxy = $this->proxy();
        $group->proxies()->attach($proxy->id);

        $this->deleteJson("/api/v1/proxies/{$proxy->id}")->assertOk();

        $this->assertSame(0, $group->proxies()->count());
    }

    #[Test]
    public function another_teams_groups_are_not_listed(): void
    {
        ProxyGroup::factory()->create(['name' => 'Của người khác']);
        $this->group(['name' => 'Của tôi']);

        $this->getJson('/api/v1/proxy-groups')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Của tôi');
    }
}
