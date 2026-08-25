<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case NeedsReauth = 'needs_reauth';
    case RateLimited = 'rate_limited';
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
