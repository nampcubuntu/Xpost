<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Session auth for the SPA (Sanctum, cookie-based) plus token issuing for
 * automation clients.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        if (! Auth::attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            $request->boolean('remember'),
        )) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $this->regenerateSession($request);

        return $this->me($request);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(status: 204);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'team_name' => ['required', 'string', 'max:255'],
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $team = Team::create([
                'name' => $data['team_name'],
                'slug' => Str::slug($data['team_name']).'-'.Str::lower(Str::random(6)),
                'owner_id' => $user->id,
            ]);

            $team->members()->attach($user->id, ['role' => 'owner']);
            $user->forceFill(['current_team_id' => $team->id])->save();

            return $user;
        });

        Auth::login($user);
        $this->regenerateSession($request);

        return $this->me($request);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('teams:id,name,slug');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'timezone' => $user->timezone,
                'current_team_id' => $user->current_team_id,
                'role' => $user->current_team_id ? $user->roleOn($user->current_team_id) : null,
                'role_label' => $user->current_team_id
                    ? $user->teamRole($user->current_team_id)?->label()
                    : null,
                /*
                 * What this user may do, so the UI can leave out what it
                 * cannot. A button that returns 403 reads as a bug rather than
                 * as a boundary -- and the server still decides, this only
                 * saves someone the trip.
                 */
                'abilities' => $user->abilities(),
            ],
            'teams' => $user->teams->map(fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'role' => $team->pivot->role,
            ]),
        ]);
    }

    public function switchTeam(Request $request): JsonResponse
    {
        $data = $request->validate(['team_id' => ['required', 'integer']]);

        if (! $request->user()->belongsToTeam($data['team_id'])) {
            abort(403, __('xpost.errors.not_a_member'));
        }

        $request->user()->forceFill(['current_team_id' => $data['team_id']])->save();

        return $this->me($request);
    }

    /** Personal access token for scripts and CI. */
    public function issueToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['array'],
            'abilities.*' => ['string'],
        ]);

        $token = $request->user()->createToken($data['name'], $data['abilities'] ?? ['*']);

        return response()->json([
            'token' => $token->plainTextToken,
            'name' => $data['name'],
        ], 201);
    }

    /**
     * Session fixation guard for the SPA.
     *
     * Only stateful requests (Sanctum matched the origin against
     * SANCTUM_STATEFUL_DOMAINS) carry a session. A token client hitting this
     * endpoint has none, and must get a clean response rather than a 500.
     */
    private function regenerateSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
    }
}
