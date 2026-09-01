<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per key in the team's KV namespace, holding only its age.
 *
 * The value is deliberately absent. KV is what the Worker resolves against, so
 * a stored value here would be a second answer to the same question and the two
 * would drift. This exists for one reason: KV cannot order by time, and reading
 * every key to sort them costs 642ms per thousand.
 */
class ShortLink extends Model
{
    use BelongsToTeam;

    protected $fillable = ['team_id', 'key', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * Newest first, and the undated last.
     *
     * A backfilled row has whatever KV metadata knew, which for keys written
     * before any of this existed is nothing. Those sort to the bottom by name
     * rather than being given an invented date.
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByRaw('created_at is null')
            ->orderByDesc('created_at')
            ->orderBy('key');
    }

    /** The same prefix filter the KV listing offers, so the box behaves alike. */
    public function scopeKeyPrefix(Builder $query, ?string $prefix): Builder
    {
        return $query->when(
            filled($prefix),
            fn (Builder $q) => $q->where('key', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $prefix).'%')
        );
    }

    /**
     * Record a key this install just wrote.
     *
     * updateOrCreate rather than create: re-pasting a batch to change a value
     * is a normal thing to do, and it must not collide with the unique index.
     * The original age is kept -- rewriting a value does not make the link new.
     */
    public static function remember(int $teamId, string $key): void
    {
        static::query()->firstOrCreate(
            ['team_id' => $teamId, 'key' => $key],
            ['created_at' => now()],
        );
    }
}
