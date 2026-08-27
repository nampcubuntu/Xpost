<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may open the queue dashboard outside local.
 *
 * Horizon shows every team's jobs at once -- payloads, failures, account ids --
 * so it sits above the tenant hierarchy entirely: being owner of one team is
 * not a reason to see another team's queue.
 *
 * The gate shipped as an empty array, which locked out everyone including the
 * operator. Nobody noticed because Horizon lets anyone in on local, so the
 * first sign would have been losing the dashboard on the day it was needed.
 */
class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        return User::factory()->create(['email' => $email])->refresh();
    }

    #[Test]
    public function a_listed_operator_gets_in(): void
    {
        config(['xpost.horizon_emails' => 'ops@xpost.test']);

        $this->assertTrue(Gate::forUser($this->user('ops@xpost.test'))->allows('viewHorizon'));
    }

    #[Test]
    public function anyone_else_does_not(): void
    {
        config(['xpost.horizon_emails' => 'ops@xpost.test']);

        $this->assertFalse(Gate::forUser($this->user('someone@example.com'))->allows('viewHorizon'));
    }

    #[Test]
    public function owning_a_team_is_not_a_way_in(): void
    {
        /*
         * The dashboard is cross-tenant. A customer who owns their own team
         * has no business reading another customer's job payloads.
         */
        config(['xpost.horizon_emails' => 'ops@xpost.test']);

        [$team] = $this->tenant();
        $owner = $this->user('customer@example.com');
        $team->members()->attach($owner->id, ['role' => TeamRole::Owner->value]);

        $this->assertFalse(Gate::forUser($owner)->allows('viewHorizon'));
    }

    #[Test]
    public function several_operators_can_be_listed(): void
    {
        config(['xpost.horizon_emails' => 'a@xpost.test, b@xpost.test']);

        $this->assertTrue(Gate::forUser($this->user('a@xpost.test'))->allows('viewHorizon'));
        // Whitespace around a comma is how a list gets typed, not a mistake.
        $this->assertTrue(Gate::forUser($this->user('b@xpost.test'))->allows('viewHorizon'));
    }

    #[Test]
    public function the_platform_owner_gets_in_when_nothing_is_listed(): void
    {
        // A correct install must not be locked out of its own dashboard by an
        // unset variable -- the failure this replaces.
        config([
            'xpost.horizon_emails' => null,
            'xpost.platform_owner_email' => 'operator@xpost.test',
        ]);

        $this->assertTrue(Gate::forUser($this->user('operator@xpost.test'))->allows('viewHorizon'));
    }

    #[Test]
    public function nothing_configured_at_all_lets_nobody_in(): void
    {
        // Fail closed. An unconfigured dashboard open to every signed-in user
        // would be worse than one nobody can reach.
        config(['xpost.horizon_emails' => null, 'xpost.platform_owner_email' => null]);

        $this->assertFalse(Gate::forUser($this->user('anyone@example.com'))->allows('viewHorizon'));
    }

    #[Test]
    public function a_signed_out_visitor_is_refused(): void
    {
        config(['xpost.horizon_emails' => 'ops@xpost.test']);

        $this->assertFalse(Gate::allows('viewHorizon'));
    }

    #[Test]
    public function the_address_is_matched_regardless_of_case(): void
    {
        // Email is case-insensitive in practice, and a capital letter in .env
        // should not be the reason nobody can open the dashboard.
        config(['xpost.horizon_emails' => 'Ops@Xpost.test']);

        $this->assertTrue(Gate::forUser($this->user('ops@xpost.test'))->allows('viewHorizon'));
    }
}
