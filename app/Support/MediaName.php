<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PostMedia;

/**
 * The name an uploaded file is filed under.
 *
 * Media comes off a phone or a downloader as `video.mp4`, `0.21.21.2.21.21.mp4`,
 * `IMG_0042.jpg` or whatever the source called it, and a library of a thousand
 * campaigns ends up with forty files named some variation of the same thing.
 * The picker prints that name under every tile, so the one label meant to tell
 * two files apart is the one label that does not.
 *
 * X files its own media under a number, and the shape is worth copying for the
 * property behind it: a name nobody chose cannot be a name somebody reused.
 *
 * Every upload, photo and video alike. Two schemes in one column would mean
 * reading each row to know which kind of name it is, and the category is
 * already a field of its own.
 */
final class MediaName
{
    /**
     * Mirrors the shape of X's own media names, not their meaning -- theirs
     * index something inside X that nothing here can see.
     */
    private const PREFIX = '13';

    private const ATTEMPTS = 5;

    public static function next(): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $name = self::PREFIX.'_'.self::snowflake();

            // Checked rather than assumed. A collision needs the same
            // millisecond and the same twenty random bits, which will not
            // happen -- but "will not happen" is what the index is for.
            if (! PostMedia::withoutGlobalScopes()->where('original_name', $name)->exists()) {
                return $name;
            }
        }

        // Past absurd. Stay numeric rather than reaching for a different shape.
        return self::PREFIX.'_'.self::snowflake().random_int(100, 999);
    }

    /**
     * Milliseconds since the epoch, shifted to leave room for randomness.
     *
     * Time in the high bits means the names sort by upload order, which is what
     * anyone scanning a list of numbers is actually using them for. The low
     * twenty bits keep two uploads inside one millisecond apart.
     */
    private static function snowflake(): string
    {
        return (string) (((int) (microtime(true) * 1000) << 20) | random_int(0, (1 << 20) - 1));
    }
}
