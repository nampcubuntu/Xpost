<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A customer who signs up becomes admin of their own team, never owner.
 *
 * Admin is everything the work needs -- accounts, apps, budgets, publishing.
 * The two things it withholds are the two that are not a tenant's to decide:
 * deleting an X app, which orphans every account assigned to it, and appointing
 * owners, which is how a tenant would otherwise hand itself the operator's
 * position.
 */
class RegistrationRoleTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $email, string $team = 'Acme'): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Someone',
            'email' => $email,
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
            'team_name' => $team,
        ])->assertOk();
    }

    private function platformOwner(): User
    {
        $owner = User::factory()->create(['email' => 'operator@xpost.test']);
        config(['xpost.platform_owner_email' => 'operator@xpost.test']);

        return $owner;
    }

    #[Test]
    public function a_customer_signing_up_gets_admin(): void
    {
        $this->platformOwner();

        $this->register('customer@example.com');

        $customer = User::query()->where('email', 'customer@example.com')->firstOrFail();

        $this->assertSame(TeamRole::Admin->value, $customer->roleOn($customer->current_team_id));
    }

    #[Test]
    public function the_operator_owns_the_team_the_customer_created(): void
    {
        $operator = $this->platformOwner();

        $this->register('customer@example.com');

        $team = Team::query()->where('name', 'Acme')->firstOrFail();

        $this->assertSame($operator->id, $team->owner_id);
        $this->assertSame(TeamRole::Owner->value, $operator->roleOn($team));
    }

    #[Test]
    public function the_customer_cannot_appoint_an_owner(): void
    {
        /*
         * The point of withholding the role: an admin who could grant owner
         * would simply grant it to a second account of their own.
         */
        $this->platformOwner();
        $this->register('customer@example.com');

        $customer = User::query()->where('email', 'customer@example.com')->firstOrFail();
        $team = Team::query()->where('name', 'Acme')->firstOrFail();

        $other = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($other->id, ['role' => TeamRole::Editor->value]);

        $this->actingAs($customer->refresh())
            ->putJson("/api/v1/members/{$other->id}", ['role' => 'owner'])
            ->assertForbidden();
    }

    #[Test]
    public function the_customer_can_still_run_their_own_team(): void
    {
        // Withholding owner must not withhold the job: accounts, apps, budgets
        // and publishing are all still theirs.
        $this->platformOwner();
        $this->register('customer@example.com');

        $abilities = User::query()->where('email', 'customer@example.com')->firstOrFail()->abilities();

        $this->assertTrue($abilities['compose']);
        $this->assertTrue($abilities['manage_accounts']);
        $this->assertTrue($abilities['manage_infrastructure']);
        $this->assertTrue($abilities['manage_budget']);
        $this->assertFalse($abilities['delete_app']);
    }

    #[Test]
    public function the_first_account_on_a_fresh_install_is_the_owner(): void
    {
        /*
         * Nobody is above it yet, and refusing to make an owner here would
         * produce a team with none at all -- which nothing could then repair.
         */
        config(['xpost.platform_owner_email' => null]);

        $this->register('first@example.com');

        $first = User::query()->where('email', 'first@example.com')->firstOrFail();

        $this->assertSame(TeamRole::Owner->value, $first->roleOn($first->current_team_id));
    }

    #[Test]
    public function a_configured_owner_who_does_not_exist_yet_does_not_break_signup(): void
    {
        // Naming an account that has not been created is a misconfiguration,
        // not a reason to refuse every registration.
        config(['xpost.platform_owner_email' => 'nobody@xpost.test']);

        $this->register('customer@example.com');

        $customer = User::query()->where('email', 'customer@example.com')->firstOrFail();

        $this->assertSame(TeamRole::Owner->value, $customer->roleOn($customer->current_team_id));
    }

    #[Test]
    public function each_customer_gets_their_own_team(): void
    {
        // The operator owns all of them; the customers cannot see each other.
        $this->platformOwner();

        $this->register('one@example.com', 'One Co');

        // Registering logs you in, and the route is guest-only. This test is
        // about the teams, not about the sign-out flow.
        Auth::logout();
        $this->flushSession();

        $this->register('two@example.com', 'Two Co');

        $one = User::query()->where('email', 'one@example.com')->firstOrFail();
        $two = User::query()->where('email', 'two@example.com')->firstOrFail();

        $this->assertNotSame($one->current_team_id, $two->current_team_id);
        $this->assertFalse($one->belongsToTeam($two->current_team_id));
    }
}
