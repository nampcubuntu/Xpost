<?php

declare(strict_types=1);

namespace App\Services\X;

use App\Models\XAccount;

/**
 * The ads accounts one X account can reach.
 *
 * Split out of the OAuth flow because two places need the same answer for
 * different reasons: connecting picks one automatically, and the accounts page
 * shows all of them so a person can choose. Automatic is right when there is
 * exactly one and a guess when there is more, and the wrong guess only shows
 * up when a card fails at publish.
 */
final class XAdsAccountLister
{
    /**
     * @return list<array{id: string, name: string, approval_status: string, usable: bool}>
     */
    public function forAccount(XAccount $account): array
    {
        $response = (new XAdsApiClient($account))->get('accounts');

        return collect((array) $response->json('data'))
            ->reject(fn (array $ads) => (bool) ($ads['deleted'] ?? false))
            ->map(fn (array $ads) => [
                'id' => (string) $ads['id'],
                'name' => (string) ($ads['name'] ?? $ads['id']),
                'approval_status' => (string) ($ads['approval_status'] ?? 'UNKNOWN'),
                /*
                 * approval_status gates running ads, not creating creative -- a
                 * REJECTED account mints cards perfectly well, measured against
                 * a live one. Reported so the choice is informed, never used to
                 * hide an option that works.
                 */
                'usable' => true,
            ])
            // Approved first, so the obvious choice is the top one.
            ->sortByDesc(fn (array $ads) => $ads['approval_status'] === 'ACCEPTED')
            ->values()
            ->all();
    }

    /**
     * Whether this account may actually use an ads account it names.
     *
     * `GET /accounts` does not list everything a user can mint through -- an
     * account that answers ACCOUNT_ADMIN here, and mints cards perfectly well,
     * was missing from the list entirely and returned NOT_FOUND when asked for
     * by id. All three measured against the same live account in the same
     * minute. So the list is a convenience and this is the authority.
     *
     * @return array{id: string, name: string, approval_status: string, usable: bool}|null
     */
    public function verify(XAccount $account, string $adsAccountId): ?array
    {
        $ads = new XAdsApiClient($account);

        try {
            $access = $ads->get("accounts/{$adsAccountId}/authenticated_user_access");
        } catch (\Throwable) {
            return null;
        }

        if (! in_array('ACCOUNT_ADMIN', (array) $access->json('data.permissions'), true)) {
            return null;
        }

        // The name is a nicety; being refused it changes nothing about access.
        $name = $adsAccountId;
        $status = 'UNKNOWN';

        try {
            $detail = $ads->get('accounts/'.$adsAccountId)->json('data');
            $name = (string) ($detail['name'] ?? $adsAccountId);
            $status = (string) ($detail['approval_status'] ?? 'UNKNOWN');
        } catch (\Throwable) {
            // The endpoint that says NOT_FOUND for an account that works.
        }

        return ['id' => $adsAccountId, 'name' => $name, 'approval_status' => $status, 'usable' => true];
    }
}
