<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a person may do inside a team.
 *
 * The roles already existed, as string literals repeated across three policies
 * with a slightly different list in each. That is how a role ends up meaning
 * one thing for posts and another for accounts, and how a new controller gets
 * written with no check at all -- there was nothing to look at that said what
 * the levels were.
 *
 * Ordered by power, so `atLeast()` can answer most questions without anyone
 * having to remember which literals belong in which array.
 */
enum TeamRole: string
{
    /** Read the campaign, publish nothing, change nothing. */
    case Member = 'member';

    /** Write and publish posts. Cannot touch accounts, apps, or money. */
    case Editor = 'editor';

    /** Everything except deleting the app pool and removing the owner. */
    case Admin = 'admin';

    /** The one who pays. */
    case Owner = 'owner';

    /** Higher is more. Only meaningful against another member of this enum. */
    public function rank(): int
    {
        return match ($this) {
            self::Member => 0,
            self::Editor => 1,
            self::Admin => 2,
            self::Owner => 3,
        };
    }

    public function atLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::Member => 'Chỉ xem',
            self::Editor => 'Soạn & đăng bài',
            self::Admin => 'Quản trị',
            self::Owner => 'Chủ sở hữu',
        };
    }

    /**
     * What this role can do, for a UI that hides what it cannot.
     *
     * A button that returns 403 is worse than no button: it reads as a bug
     * rather than as a boundary.
     *
     * @return array<string, bool>
     */
    public function abilities(): array
    {
        return [
            'view' => true,
            'compose' => $this->atLeast(self::Editor),
            'publish' => $this->atLeast(self::Editor),
            // Accounts, apps, proxies and short links are the shared plumbing:
            // one wrong edit affects every campaign the team is running.
            'manage_accounts' => $this->atLeast(self::Admin),
            'manage_infrastructure' => $this->atLeast(self::Admin),
            // Spending is the owner's, whoever holds the card.
            'manage_budget' => $this->atLeast(self::Admin),
            'delete_app' => $this === self::Owner,
            /*
             * Not a team role at all -- the queue dashboard shows every team's
             * jobs, so it belongs to whoever runs Xpost. Declared here so the
             * shape is complete, and overwritten by User::abilities() from the
             * Horizon gate.
             */
            'view_queue' => false,
        ];
    }

    public static function tryFromRole(?string $role): ?self
    {
        return $role === null ? null : self::tryFrom($role);
    }
}
