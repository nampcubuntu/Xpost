<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountGroup;
use App\Models\Team;
use App\Models\XAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Naming batches of accounts.
 *
 * The endpoints have been here since the beginning and no page ever reached
 * them: the accounts toolbar could put a selection into a group and offered no
 * way to make one, so the dropdown was empty on every install that had not
 * seeded a group by hand. Untested for the same reason -- nothing was calling
 * them, so nothing noticed.
 */
class AccountGroupTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $user] = $this->tenant();
        $this->actingAs($user->refresh());
    }

    private function account(array $attributes = []): XAccount
    {
        return XAccount::factory()->create($attributes + ['team_id' => $this->team->id]);
    }

    #[Test]
    public function a_group_is_created_and_listed(): void
    {
        $this->postJson('/api/v1/account-groups', ['name' => 'Thị trường VN', 'color' => '#ff0000'])
            ->assertCreated()
            ->assertJsonPath('name', 'Thị trường VN');

        $this->getJson('/api/v1/account-groups')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Thị trường VN')
            ->assertJsonPath('data.0.accounts_count', 0);
    }

    #[Test]
    public function two_groups_on_one_team_cannot_share_a_name(): void
    {
        AccountGroup::factory()->create(['team_id' => $this->team->id, 'name' => 'Thị trường VN']);

        $this->postJson('/api/v1/account-groups', ['name' => 'Thị trường VN'])->assertStatus(422);
    }

    #[Test]
    public function another_team_may_use_the_same_name(): void
    {
        // Per team, not global -- every team will want a group called "VN".
        AccountGroup::factory()->create(['name' => 'Thị trường VN']);

        $this->postJson('/api/v1/account-groups', ['name' => 'Thị trường VN'])->assertCreated();
    }

    #[Test]
    public function a_selection_is_added_and_removed_in_one_call(): void
    {
        $group = AccountGroup::factory()->create(['team_id' => $this->team->id]);
        $stays = $this->account();
        $goes = $this->account();

        $group->accounts()->attach($goes->id);

        $this->postJson("/api/v1/account-groups/{$group->id}/sync", [
            'attach' => [$stays->id],
            'detach' => [$goes->id],
        ])->assertOk()->assertJsonPath('accounts_count', 1);

        $this->assertSame([$stays->id], $group->accounts()->pluck('x_accounts.id')->all());
    }

    #[Test]
    public function the_bulk_action_on_the_accounts_page_fills_the_group(): void
    {
        /*
         * The path the toolbar actually takes. It is a different endpoint from
         * the sync above, and it is the one a user reaches -- so it is the one
         * that has to keep the count honest.
         */
        $group = AccountGroup::factory()->create(['team_id' => $this->team->id]);
        $ids = [$this->account()->id, $this->account()->id];

        $this->postJson('/api/v1/accounts/bulk', [
            'action' => 'assign_group',
            'account_ids' => $ids,
            'group_id' => $group->id,
        ])->assertOk();

        /*
         * Read without the team scope, because this assertion runs outside a
         * request and TeamContext::id() is null by then -- the scope would
         * filter x_accounts to nothing and report an empty group that is
         * actually full. What is being checked is the pivot, not the scope.
         */
        $this->assertSame(2, $group->accounts()->withoutGlobalScopes()->count());

        // The denormalised counter the group list renders, which is what would
        // be wrong if the observer stopped firing.
        $this->assertSame(2, $group->fresh()->accounts_count);
    }

    #[Test]
    public function renaming_keeps_the_members(): void
    {
        $group = AccountGroup::factory()->create(['team_id' => $this->team->id, 'name' => 'Cũ']);
        $group->accounts()->attach($this->account()->id);

        $this->putJson("/api/v1/account-groups/{$group->id}", ['name' => 'Mới'])
            ->assertOk()
            ->assertJsonPath('name', 'Mới');

        $this->assertSame(1, $group->accounts()->count());
    }

    #[Test]
    public function deleting_a_group_keeps_the_accounts(): void
    {
        // A mis-click on "Xoá nhóm" must not cost the accounts themselves.
        $group = AccountGroup::factory()->create(['team_id' => $this->team->id]);
        $account = $this->account();
        $group->accounts()->attach($account->id);

        $this->deleteJson("/api/v1/account-groups/{$group->id}")->assertNoContent();

        $this->assertNotNull($account->fresh());
        $this->assertSame(0, AccountGroup::query()->count());
    }

    #[Test]
    public function another_teams_groups_are_not_listed(): void
    {
        AccountGroup::factory()->create(['name' => 'Của người khác']);
        AccountGroup::factory()->create(['team_id' => $this->team->id, 'name' => 'Của tôi']);

        $this->getJson('/api/v1/account-groups')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Của tôi');
    }
}
