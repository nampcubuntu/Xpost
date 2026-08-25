<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Team;
use App\Models\User;
use App\Support\TeamContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a team with an owner and make it the active tenant.
     *
     * Almost every model is behind TeamScope, so a test that skips this ends up
     * querying with no tenant and silently sees nothing.
     *
     * @return array{0: Team, 1: User}
     */
    protected function tenant(string $role = 'owner'): array
    {
        $user = User::factory()->create();

        $team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-'.Str::lower(Str::random(8)),
            'owner_id' => $user->id,
            'timezone' => 'Asia/Ho_Chi_Minh',
        ]);

        $team->members()->attach($user->id, ['role' => $role]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        TeamContext::set($team->id);

        return [$team, $user];
    }

    protected function tearDown(): void
    {
        TeamContext::set(null);

        parent::tearDown();
    }
}
