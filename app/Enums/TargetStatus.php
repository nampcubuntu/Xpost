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

    /**
     * Statuses that will never change again, as values for a whereIn.
     *
     * What makes a row safe to delete: anything else is work not yet done, and
     * an old row in one of those states is a backlog, not litter.
     *
     * @return list<string>
     */
    public static function terminal(): array
    {
        return array_values(array_map(
            static fn (self $status) => $status->value,
            array_filter(self::cases(), static fn (self $status) => $status->isTerminal()),
        ));
    }

    public function label(): string
    {
        return __('xpost.target_status.'.$this->value);
    }
}
