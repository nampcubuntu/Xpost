<?php

declare(strict_types=1);

namespace App\Enums;

enum VariantStrategy: string
{
    /** Every account posts the exact same body. */
    case Same = 'same';

    /** Body carries {a|b|c} spintax; each target renders its own permutation. */
    case Spintax = 'spintax';

    /** Explicit variant list, distributed round-robin across targets. */
    case RoundRobin = 'round_robin';

    /** Explicit variant list, drawn at random honouring per-variant weights. */
    case Random = 'random';

    /** One imported row mapped 1:1 onto one account. */
    case PerAccount = 'per_account';

    public function usesVariantTable(): bool
    {
        return in_array($this, [self::RoundRobin, self::Random, self::PerAccount], true);
    }
}
