<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Maps to the X API v2 reply_settings field on POST /2/tweets.
 * "everyone" is the API default and is sent as null.
 */
enum ReplySettings: string
{
    case Everyone = 'everyone';
    case Following = 'following';
    case MentionedUsers = 'mentionedUsers';
    case Subscribers = 'subscribers';
    case Verified = 'verified';

    public function toApiValue(): ?string
    {
        return $this === self::Everyone ? null : $this->value;
    }

    /**
     * The ones the composer offers: all of them.
     *
     * All five are real. The OpenAPI schema for POST /2/tweets lists
     * following, mentionedUsers, subscribers and verified, with everyone as
     * the default that is sent by omitting the field.
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        return self::cases();
    }

    /**
     * Needs X Premium on the posting account.
     *
     * Not a reason to withhold the option -- it is a reason to say so on the
     * row. A post fans out to a thousand accounts, so choosing one of these
     * against a pool with no Premium fails per account, hours later, inside a
     * publish worker, against a post that looked fine when it was written. The
     * label is what turns that into a decision instead of a surprise.
     */
    public function requiresPremium(): bool
    {
        return in_array($this, [self::Subscribers, self::Verified], true);
    }

    /** NavIcon name, so the menu reads at a glance the way the reference does. */
    public function icon(): string
    {
        return match ($this) {
            self::Following, self::Subscribers => 'members',
            self::MentionedUsers => 'at',
            self::Verified => 'badge',
            default => 'globe',
        };
    }

    public function label(): string
    {
        return __('xpost.reply_settings.'.$this->value);
    }
}
