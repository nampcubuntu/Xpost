<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What each role may do.
 *
 * The roles existed as string literals repeated across three policies, with a
 * slightly different list in each -- which is how a role ends up meaning one
 * thing for posts and another for accounts, and how a controller gets written
 * with no check at all.
 */
class TeamRoleTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team] = $this->tenant();

        // The short-link store is reached through the team's own namespace.
        $this->team->forceFill(['cloudflare_kv_namespace_id' => 'ns456'])->save();
    }

    private function userWith(TeamRole $role): User
    {
        $user = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->members()->attach($user->id, ['role' => $role->value]);

        /*
         * Re-read rather than reused. A factory-built model holds only what the
         * factory set, and under preventAccessingMissingAttributes a column
         * filled by its database default -- locale, timezone -- throws on
         * access. Production always retrieves the user from the database, so
         * the fixture should too.
         */
        return $user->refresh();
    }

    #[Test]
    public function roles_are_ordered_by_power(): void
    {
        // atLeast() is what removes the repeated ['owner', 'admin'] arrays, so
        // a role added later cannot be missing from a list nobody updated.
        $this->assertTrue(TeamRole::Owner->atLeast(TeamRole::Admin));
        $this->assertTrue(TeamRole::Admin->atLeast(TeamRole::Editor));
        $this->assertTrue(TeamRole::Editor->atLeast(TeamRole::Member));

        $this->assertFalse(TeamRole::Editor->atLeast(TeamRole::Admin));
        $this->assertFalse(TeamRole::Member->atLeast(TeamRole::Editor));
    }

    #[Test]
    public function a_member_reads_and_changes_nothing(): void
    {
        $abilities = TeamRole::Member->abilities();

        $this->assertTrue($abilities['view']);
        $this->assertFalse($abilities['compose']);
        $this->assertFalse($abilities['manage_accounts']);
        $this->assertFalse($abilities['manage_budget']);
    }

    #[Test]
    public function an_editor_publishes_but_does_not_touch_the_plumbing(): void
    {
        // Accounts, apps and links are shared: one wrong edit reaches every
        // campaign the team is running.
        $abilities = TeamRole::Editor->abilities();

        $this->assertTrue($abilities['compose']);
        $this->assertTrue($abilities['publish']);
        $this->assertFalse($abilities['manage_accounts']);
        $this->assertFalse($abilities['manage_infrastructure']);
    }

    #[Test]
    public function only_the_owner_deletes_an_app(): void
    {
        // Deleting an app orphans every account assigned to it.
        $this->assertTrue(TeamRole::Owner->abilities()['delete_app']);
        $this->assertFalse(TeamRole::Admin->abilities()['delete_app']);
    }

    #[Test]
    public function the_short_link_store_is_closed_to_members(): void
    {
        /*
         * The controller shipped with no authorization at all, so any member
         * could rewrite or empty a store a Worker reads for live traffic --
         * deleting a key does not unpublish anything, it makes a link in a live
         * post stop resolving.
         */
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        config([
            'xpost.links.account_id' => 'acc',
            'xpost.links.api_token' => 'tok',
        ]);

        $this->actingAs($this->userWith(TeamRole::Member))
            ->postJson('/api/v1/links', ['value' => 'https://a|https://b'])
            ->assertForbidden();

        $this->actingAs($this->userWith(TeamRole::Editor))
            ->postJson('/api/v1/links', ['value' => 'https://a|https://b'])
            ->assertForbidden();

        $this->actingAs($this->userWith(TeamRole::Admin))
            ->postJson('/api/v1/links', ['value' => 'https://a|https://b'])
            ->assertCreated();
    }

    #[Test]
    public function reading_the_short_link_store_is_open_to_the_team(): void
    {
        // Knowing which links exist is not the same as being able to break them.
        Http::fake(['api.cloudflare.com/*' => Http::response([
            'success' => true, 'result' => [], 'result_info' => ['cursor' => ''],
        ])]);

        config([
            'xpost.links.account_id' => 'acc',
            'xpost.links.api_token' => 'tok',
        ]);

        $this->actingAs($this->userWith(TeamRole::Member))
            ->getJson('/api/v1/links')
            ->assertOk();
    }

    #[Test]
    public function the_spending_ceiling_is_not_a_member_setting(): void
    {
        /*
         * It was gated on viewAny, which only means "you are on a team" -- so
         * any member could raise or remove the ceiling on real money at twenty
         * cents a post.
         */
        $this->actingAs($this->userWith(TeamRole::Member))
            ->putJson('/api/v1/publishing/budget', ['daily_budget_usd' => 9999])
            ->assertForbidden();

        $this->actingAs($this->userWith(TeamRole::Editor))
            ->putJson('/api/v1/publishing/budget', ['daily_budget_usd' => 9999])
            ->assertForbidden();

        $this->actingAs($this->userWith(TeamRole::Admin))
            ->putJson('/api/v1/publishing/budget', ['daily_budget_usd' => 50])
            ->assertOk();
    }

    #[Test]
    public function the_api_tells_the_ui_what_this_role_may_do(): void
    {
        // So the UI can leave out what it cannot do. A button that 403s reads
        // as a bug rather than as a boundary.
        $this->actingAs($this->userWith(TeamRole::Editor))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.role', 'editor')
            ->assertJsonPath('user.abilities.compose', true)
            ->assertJsonPath('user.abilities.manage_infrastructure', false);
    }

    #[Test]
    public function an_unknown_role_grants_nothing(): void
    {
        // A typo in the pivot must fail closed, not fall through to allowed.
        $user = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->members()->attach($user->id, ['role' => 'superuser']);

        $this->assertNull($user->teamRole($this->team->id));
        $this->assertFalse($user->isAtLeast($this->team->id, TeamRole::Member));
        $this->assertFalse($user->abilities()['compose']);
    }

    #[Test]
    public function someone_on_no_team_gets_nothing(): void
    {
        $user = User::factory()->create(['current_team_id' => null]);

        $this->assertFalse($user->isAtLeast(null, TeamRole::Member));
        $this->assertSame([], array_filter($user->abilities()));
    }
}
