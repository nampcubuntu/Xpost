<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\XAuthException;
use App\Models\XAccount;
use App\Services\X\XOAuth2Service;
use App\Support\TeamContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RefreshAccountToken implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $accountId,
        public readonly int $teamId,
    ) {
        $this->onQueue('tokens');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("refresh-token:{$this->accountId}"))->dontRelease()];
    }

    public function handle(XOAuth2Service $oauth): void
    {
        TeamContext::runAs($this->teamId, function () use ($oauth): void {
            $account = XAccount::withoutGlobalScopes()->find($this->accountId);

            if ($account === null) {
                return;
            }

            try {
                $oauth->refresh($account);
            } catch (XAuthException) {
                // refresh() has already flagged the account as needs_reauth;
                // there is nothing a retry could achieve.
            }
        });
    }
}
