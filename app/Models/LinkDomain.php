<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;

/**
 * A host a short code can be served from.
 *
 * Not a property of any key: every domain routing to the same Worker reads the
 * same namespace, so any code resolves on all of them. The domain is chosen
 * when a link is used.
 */
class LinkDomain extends Model
{
    use BelongsToTeam;

    protected $fillable = ['team_id', 'host', 'label', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Reduce whatever was typed to a bare host.
     *
     * People paste what they have -- a full URL, a scheme, a trailing slash --
     * and two rows for the same domain that differ by a slash are two rows
     * that look identical on screen and break the unique index's purpose.
     */
    public static function normaliseHost(string $input): string
    {
        $host = trim($input);
        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host) ?? $host;

        return rtrim(strtolower(explode('/', $host)[0]), '.');
    }

    public function url(string $key): string
    {
        return 'https://'.$this->host.'/'.self::codeFrom($key);
    }

    /**
     * The part of a key the Worker resolves by.
     *
     * Keys are stored with a prefix (`r:abc`) so a namespace can hold more than
     * one kind of entry, but the Worker is reached by the code alone.
     */
    public static function codeFrom(string $key): string
    {
        return str_contains($key, ':') ? substr($key, strrpos($key, ':') + 1) : $key;
    }
}
