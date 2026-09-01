<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\AccountHealthChanged;
use App\Models\Proxy;
use App\Models\XAccount;
use App\Models\XApp;

/**
 * Maintains the assigned_count columns that the app and proxy selectors sort
 * on. Without these the selectors would need a COUNT per candidate on every
 * single assignment.
 */
class XAccountObserver
{
    public function created(XAccount $account): void
    {
        $this->bump($account->x_app_id, $account->proxy_id, 1);
    }

    public function updated(XAccount $account): void
    {
        if ($account->wasChanged('x_app_id')) {
            $this->adjustApp($account->getOriginal('x_app_id'), -1);
            $this->adjustApp($account->x_app_id, 1);
        }

        if ($account->wasChanged('proxy_id')) {
            $this->adjustProxy($account->getOriginal('proxy_id'), -1);
            $this->adjustProxy($account->proxy_id, 1);
        }

        $this->announceIfWatched($account);
    }

    /**
     * Tell the accounts page a column it renders has moved.
     *
     * Here rather than at the call sites, because there are seven of those and
     * two of them had it: the publisher's own success and failure paths. The
     * transition that matters most to whoever is watching -- an account falling
     * to needs_reauth when its token will not refresh -- happens in
     * XOAuth2Service and did not, so the most common status change on this
     * install was the one the page could not see.
     *
     * The event carries no account and is throttled per team, so this firing on
     * every row of a ten thousand account import costs one message.
     */
    private function announceIfWatched(XAccount $account): void
    {
        if ($account->wasChanged(['status', 'health_score', 'cooldown_until'])) {
            AccountHealthChanged::announce((int) $account->team_id);
        }
    }

    public function deleted(XAccount $account): void
    {
        $this->bump($account->x_app_id, $account->proxy_id, -1);
    }

    public function restored(XAccount $account): void
    {
        $this->bump($account->x_app_id, $account->proxy_id, 1);
    }

    private function bump(?int $appId, ?int $proxyId, int $delta): void
    {
        $this->adjustApp($appId, $delta);
        $this->adjustProxy($proxyId, $delta);
    }

    private function adjustApp(?int $appId, int $delta): void
    {
        if ($appId === null) {
            return;
        }

        $query = XApp::withoutGlobalScopes()->whereKey($appId);

        $delta > 0
            ? $query->increment('assigned_count')
            : $query->where('assigned_count', '>', 0)->decrement('assigned_count');
    }

    private function adjustProxy(?int $proxyId, int $delta): void
    {
        if ($proxyId === null) {
            return;
        }

        $query = Proxy::withoutGlobalScopes()->whereKey($proxyId);

        $delta > 0
            ? $query->increment('assigned_count')
            : $query->where('assigned_count', '>', 0)->decrement('assigned_count');
    }
}
