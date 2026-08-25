<?php

declare(strict_types=1);

namespace App\Services\Publishing;

/**
 * X does not count characters, it counts "weighted" characters:
 * most Latin text weighs 1, CJK and emoji weigh 2, and any URL is normalised
 * to a fixed 23 regardless of length. The composer counter has to match this
 * exactly or users will hit 403s from a box that says they had room left.
 *
 * Implements twitter-text configuration v3.
 */
final class TweetTextWeigher
{
    public const MAX_WEIGHTED_LENGTH = 280;

    private const TRANSFORMED_URL_LENGTH = 23;

    /** Ranges that weigh 1; everything outside them weighs 2. */
    private const LIGHT_RANGES = [
        [0x0000, 0x10FF],
        [0x2000, 0x200D],
        [0x2010, 0x201F],
        [0x2032, 0x2037],
    ];

    private const URL_PATTERN = '~\b(?:https?://|www\.)[^\s<>"\x{0000}-\x{0020}]+~iu';

    public static function weight(string $text): int
    {
        $text = self::maskUrls($text, $urlCount);

        $weight = 0;

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $weight += self::weightOf($char);
        }

        return $weight + ($urlCount * self::TRANSFORMED_URL_LENGTH);
    }

    public static function remaining(string $text): int
    {
        return self::MAX_WEIGHTED_LENGTH - self::weight($text);
    }

    public static function fits(string $text): bool
    {
        return self::remaining($text) >= 0;
    }

    /** Extract URLs so they can be replaced by their fixed t.co weight. */
    public static function urls(string $text): array
    {
        preg_match_all(self::URL_PATTERN, $text, $matches);

        return $matches[0] ?? [];
    }

    private static function maskUrls(string $text, ?int &$count): string
    {
        $count = 0;

        return (string) preg_replace_callback(
            self::URL_PATTERN,
            function () use (&$count): string {
                $count++;

                return '';
            },
            $text,
        );
    }

    private static function weightOf(string $char): int
    {
        $code = mb_ord($char, 'UTF-8');

        if ($code === false) {
            return 2;
        }

        foreach (self::LIGHT_RANGES as [$start, $end]) {
            if ($code >= $start && $code <= $end) {
                return 1;
            }
        }

        return 2;
    }
}
