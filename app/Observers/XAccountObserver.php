<?php

declare(strict_types=1);

namespace App\Observers;

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
