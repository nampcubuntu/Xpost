<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Who is on the team and what they may do.
 *
 * Roles were only changeable by editing team_user.role in the database, which
 * means in practice they were never changed -- everyone stayed whatever they
 * were created as, and the levels the policies enforce went unused.
 *
 * Three rules are enforced here rather than left to care, because each one
 * locks somebody out of their own account when it goes wrong:
 *
 *  - a team always keeps at least one owner
 *  - nobody grants a role above their own
 *  - nobody changes their own role
 */
class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $isOperator = $request->user()->isPlatformOperator();

        /*
         * The operator sees every team at once; everyone else sees their own.
         *
         * They are already a member of all of them, so this shows nothing they
         * could not reach by switching teams one at a time -- it just stops
         * that being the only way to answer "who is on my platform".
         */
        $teams = $isOperator
            ? Team::query()->orderBy('name')->get()
            : Team::query()->whereKey($request->user()->current_team_id)->get();

        $rows = collect();

        foreach ($teams as $team) {
            foreach ($team->members()->orderBy('name')->get() as $member) {
                /*
                 * The operator is on every team and is not a teammate.
                 *
                 * They hold owner on all of them so a customer cannot delete an
                 * app or appoint owners -- but listing them shows every customer
                 * the operator's name and email, and offers buttons that only
                 * ever refuse. They are plumbing, not membership.
                 */
                if ($member->isPlatformOperator() && ! $isOperator) {
                    continue;
                }

                $rows->push([
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'team_id' => $team->id,
                    'team_name' => $team->name,
                    'role' => $member->pivot->role,
                    'role_label' => TeamRole::tryFromRole($member->pivot->role)?->label() ?? $member->pivot->role,
                    'is_you' => $member->id === $request->user()->id,
                ]);
            }
        }

        return response()->json([
            'data' => $rows->values(),
            // Only the operator's view spans teams, so only it needs the column.
            'spans_teams' => $isOperator,
            'roles' => collect(TeamRole::cases())->map(fn (TeamRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                // What the caller may actually hand out.
                'assignable' => $isOperator || ($this->actingRole($request)?->atLeast($role) ?? false),
            ]),
            'teams' => $isOperator
                ? $teams->map(fn (Team $team) => ['id' => $team->id, 'name' => $team->name])
                : [],
        ]);
    }

    /** Add someone who already has an account. */
    public function store(Request $request): JsonResponse
    {
        $team = $this->targetTeam($request);
        $this->authorize('create', User::class);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);

        $role = TeamRole::from($data['role']);
        $this->assertCanGrant($request, $role, $team);

        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null) {
            return response()->json([
                'message' => 'Chưa có tài khoản nào dùng email này. Người đó cần đăng ký trước.',
            ], 422);
        }

        if ($team->members()->whereKey($user->id)->exists()) {
            return response()->json(['message' => $user->email.' đã ở trong team.'], 422);
        }

        $team->members()->attach($user->id, ['role' => $role->value]);

        // Somewhere to land on first sign-in, rather than a 409 for having no
        // active team.
        if ($user->current_team_id === null) {
            $user->forceFill(['current_team_id' => $team->id])->save();
        }

        return response()->json(status: 201);
    }

    public function update(Request $request, User $member): JsonResponse
    {
        $team = $this->targetTeam($request, $member);
        $this->authorize('update', $member);

        $data = $request->validate(['role' => ['required', Rule::enum(TeamRole::class)]]);
        $role = TeamRole::from($data['role']);

        $this->assertOnTeam($team, $member);
        $this->assertNotTheOperator($request, $member);
        $this->assertNotSelf($request, $member, 'Không tự đổi vai trò của chính mình.');
        $this->assertCanGrant($request, $role, $team);
        $this->assertOwnerRemains($team, $member, $role);

        $team->members()->updateExistingPivot($member->id, ['role' => $role->value]);

        return response()->json(['data' => ['id' => $member->id, 'role' => $role->value]]);
    }

    public function destroy(Request $request, User $member): JsonResponse
    {
        $team = $this->targetTeam($request, $member);
        $this->authorize('delete', $member);

        $this->assertOnTeam($team, $member);
        $this->assertNotTheOperator($request, $member);
        $this->assertNotSelf($request, $member, 'Không tự xoá mình khỏi team.');
        $this->assertOwnerRemains($team, $member, null);

        $team->members()->detach($member->id);

        /*
         * Leave them without an active team rather than pointing at one they
         * can no longer see -- TeamScope would return nothing and every page
         * would look broken instead of saying what happened.
         */
        if ($member->current_team_id === $team->id) {
            $member->forceFill(['current_team_id' => null])->save();
        }

        return response()->json(status: 204);
    }

    private function team(Request $request): Team
    {
        return Team::query()->findOrFail($request->user()->current_team_id);
    }

    /**
     * Which team a request is acting on.
     *
     * A tenant always acts on the one they are signed into. The operator may
     * name another, because their view spans every team and editing a row from
     * it should not require switching first.
     */
    private function targetTeam(Request $request, ?User $member = null): Team
    {
        if (! $request->user()->isPlatformOperator()) {
            return $this->team($request);
        }

        if ($request->filled('team_id')) {
            return Team::query()->findOrFail((int) $request->input('team_id'));
        }

        /*
         * Falling back to the member's own team rather than the caller's: the
         * operator is on every team, so "their current team" says nothing about
         * which row was clicked.
         */
        $teamId = $member?->teams()->first()?->id ?? $request->user()->current_team_id;

        return Team::query()->findOrFail($teamId);
    }

    /**
     * The role the caller is acting with.
     *
     * The operator holds owner everywhere, so their level does not depend on
     * which team is being edited.
     */
    private function actingRole(Request $request, ?Team $team = null): ?TeamRole
    {
        if ($request->user()->isPlatformOperator()) {
            return TeamRole::Owner;
        }

        return $request->user()->teamRole(($team ?? $this->team($request))->id);
    }

    /**
     * Nobody hands out a role above their own.
     *
     * Without this an admin promotes themselves to owner through a second
     * account, and the level stops meaning anything.
     */
    private function assertCanGrant(Request $request, TeamRole $role, ?Team $team = null): void
    {
        abort_unless(
            $this->actingRole($request, $team)?->atLeast($role) ?? false,
            403,
            'Không thể cấp vai trò cao hơn vai trò của bạn.',
        );
    }

    private function assertNotSelf(Request $request, User $member, string $message): void
    {
        abort_if($member->id === $request->user()->id, 422, $message);
    }

    /**
     * A tenant cannot edit the operator out of its own team.
     *
     * They are hidden from the list, so reaching this needs a hand-made
     * request -- which is exactly when it matters. 404 rather than 403: the
     * answer to "is the operator on my team" is not a customer's to have.
     */
    private function assertNotTheOperator(Request $request, User $member): void
    {
        abort_if(
            $member->isPlatformOperator() && ! $request->user()->isPlatformOperator(),
            404,
        );
    }

    private function assertOnTeam(Team $team, User $member): void
    {
        abort_unless($team->members()->whereKey($member->id)->exists(), 404);
    }

    /**
     * A team without an owner has nobody who can appoint one.
     *
     * @param  TeamRole|null  $becoming  null when the member is being removed
     */
    private function assertOwnerRemains(Team $team, User $member, ?TeamRole $becoming): void
    {
        $isOwner = $team->members()->whereKey($member->id)->first()?->pivot->role === TeamRole::Owner->value;

        if (! $isOwner || $becoming === TeamRole::Owner) {
            return;
        }

        $owners = $team->members()->wherePivot('role', TeamRole::Owner->value)->count();

        abort_if($owners <= 1, 422, 'Team phải còn ít nhất một chủ sở hữu.');
    }
}
