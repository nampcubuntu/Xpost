<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;

/**
 * A short link as TinyURL shortened it.
 *
 * Remembered so the same URL is never shortened twice: a second request mints
 * a second code, and a post already carrying the first becomes untraceable.
 */
class TinyLink extends Model
{
    use BelongsToTeam;

    protected $fillable = ['team_id', 'target_url', 'target_hash', 'tiny_url', 'alias'];

    public static function hashFor(string $url): string
    {
        return hash('sha256', $url);
    }
}
