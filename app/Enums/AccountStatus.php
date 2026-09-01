<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case NeedsReauth = 'needs_reauth';

    /*
     * There is deliberately no RateLimited here.
     *
     * X refusing for tempo is handled where it happens: the target is
     * deferred by the retry-after it came with, and repeated failures put
     * the account on a cooldown that expires by itself. A status would have
     * needed something to set it back and nothing would have -- so the case
     * existed, was never assigned by any code path, and put an option in the
     * filter that could not match a row.
     */
    case Suspended = 'suspended';
    case Disabled = 'disabled';
    case Error = 'error';

    /** Statuses that are allowed to receive publishing work. */
    public static function publishable(): array
    {
        return [self::Active];
    }

    public function isPublishable(): bool
    {
        return in_array($this, self::publishable(), true);
    }

    /** Whether the operator can fix this by re-authorising the account. */
    public function isRecoverableByReauth(): bool
    {
        return in_array($this, [self::NeedsReauth, self::Error], true);
    }

    public function label(): string
    {
        return __('xpost.account_status.'.$this->value);
    }
}
