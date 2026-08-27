<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\XAccount;
use App\Support\TeamContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One customer must never be served another's data.
 *
 * Under PHP-FPM the process ends after each request and takes the tenant
 * context with it, so a leak was impossible by accident. Under a long-lived
 * worker -- Octane, FrankenPHP, an artisan process handling several tenants --
 * it is not, and a context left behind is read by TeamScope on the next
 * request. That failure appears in production, intermittently, and never in a
 * suite that does one request per test.
 *
 * These tests do two in a row on purpose.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_request_does_not_inherit_the_previous_tenant(): void
    {
        [$teamA, $userA] = $this->tenant();
        XAccount::factory()->create(['team_id' => $teamA->id, 'username' => 'belongs_to_a']);

        [$teamB, $userB] = $this->tenant();
        XAccount::factory()->create(['team_id' => $teamB->id, 'username' => 'belongs_to_b']);

        $first = $this->actingAs($userA)->getJson('/api/v1/accounts')->assertOk();
        $this->assertSame(['belongs_to_a'], collect($first->json('data'))->pluck('username')->all());

        $second = $this->actingAs($userB)->getJson('/api/v1/accounts')->assertOk();
        $this->assertSame(['belongs_to_b'], collect($second->json('data'))->pluck('username')->all());
    }

    #[Test]
    public function the_context_is_cleared_when_the_request_ends(): void
    {
        // What makes the previous test hold on a worker that does not die.
        [, $user] = $this->tenant();

        TeamContext::set(null);

        $this->actingAs($user)->getJson('/api/v1/accounts')->assertOk();

        $this->assertNull(TeamContext::id());
    }

    #[Test]
    public function the_context_is_cleared_even_when_the_request_throws(): void
    {
        /*
         * The dangerous case: an unhandled error leaves the tenant set, and
         * the next request through that worker reads it. A leak that only
         * happens after an error is a leak that happens under load.
         */
        [, $user] = $this->tenant();

        TeamContext::set(null);

        // Handled, so the throw happens inside the pipeline the middleware
        // wraps -- which is the case the finally has to cover.
        $this->actingAs($user)
            ->getJson('/api/v1/accounts/999999')
            ->assertNotFound();

        $this->assertNull(TeamContext::id());
    }

    #[Test]
    public function an_unknown_team_header_is_refused_rather_than_scoped_wide(): void
    {
        // Naming someone else's team must not widen the scope, and must not
        // fall through to an unscoped query either.
        [$teamA, $userA] = $this->tenant();
        [$teamB] = $this->tenant();

        XAccount::factory()->create(['team_id' => $teamB->id, 'username' => 'belongs_to_b']);

        $this->actingAs($userA)
            ->withHeader('X-Team-Id', (string) $teamB->id)
            ->getJson('/api/v1/accounts')
            ->assertForbidden();
    }

    #[Test]
    public function a_refused_team_header_still_clears_the_context(): void
    {
        [, $userA] = $this->tenant();
        [$teamB] = $this->tenant();

        TeamContext::set(null);

        $this->actingAs($userA)
            ->withHeader('X-Team-Id', (string) $teamB->id)
            ->getJson('/api/v1/accounts')
            ->assertForbidden();

        $this->assertNull(TeamContext::id());
    }
}
