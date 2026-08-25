<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Publishing\TweetTextWeigher;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates against X's weighted length, not strlen.
 *
 * For spintax the *longest* permutation is what has to fit -- a template that
 * only overflows on one branch would otherwise pass validation and then fail
 * for whichever unlucky accounts drew that branch.
 */
class FitsTweetLength implements ValidationRule
{
    public function __construct(private readonly bool $checkAllPermutations = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $worst = $this->checkAllPermutations
            ? $this->longestPermutation($value)
            : $value;

        $weight = TweetTextWeigher::weight($worst);

        if ($weight > TweetTextWeigher::MAX_WEIGHTED_LENGTH) {
            $fail(__('validation.custom.post.too_long', [
                'weight' => $weight,
                'max' => TweetTextWeigher::MAX_WEIGHTED_LENGTH,
            ]));
        }
    }

    /** Greedily pick the longest option in every spintax group. */
    private function longestPermutation(string $template): string
    {
        $guard = 0;
        $output = $template;

        while (preg_match('/\{([^{}]*)\}/', $output) === 1 && $guard++ < 50) {
            $output = (string) preg_replace_callback(
                '/\{([^{}]*)\}/',
                static function (array $match): string {
                    $options = explode('|', $match[1]);
                    usort($options, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

                    return $options[0] ?? '';
                },
                $output,
            );
        }

        return $output;
    }
}
