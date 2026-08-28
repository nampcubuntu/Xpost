<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\ReplySettings;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\X\DTO\TweetPayload;
use App\Services\X\XApiClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Does the setting chosen in the composer reach X?
 *
 * Every link in the chain looked right on its own -- the enum maps the API, the
 * request validates against it, the model casts it, the publisher passes it.
 * Those are four claims, and the only assertion that covers all four is the
 * JSON body that actually leaves the process.
 */
class ReplySettingsReachXTest extends TestCase
{
    use RefreshDatabase;

    private XAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        [$team, $user] = $this->tenant();
        $this->actingAs($user->refresh());

        $app = XApp::factory()->create([
            'team_id' => $team->id,
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
        ]);

        $this->account = XAccount::factory()->create([
            'team_id' => $team->id,
            'x_app_id' => $app->id,
            'proxy_id' => null,
            'credential_type' => CredentialType::OAuth1,
            'access_token' => 'at',
            'token_secret' => 'ts',
        ]);
    }

    /** The body X is actually handed for a post carrying this setting. */
    private function sentBody(ReplySettings $setting): array
    {
        $seen = [];

        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->data();

            return Http::response(['data' => ['id' => '1', 'text' => 'x']]);
        });

        app(XApiClientFactory::class)->for($this->account->fresh())->createTweet(
            new TweetPayload(text: 'xin chào', replySettings: $setting)
        );

        return $seen[0] ?? [];
    }

    /** @return array<string, array{0: ReplySettings, 1: string|null}> */
    public static function settings(): array
    {
        return [
            // Not in X's enum: it is the default you get by saying nothing, so
            // sending the word would be rejected.
            'everyone is sent by omission' => [ReplySettings::Everyone, null],
            'following' => [ReplySettings::Following, 'following'],
            'mentionedUsers' => [ReplySettings::MentionedUsers, 'mentionedUsers'],
            // Dropped from the menu, still valid on a post saved earlier.
            'subscribers' => [ReplySettings::Subscribers, 'subscribers'],
            'verified' => [ReplySettings::Verified, 'verified'],
        ];
    }

    #[Test]
    #[DataProvider('settings')]
    public function the_chosen_setting_is_what_x_receives(ReplySettings $setting, ?string $expected): void
    {
        $body = $this->sentBody($setting);

        if ($expected === null) {
            $this->assertArrayNotHasKey('reply_settings', $body);

            return;
        }

        $this->assertSame($expected, $body['reply_settings'] ?? null);
    }

    #[Test]
    public function the_spelling_is_the_one_the_api_schema_lists(): void
    {
        /*
         * mentionedUsers, not mentioned and not mentioned_users. The Post
         * object returned by a read uses a different spelling from the one
         * creation accepts, which is exactly how a wrong constant gets written
         * and never noticed -- the post publishes, and the setting silently is
         * not applied.
         */
        $this->assertSame(
            ['following', 'mentionedUsers', 'subscribers', 'verified'],
            collect(ReplySettings::cases())
                ->map(fn (ReplySettings $c) => $c->toApiValue())
                ->filter()
                ->values()
                ->all()
        );
    }

    #[Test]
    public function a_post_keeps_the_setting_through_save_and_reload(): void
    {
        // The cast is what makes the round trip an enum rather than a string,
        // and a string here would reach the publisher and fail on ->toApiValue.
        $id = $this->postJson('/api/v1/posts', [
            'body' => 'xin chào',
            'destination_type' => 'none',
            'schedule_mode' => 'immediate',
            'reply_settings' => 'mentionedUsers',
            'audience' => ['mode' => 'all'],
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/posts/{$id}")
            ->assertOk()
            ->assertJsonPath('data.reply_settings', 'mentionedUsers');
    }

    #[Test]
    public function a_value_outside_the_enum_is_refused_at_the_form(): void
    {
        // Rather than at X, per account, hours later.
        $this->postJson('/api/v1/posts', [
            'body' => 'xin chào',
            'destination_type' => 'none',
            'schedule_mode' => 'immediate',
            'reply_settings' => 'mentioned',
            'audience' => ['mode' => 'all'],
        ])->assertStatus(422)->assertJsonValidationErrors('reply_settings');
    }
}
