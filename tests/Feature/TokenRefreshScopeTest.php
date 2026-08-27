<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\TargetStatus;
use App\Jobs\RefreshAccountToken;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\XAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Warming only the tokens that are about to be used.
 *
 * An OAuth 2.0 token lasts two hours, so refreshing every account that holds
 * one means refreshing the whole pool twelve times a day whether or not it
 * publishes. Ten million accounts would need fourteen hundred refreshes a
 * second, sustained, for tokens nobody is going to use.
 *
 * freshAccessToken() already refreshes on use, so this pre-warm exists only to
 * keep that off the publish path.
 */
class TokenRefreshScopeTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team] = $this->tenant();

        Queue::fake();
    }

    private function account(): XAccount
    {
        return XAccount::factory()->create([
            'team_id' => $this->team->id,
            'credential_type' => CredentialType::OAuth2,
            'refresh_token' => 'refresh',
            // Inside the default --within window.
            'token_expires_at' => now()->addMinutes(5),
        ]);
    }

    private function giveWork(XAccount $account, int $minutesFromNow): void
    {
        PostTarget::factory()->create([
            'post_id' => Post::factory()->create(['team_id' => $this->team->id])->id,
            'team_id' => $this->team->id,
            'x_account_id' => $account->id,
            'status' => TargetStatus::Pending,
            'scheduled_at' => now()->addMinutes($minutesFromNow),
        ]);
    }

    #[Test]
    public function an_account_with_work_coming_up_is_warmed(): void
    {
        $account = $this->account();
        $this->giveWork($account, 10);

        $this->artisan('xpost:refresh-tokens')->assertSuccessful();

        Queue::assertPushed(RefreshAccountToken::class, 1);
    }

    #[Test]
    public function an_idle_account_is_left_to_refresh_on_use(): void
    {
        /*
         * The whole point: an expiring token on an account that is not
         * publishing costs nothing to leave alone, and freshAccessToken()
         * picks it up the moment it matters.
         */
        $this->account();

        $this->artisan('xpost:refresh-tokens')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function work_beyond_the_window_does_not_count_as_coming_up(): void
    {
        $account = $this->account();
        $this->giveWork($account, 240);

        $this->artisan('xpost:refresh-tokens')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function work_already_finished_does_not_count(): void
    {
        // A published target is not work; warming for it is warming for
        // nothing.
        $account = $this->account();

        PostTarget::factory()->create([
            'post_id' => Post::factory()->create(['team_id' => $this->team->id])->id,
            'team_id' => $this->team->id,
            'x_account_id' => $account->id,
            'status' => TargetStatus::Published,
            'scheduled_at' => now()->addMinutes(5),
        ]);

        $this->artisan('xpost:refresh-tokens')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_old_sweep_is_still_available(): void
    {
        // For a pool small enough that warming all of it costs nothing.
        $this->account();

        $this->artisan('xpost:refresh-tokens --work-within=0')->assertSuccessful();

        Queue::assertPushed(RefreshAccountToken::class, 1);
    }

    #[Test]
    public function an_oauth1_account_is_never_warmed(): void
    {
        // Those tokens do not expire, so there is nothing to refresh.
        $account = XAccount::factory()->create([
            'team_id' => $this->team->id,
            'credential_type' => CredentialType::OAuth1,
            'token_secret' => 'secret',
            'token_expires_at' => null,
        ]);

        $this->giveWork($account, 5);

        $this->artisan('xpost:refresh-tokens --work-within=0')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
