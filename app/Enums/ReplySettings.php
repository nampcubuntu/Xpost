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

    public function label(): string
    {
        return __('xpost.reply_settings.'.$this->value);
    }
}
