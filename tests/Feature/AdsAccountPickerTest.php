<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Models\XAccount;
use App\Models\XApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Choosing which ads account mints an account's cards.
 *
 * Connecting picks one automatically, which is right when there is exactly one
 * and a guess when there is more -- and the wrong guess is only discovered when
 * a card fails at publish.
 */
class AdsAccountPickerTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    private $user;

    private XApp $xApp;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $this->user] = $this->tenant();

        $this->xApp = XApp::factory()->create([
            'team_id' => $this->team->id,
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
        ]);
    }

    private function account(array $overrides = []): XAccount
    {
        return XAccount::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'x_app_id' => $this->xApp->id,
            'credential_type' => CredentialType::OAuth1,
            'access_token' => 'token',
            'token_secret' => 'secret',
        ], $overrides));
    }

    #[Test]
    public function it_lists_the_ads_accounts_this_account_can_reach(): void
    {
        Http::fake(['ads-api.x.com/*' => Http::response(['data' => [
            ['id' => '18ce55wllne', 'name' => 'Nam Beta', 'approval_status' => 'ACCEPTED', 'deleted' => false],
        ]])]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/accounts/{$this->account()->id}/ads-accounts")
            ->assertOk()
            ->assertJsonPath('data.0.id', '18ce55wllne')
            ->assertJsonPath('data.0.name', 'Nam Beta');
    }

    #[Test]
    public function an_unapproved_ads_account_is_offered_and_labelled(): void
    {
        /*
         * approval_status gates running ads, not creating creative -- a
         * REJECTED account mints cards perfectly well, measured against a live
         * one. Reporting it lets someone choose knowingly; hiding it would
         * remove an option that works.
         */
        Http::fake(['ads-api.x.com/*' => Http::response(['data' => [
            ['id' => 'rejected-one', 'name' => 'B', 'approval_status' => 'REJECTED', 'deleted' => false],
        ]])]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/accounts/{$this->account()->id}/ads-accounts")
            ->assertOk()
            ->assertJsonPath('data.0.approval_status', 'REJECTED')
            ->assertJsonPath('data.0.usable', true);
    }

    #[Test]
    public function an_approved_account_is_listed_first(): void
    {
        // Both work, so the obvious choice should be the top one.
        Http::fake(['ads-api.x.com/*' => Http::response(['data' => [
            ['id' => 'rejected-one', 'name' => 'B', 'approval_status' => 'REJECTED', 'deleted' => false],
            ['id' => 'accepted-one', 'name' => 'A', 'approval_status' => 'ACCEPTED', 'deleted' => false],
        ]])]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/accounts/{$this->account()->id}/ads-accounts")
            ->assertOk()
            ->assertJsonPath('data.0.id', 'accepted-one');
    }

    #[Test]
    public function a_deleted_ads_account_is_not_offered(): void
    {
        Http::fake(['ads-api.x.com/*' => Http::response(['data' => [
            ['id' => 'gone', 'name' => 'Gone', 'approval_status' => 'ACCEPTED', 'deleted' => true],
        ]])]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/accounts/{$this->account()->id}/ads-accounts")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function an_oauth2_account_is_told_why_it_cannot_ask(): void
    {
        // The Ads API refuses a bearer token outright, and the message X gives
        // for that says nothing about which flow was used.
        $account = $this->account([
            'credential_type' => CredentialType::OAuth2,
            'token_secret' => null,
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/accounts/{$account->id}/ads-accounts")
            ->assertStatus(422)
            ->assertJsonPath('data', []);
    }

    #[Test]
    public function the_choice_is_saved_on_the_account(): void
    {
        $account = $this->account(['ads_account_id' => 'old-one']);

        $this->actingAs($this->user)
            ->putJson("/api/v1/accounts/{$account->id}", ['ads_account_id' => '18ce55x0crz'])
            ->assertOk();

        $this->assertSame('18ce55x0crz', $account->fresh()->ads_account_id);
    }

    #[Test]
    public function clearing_the_choice_turns_card_minting_off(): void
    {
        // An account with no ads account still publishes; it just cannot mint.
        $account = $this->account(['ads_account_id' => '18ce55wllne']);

        $this->actingAs($this->user)
            ->putJson("/api/v1/accounts/{$account->id}", ['ads_account_id' => null])
            ->assertOk();

        $this->assertNull($account->fresh()->ads_account_id);
    }
}
