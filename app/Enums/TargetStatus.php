<?php

declare(strict_types=1);

namespace App\Enums;

enum TargetStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Published, self::Failed, self::Skipped, self::Cancelled], true);
    }

    /** Statuses the dispatcher is allowed to claim off the schedule. */
    public static function claimable(): array
    {
        return [self::Pending->value];
    }

    public function label(): string
    {
        return __('xpost.target_status.'.$this->value);
    }
}
