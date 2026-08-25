<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Enums\CredentialType;
use App\Models\AccountImportBatch;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\X\XApiClientFactory;
use App\Services\X\XAppSelector;
use App\Support\TeamContext;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Imports one chunk of a CSV of pre-existing OAuth 1.0a user tokens.
 *
 * Run as a batch so the UI can show progress across thousands of rows and a
 * single malformed line cannot abort the import.
 *
 * Expected columns:
 *   username, x_user_id, access_token, token_secret, app_name, proxy, timezone
 */
class ImportAccountChunk implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    /**
     * @param  list<array<string, string>>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $teamId,
        public readonly int $batchId,
        public readonly bool $verify = true,
    ) {
        $this->onQueue('imports');
    }

    public function handle(XAppSelector $apps, XApiClientFactory $clients): void
    {
        TeamContext::runAs($this->teamId, function () use ($apps, $clients): void {
            $imported = 0;
            $failed = 0;
            $errors = [];

            $appsByName = XApp::query()->where('team_id', $this->teamId)->get()->keyBy('name');

            foreach ($this->rows as $line => $row) {
                try {
                    $account = $this->importRow($row, $appsByName, $apps);

                    if ($this->verify) {
                        $this->verifyCredentials($account, $clients);
                    }

                    $imported++;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = ['row' => $line, 'username' => $row['username'] ?? '?', 'error' => $e->getMessage()];
                }
            }

            $this->recordProgress($imported, $failed, $errors);
        });
    }

    private function importRow(array $row, $appsByName, XAppSelector $apps): XAccount
    {
        $username = trim((string) ($row['username'] ?? ''));
        $token = trim((string) ($row['access_token'] ?? ''));
        $secret = trim((string) ($row['token_secret'] ?? ''));

        if ($username === '' || $token === '' || $secret === '') {
            throw new \InvalidArgumentException('username, access_token and token_secret are all required.');
        }

        // OAuth 1.0a tokens embed the user id before the dash: "123456-abcdef".
        $xUserId = trim((string) ($row['x_user_id'] ?? '')) ?: (str_contains($token, '-') ? explode('-', $token, 2)[0] : '');

        if ($xUserId === '') {
            throw new \InvalidArgumentException("Could not determine the X user id for {$username}.");
        }

        $account = XAccount::updateOrCreate(
            ['team_id' => $this->teamId, 'x_user_id' => $xUserId],
            [
                'username' => ltrim($username, '@'),
                'credential_type' => CredentialType::OAuth1,
                'access_token' => $token,
                'token_secret' => $secret,
                'timezone' => $row['timezone'] ?? null,
                'status' => AccountStatus::Active,
            ],
        );

        $named = trim((string) ($row['app_name'] ?? ''));

        if ($named !== '' && $appsByName->has($named)) {
            $account->forceFill(['x_app_id' => $appsByName->get($named)->id])->save();
        } else {
            $apps->assign($account);
        }

        return $account;
    }

    /** Confirm the token actually works before it is trusted with a campaign. */
    private function verifyCredentials(XAccount $account, XApiClientFactory $clients): void
    {
        $profile = $clients->for($account->fresh())->me();

        $account->forceFill([
            'username' => $profile['username'] ?? $account->username,
            'name' => $profile['name'] ?? null,
            'avatar_url' => $profile['profile_image_url'] ?? null,
            'followers_count' => data_get($profile, 'public_metrics.followers_count', 0),
            'verified_type' => $profile['verified_type'] ?? null,
            'location' => $profile['location'] ?? $account->location,
            'last_synced_at' => now(),
        ])->save();
    }

    private function recordProgress(int $imported, int $failed, array $errors): void
    {
        DB::transaction(function () use ($imported, $failed, $errors): void {
            $batch = AccountImportBatch::withoutGlobalScopes()->lockForUpdate()->find($this->batchId);

            if ($batch === null) {
                return;
            }

            $batch->forceFill([
                'rows_imported' => $batch->rows_imported + $imported,
                'rows_failed' => $batch->rows_failed + $failed,
                // Keep the error log bounded -- a broken export should not write
                // a 50 MB JSON column.
                'errors' => array_slice(array_merge($batch->errors ?? [], $errors), 0, 500),
                'status' => $this->batch()?->finished() ? 'completed' : 'processing',
            ])->save();
        });
    }
}
