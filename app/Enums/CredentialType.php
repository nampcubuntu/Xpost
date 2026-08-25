<?php

declare(strict_types=1);

namespace App\Enums;

enum CredentialType: string
{
    /** X API v2 user context via OAuth 2.0 Authorization Code + PKCE. Short lived, refreshable. */
    case OAuth2 = 'oauth2';

    /** Legacy OAuth 1.0a user tokens (access token + secret). Long lived, signed per request. */
    case OAuth1 = 'oauth1';

    public function requiresRefresh(): bool
    {
        return $this === self::OAuth2;
    }
}
