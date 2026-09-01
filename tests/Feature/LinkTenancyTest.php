<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\ShortLink;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One team's short links are not another's.
 *
 * The namespace id was a single global setting, so every team read and wrote
 * the same Cloudflare store: any admin could list another team's links and
 * delete them -- which does not unpublish anything, it makes a link in somebody
 * else's live post stop resolving, quietly, for everyone who clicks it.
 *
 * It was the only tenancy hole left once every model had a team scope, and it
 * was invisible precisely because no model was involved.
 */
class LinkTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The account and token are the operator's; only the namespace is not.
        config(['xpost.links.account_id' => 'acc', 'xpost.links.api_token' => 'tok']);
    }

    /** @return array{Team, User} */
    private function teamWith(?string $namespace): array
    {
        [$team, $user] = $this->tenant();
        $team->forceFill(['cloudflare_kv_namespace_id' => $namespace])->save();

        return [$team, $user];
    }

    #[Test]
    public function each_team_reads_its_own_namespace(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response([
            'success' => true, 'result' => [], 'result_info' => ['cursor' => ''],
        ])]);

        [$firstTeam, $first] = $this->teamWith('aaaa1111');
        [$secondTeam, $second] = $this->teamWith('bbbb2222');

        /*
         * A row each, because the page reads values only for the keys it is
         * showing -- an empty index sends no request at all, and this test
         * is about which namespace the request names.
         */
        ShortLink::create(['team_id' => $firstTeam->id, 'key' => 'r:one', 'created_at' => now()]);
        ShortLink::create(['team_id' => $secondTeam->id, 'key' => 'r:two', 'created_at' => now()]);

        $this->actingAs($first)->getJson('/api/v1/links')->assertOk();
        $this->actingAs($second)->getJson('/api/v1/links')->assertOk();

        // Cloudflare puts the namespace in the path, so the isolation is
        // visible in the request rather than resting on a WHERE clause.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/namespaces/aaaa1111/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/namespaces/bbbb2222/'));
    }

    #[Test]
    public function a_team_never_writes_into_anothers_namespace(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        [, $mine] = $this->teamWith('aaaa1111');
        $this->teamWith('bbbb2222');

        $this->actingAs($mine)
            ->postJson('/api/v1/links', ['value' => 'https://a|https://b'])
            ->assertCreated();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'bbbb2222'));
    }

    #[Test]
    public function a_team_with_no_namespace_has_no_store(): void
    {
        /*
         * Not a fallback to a global namespace -- that fallback is the sharing
         * this exists to prevent. No namespace means not configured.
         */
        [, $user] = $this->teamWith(null);

        $this->actingAs($user)
            ->getJson('/api/v1/links')
            ->assertStatus(422)
            ->assertJsonPath('configured', false)
            // The operator's half is set, so the fix belongs to this team.
            ->assertJsonPath('needs_namespace', true);

        Http::assertNothingSent();
    }

    #[Test]
    public function an_admin_can_point_their_team_at_a_namespace(): void
    {
        [$team, $user] = $this->teamWith(null);

        $this->actingAs($user)
            ->putJson('/api/v1/link-namespace', ['namespace_id' => str_repeat('a', 32)])
            ->assertOk();

        $this->assertSame(str_repeat('a', 32), $team->fresh()->cloudflare_kv_namespace_id);
    }

    #[Test]
    public function an_editor_cannot(): void
    {
        // Repointing the store is repointing every link the team publishes.
        [$team] = $this->teamWith(null);

        $editor = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($editor->id, ['role' => TeamRole::Editor->value]);

        $this->actingAs($editor->refresh())
            ->putJson('/api/v1/link-namespace', ['namespace_id' => str_repeat('a', 32)])
            ->assertForbidden();
    }

    #[Test]
    public function something_that_is_not_a_namespace_id_is_refused(): void
    {
        // Cloudflare ids are 32 hex characters. Storing a pasted URL instead
        // would fail later as an unhelpful 404 from their API.
        [, $user] = $this->teamWith(null);

        $this->actingAs($user)
            ->putJson('/api/v1/link-namespace', ['namespace_id' => 'https://dash.cloudflare.com/…'])
            ->assertStatus(422);
    }

    #[Test]
    public function the_namespace_can_be_cleared(): void
    {
        [$team, $user] = $this->teamWith('aaaa1111aaaa1111aaaa1111aaaa1111');

        $this->actingAs($user)
            ->putJson('/api/v1/link-namespace', ['namespace_id' => null])
            ->assertOk();

        $this->assertNull($team->fresh()->cloudflare_kv_namespace_id);
    }
}
