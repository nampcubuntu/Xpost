<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Catches unbalanced spintax braces before fan-out.
 *
 * An unclosed "{" renders as a literal brace on every one of thousands of
 * accounts, which is both obvious and unfixable after the fact.
 */
class ValidSpintax implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! str_contains($value, '{')) {
            return;
        }

        $depth = 0;

        foreach (str_split($value) as $char) {
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }

            if ($depth < 0) {
                $fail(__('validation.custom.post.spintax_unbalanced'));

                return;
            }
        }

        if ($depth !== 0) {
            $fail(__('validation.custom.post.spintax_unbalanced'));

            return;
        }

        // {} and {a} are almost always a typo rather than an intentional group.
        if (preg_match('/\{[^{}|]*\}/', $value) === 1) {
            $fail(__('validation.custom.post.spintax_single_option'));
        }
    }
}
