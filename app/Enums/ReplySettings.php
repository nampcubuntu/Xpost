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
     * The ones the composer offers.
     *
     * All five are real: the OpenAPI schema for POST /2/tweets lists
     * following, mentionedUsers, subscribers and verified, with everyone as
     * the default that is sent by omitting the field.
     *
     * Subscribers and verified are left out because they need X Premium on the
     * posting account. A post here fans out to a thousand of them, so a
     * setting that holds on some and is refused on others is worse than one
     * that was never offered -- the failure arrives per account, hours later,
     * inside a publish worker. X Ads Manager's own composer shows the same
     * three, for what is presumably the same reason.
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        return [self::Everyone, self::Following, self::MentionedUsers];
    }

    /** NavIcon name, so the menu reads at a glance the way the reference does. */
    public function icon(): string
    {
        return match ($this) {
            self::Following, self::Subscribers => 'members',
            self::MentionedUsers => 'at',
            default => 'globe',
        };
    }

    public function label(): string
    {
        return __('xpost.reply_settings.'.$this->value);
    }
}
