<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One handle being watched, and the last thing X's search said about it.
 *
 * Not tied to a connected account: the question is asked about names, and the
 * useful ones include handles this install has never posted from.
 */
class VisibilityCheck extends Model
{
    use BelongsToTeam;

    protected $fillable = ['team_id', 'handle', 'state', 'checked_at', 'detail'];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * What a pasted line actually names.
     *
     * People paste what they have: a bare handle, an @ in front, a whole
     * profile URL, in whatever case X showed it to them. All of those are one
     * handle, and storing them as typed would put the same account in the list
     * three times.
     */
    public static function normalise(string $raw): string
    {
        $value = trim($raw);

        if (str_contains($value, '/')) {
            $path = parse_url($value, PHP_URL_PATH) ?: $value;
            $value = basename(rtrim($path, '/'));
        }

        return strtolower(ltrim(trim($value), '@'));
    }

    /** Unchecked first -- they are the ones the operator just added. */
    public function scopeUncheckedFirst(Builder $query): Builder
    {
        return $query->orderByRaw('checked_at is null desc')
            ->orderByDesc('checked_at')
            ->orderBy('handle');
    }
}
