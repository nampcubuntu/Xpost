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
        $team = $this->team($request);

        $this->authorize('viewAny', User::class);

        return response()->json([
            'data' => $team->members()->orderBy('name')->get()->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot->role,
                'role_label' => TeamRole::tryFromRole($member->pivot->role)?->label() ?? $member->pivot->role,
                'is_you' => $member->id === $request->user()->id,
            ]),
            'roles' => collect(TeamRole::cases())->map(fn (TeamRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                // What the caller may actually hand out.
                'assignable' => $this->actingRole($request)?->atLeast($role) ?? false,
            ]),
        ]);
    }

    /** Add someone who already has an account. */
    public function store(Request $request): JsonResponse
    {
        $team = $this->team($request);
        $this->authorize('create', User::class);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);

        $role = TeamRole::from($data['role']);
        $this->assertCanGrant($request, $role);

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
        $team = $this->team($request);
        $this->authorize('update', $member);

        $data = $request->validate(['role' => ['required', Rule::enum(TeamRole::class)]]);
        $role = TeamRole::from($data['role']);

        $this->assertOnTeam($team, $member);
        $this->assertNotSelf($request, $member, 'Không tự đổi vai trò của chính mình.');
        $this->assertCanGrant($request, $role);
        $this->assertOwnerRemains($team, $member, $role);

        $team->members()->updateExistingPivot($member->id, ['role' => $role->value]);

        return response()->json(['data' => ['id' => $member->id, 'role' => $role->value]]);
    }

    public function destroy(Request $request, User $member): JsonResponse
    {
        $team = $this->team($request);
        $this->authorize('delete', $member);

        $this->assertOnTeam($team, $member);
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

    private function actingRole(Request $request): ?TeamRole
    {
        return $request->user()->teamRole($request->user()->current_team_id);
    }

    /**
     * Nobody hands out a role above their own.
     *
     * Without this an admin promotes themselves to owner through a second
     * account, and the level stops meaning anything.
     */
    private function assertCanGrant(Request $request, TeamRole $role): void
    {
        abort_unless(
            $this->actingRole($request)?->atLeast($role) ?? false,
            403,
            'Không thể cấp vai trò cao hơn vai trò của bạn.',
        );
    }

    private function assertNotSelf(Request $request, User $member, string $message): void
    {
        abort_if($member->id === $request->user()->id, 422, $message);
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
