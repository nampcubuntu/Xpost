<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Models\XAccount;

/**
 * Renders {a|b|c} spintax, including nested groups, plus a small set of
 * per-account tokens.
 *
 * The point is that 10k accounts posting byte-identical text is the single
 * loudest spam signal there is; giving every target its own permutation is the
 * cheapest way to avoid it.
 */
final class SpintaxRenderer
{
    /** Innermost group first, so nesting resolves bottom-up. */
    private const GROUP = '/\{([^{}]*)\}/';

    public function render(string $template, ?int $seed = null): string
    {
        if ($seed !== null) {
            mt_srand($seed);
        }

        $output = $template;
        $guard = 0;

        while (preg_match(self::GROUP, $output) === 1 && $guard++ < 50) {
            $output = (string) preg_replace_callback(
                self::GROUP,
                static function (array $match): string {
                    $options = explode('|', $match[1]);

                    return $options[array_rand($options)];
                },
                $output,
            );
        }

        if ($seed !== null) {
            mt_srand();
        }

        return $output;
    }

    /**
     * Replace @-style tokens with values from the account being posted to.
     * Unknown tokens are left intact so a typo is visible rather than silent.
     */
    public function substitute(string $text, XAccount $account, array $extra = []): string
    {
        $tokens = array_merge([
            'username' => $account->username,
            'name' => $account->name ?? $account->username,
            'handle' => $account->handle(),
            'followers' => (string) $account->followers_count,
            'date' => now($account->timezone ?? config('app.timezone'))->format('d/m/Y'),
            'time' => now($account->timezone ?? config('app.timezone'))->format('H:i'),
        ], $extra);

        return (string) preg_replace_callback(
            '/\[\[\s*([a-z_]+)\s*\]\]/i',
            static fn (array $m): string => $tokens[strtolower($m[1])] ?? $m[0],
            $text,
        );
    }

    /** Full pipeline for one target. The seed keeps re-runs deterministic. */
    public function renderFor(string $template, XAccount $account, int $seed, array $extra = []): string
    {
        return $this->substitute($this->render($template, $seed), $account, $extra);
    }

    /** How many distinct outputs a template can produce -- shown in the composer. */
    public function permutationCount(string $template): int
    {
        $total = 1;
        $working = $template;
        $guard = 0;

        while (preg_match(self::GROUP, $working, $match) === 1 && $guard++ < 50) {
            $total *= max(1, count(explode('|', $match[1])));
            $working = (string) preg_replace(self::GROUP, 'x', $working, 1);

            if ($total > 1_000_000) {
                return 1_000_000;
            }
        }

        return $total;
    }
}
