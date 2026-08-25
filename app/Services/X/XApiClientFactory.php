<?php

declare(strict_types=1);

namespace App\Services\X;

use App\Models\XAccount;

/**
 * Builds a client bound to one account. Registered as a singleton so the
 * OAuth service and limiter are shared, while each client stays account-scoped.
 */
final class XApiClientFactory
{
    public function __construct(
        private readonly XOAuth2Service $oauth,
        private readonly XRateLimiter $limiter,
    ) {}

    public function for(XAccount $account): XApiClient
    {
        $account->loadMissing(['app', 'proxy']);

        return new XApiClient($account, $this->oauth, $this->limiter);
    }
}
