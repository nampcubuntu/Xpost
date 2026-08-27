<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Managing who is on a team.
 *
 * Three rules are enforced rather than left to care, because each one locks
 * somebody out of their own account when it goes wrong: a team keeps at least
 * one owner, nobody grants a role above their own, and nobody changes their own.
 */
class TeamMembersTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $this->owner] = $this->tenant();
        $this->owner->refresh();
    }

    private function memberWith(TeamRole $role, ?string $email = null): User
    {
        $user = User::factory()->create([
            'current_team_id' => $this->team->id,
            'email' => $email ?? fake()->unique()->safeEmail(),
        ]);

        $this->team->members()->attach($user->id, ['role' => $role->value]);

        // Re-read: a factory model holds only what the factory set, and columns
        // filled by a database default throw under preventAccessingMissingAttributes.
        return $user->refresh();
    }

    #[Test]
    public function the_team_can_see_who_is_on_it(): void
    {
        // Ordinary information: it explains whose campaigns these are.
        $this->memberWith(TeamRole::Member);

        $this->actingAs($this->memberWith(TeamRole::Member))
            ->getJson('/api/v1/members')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function an_admin_changes_a_role(): void
    {
        $member = $this->memberWith(TeamRole::Member);

        $this->actingAs($this->memberWith(TeamRole::Admin))
            ->putJson("/api/v1/members/{$member->id}", ['role' => 'editor'])
            ->assertOk();

        $this->assertSame('editor', $member->fresh()->roleOn($this->team));
    }

    #[Test]
    public function an_editor_cannot_change_anyones_role(): void
    {
        // Adding a member hands them the whole account; changing a role hands
        // them more of it.
        $member = $this->memberWith(TeamRole::Member);

        $this->actingAs($this->memberWith(TeamRole::Editor))
            ->putJson("/api/v1/members/{$member->id}", ['role' => 'admin'])
            ->assertForbidden();
    }

    #[Test]
    public function nobody_grants_a_role_above_their_own(): void
    {
        /*
         * Without this an admin promotes themselves to owner through a second
         * account, and the level stops meaning anything.
         */
        $member = $this->memberWith(TeamRole::Member);

        $this->actingAs($this->memberWith(TeamRole::Admin))
            ->putJson("/api/v1/members/{$member->id}", ['role' => 'owner'])
            ->assertForbidden();
    }

    #[Test]
    public function nobody_changes_their_own_role(): void
    {
        // The other half of self-promotion, and the way someone locks
        // themselves out.
        $admin = $this->memberWith(TeamRole::Admin);

        $this->actingAs($admin)
            ->putJson("/api/v1/members/{$admin->id}", ['role' => 'member'])
            ->assertStatus(422);
    }

    #[Test]
    public function the_last_owner_cannot_be_demoted(): void
    {
        // A team without an owner has nobody who can appoint one.
        $this->actingAs($this->memberWith(TeamRole::Admin))
            ->putJson("/api/v1/members/{$this->owner->id}", ['role' => 'admin'])
            ->assertStatus(422);

        $this->assertSame('owner', $this->owner->fresh()->roleOn($this->team));
    }

    #[Test]
    public function the_last_owner_cannot_be_removed(): void
    {
        $this->actingAs($this->memberWith(TeamRole::Admin))
            ->deleteJson("/api/v1/members/{$this->owner->id}")
            ->assertStatus(422);
    }

    #[Test]
    public function an_owner_can_be_demoted_once_there_is_another(): void
    {
        $second = $this->memberWith(TeamRole::Owner);

        $this->actingAs($second)
            ->putJson("/api/v1/members/{$this->owner->id}", ['role' => 'admin'])
            ->assertOk();

        $this->assertSame('admin', $this->owner->fresh()->roleOn($this->team));
    }

    #[Test]
    public function a_removed_member_is_left_without_an_active_team(): void
    {
        /*
         * Rather than pointing at one they can no longer see -- TeamScope would
         * return nothing and every page would look broken instead of saying
         * what happened.
         */
        $member = $this->memberWith(TeamRole::Member);

        $this->actingAs($this->memberWith(TeamRole::Admin))
            ->deleteJson("/api/v1/members/{$member->id}")
            ->assertNoContent();

        $this->assertNull($member->fresh()->current_team_id);
    }

    #[Test]
    public function someone_who_has_no_account_yet_is_named_rather_than_invented(): void
    {
        // Creating an account for them would set a password nobody chose.
        $this->actingAs($this->memberWith(TeamRole::Admin))
            ->postJson('/api/v1/members', ['email' => 'nobody@example.com', 'role' => 'editor'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Chưa có tài khoản nào dùng email này. Người đó cần đăng ký trước.');
    }

    #[Test]
    public function an_existing_user_can_be_added(): void
    {
        $outsider = User::factory()->create(['email' => 'new@example.com', 'current_team_id' => null]);

        $this->actingAs($this->memberWith(TeamRole::Admin))
            ->postJson('/api/v1/members', ['email' => 'new@example.com', 'role' => 'editor'])
            ->assertCreated();

        $this->assertSame('editor', $outsider->fresh()->roleOn($this->team));
        // Somewhere to land, rather than a 409 for having no active team.
        $this->assertSame($this->team->id, $outsider->fresh()->current_team_id);
    }

    #[Test]
    public function adding_someone_already_on_the_team_is_refused(): void
    {
        $existing = $this->memberWith(TeamRole::Member, 'already@example.com');

        $this->actingAs($this->memberWith(TeamRole::Admin))
            ->postJson('/api/v1/members', ['email' => $existing->email, 'role' => 'admin'])
            ->assertStatus(422);
    }

    #[Test]
    public function the_operator_is_not_listed_to_a_customer(): void
    {
        /*
         * They hold owner on every team so a tenant cannot delete an app or
         * appoint owners -- but listing them shows every customer the
         * operator's name and email, and offers buttons that only ever refuse.
         * They are plumbing, not a teammate.
         */
        config(['xpost.platform_owner_email' => 'operator@xpost.test']);

        $operator = User::factory()->create(['email' => 'operator@xpost.test']);
        $this->team->members()->attach($operator->id, ['role' => TeamRole::Owner->value]);

        $emails = collect(
            $this->actingAs($this->memberWith(TeamRole::Admin))
                ->getJson('/api/v1/members')
                ->assertOk()
                ->json('data')
        )->pluck('email');

        $this->assertFalse($emails->contains('operator@xpost.test'));
    }

    #[Test]
    public function the_operator_sees_themselves(): void
    {
        // Hidden from customers, not from the person who runs it.
        config(['xpost.platform_owner_email' => 'operator@xpost.test']);

        $operator = User::factory()->create([
            'email' => 'operator@xpost.test',
            'current_team_id' => $this->team->id,
        ]);
        $this->team->members()->attach($operator->id, ['role' => TeamRole::Owner->value]);

        $emails = collect(
            $this->actingAs($operator->refresh())->getJson('/api/v1/members')->json('data')
        )->pluck('email');

        $this->assertTrue($emails->contains('operator@xpost.test'));
    }

    #[Test]
    public function a_customer_cannot_edit_the_operator_by_id(): void
    {
        // Hidden from the list, so reaching this needs a hand-made request --
        // which is exactly when it matters.
        config(['xpost.platform_owner_email' => 'operator@xpost.test']);

        $operator = User::factory()->create(['email' => 'operator@xpost.test']);
        $this->team->members()->attach($operator->id, ['role' => TeamRole::Owner->value]);

        $admin = $this->memberWith(TeamRole::Admin);

        $this->actingAs($admin)
            ->putJson("/api/v1/members/{$operator->id}", ['role' => 'member'])
            ->assertNotFound();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/members/{$operator->id}")
            ->assertNotFound();
    }

    #[Test]
    public function the_operator_sees_every_team_at_once(): void
    {
        /*
         * They are already on all of them, so this shows nothing they could not
         * reach by switching teams one at a time -- it stops that being the only
         * way to answer "who is on my platform".
         */
        config(['xpost.platform_owner_email' => 'operator@xpost.test']);

        $operator = User::factory()->create([
            'email' => 'operator@xpost.test',
            'current_team_id' => $this->team->id,
        ]);
        $this->team->members()->attach($operator->id, ['role' => TeamRole::Owner->value]);

        [$other, $stranger] = $this->tenant();
        $other->members()->attach($operator->id, ['role' => TeamRole::Owner->value]);

        $rows = $this->actingAs($operator->refresh())
            ->getJson('/api/v1/members')
            ->assertOk()
            ->assertJsonPath('spans_teams', true)
            ->json('data');

        $this->assertTrue(collect($rows)->pluck('email')->contains($stranger->email));
        // Every row names its team, or the same person on two of them is
        // ambiguous.
        $this->assertTrue(collect($rows)->every(fn ($row) => filled($row['team_name'])));
    }

    #[Test]
    public function the_operator_edits_a_member_of_another_team(): void
    {
        config(['xpost.platform_owner_email' => 'operator@xpost.test']);

        $operator = User::factory()->create([
            'email' => 'operator@xpost.test',
            'current_team_id' => $this->team->id,
        ]);
        $this->team->members()->attach($operator->id, ['role' => TeamRole::Owner->value]);

        [$other] = $this->tenant();
        $their = User::factory()->create(['current_team_id' => $other->id]);
        $other->members()->attach($their->id, ['role' => TeamRole::Member->value]);

        // The row carries its own team; "the team I am signed into" would name
        // the wrong one.
        $this->actingAs($operator->refresh())
            ->putJson("/api/v1/members/{$their->id}", ['role' => 'admin', 'team_id' => $other->id])
            ->assertOk();

        $this->assertSame('admin', $their->fresh()->roleOn($other));
    }

    #[Test]
    public function a_customer_admin_stays_inside_their_own_team(): void
    {
        // The other half of the same rule: naming another team must not reach
        // into it.
        [$other] = $this->tenant();
        $their = User::factory()->create(['current_team_id' => $other->id]);
        $other->members()->attach($their->id, ['role' => TeamRole::Member->value]);

        $this->actingAs($this->memberWith(TeamRole::Admin))
            ->putJson("/api/v1/members/{$their->id}", ['role' => 'admin', 'team_id' => $other->id])
            ->assertNotFound();

        $this->assertSame('member', $their->fresh()->roleOn($other));
    }

    #[Test]
    public function a_customer_never_sees_another_team(): void
    {
        [$other, $stranger] = $this->tenant();

        $rows = $this->actingAs($this->memberWith(TeamRole::Admin))
            ->getJson('/api/v1/members')
            ->assertOk()
            ->assertJsonPath('spans_teams', false)
            ->json('data');

        $this->assertFalse(collect($rows)->pluck('email')->contains($stranger->email));
    }

    #[Test]
    public function a_member_of_another_team_is_not_reachable(): void
    {
        // Route model binding finds any user by id; the team check is what
        // stops one team editing another's people.
        [, $stranger] = $this->tenant();

        $this->actingAs($this->memberWith(TeamRole::Admin))
            ->putJson("/api/v1/members/{$stranger->id}", ['role' => 'member'])
            ->assertNotFound();
    }
}
