<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'current_team_id', 'locale', 'timezone'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('role')->withTimestamps();
    }

    public function ownedTeams(): BelongsToMany
    {
        return $this->teams()->wherePivot('role', 'owner');
    }

    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function belongsToTeam(Team|int $team): bool
    {
        $id = $team instanceof Team ? $team->id : $team;

        return $this->teams()->whereKey($id)->exists();
    }

    public function roleOn(Team|int $team): ?string
    {
        $id = $team instanceof Team ? $team->id : $team;

        return $this->teams()->whereKey($id)->first()?->pivot->role;
    }

    public function hasTeamRole(Team|int $team, string ...$roles): bool
    {
        return in_array($this->roleOn($team), $roles, true);
    }
}
