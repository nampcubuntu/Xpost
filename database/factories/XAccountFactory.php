<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\CredentialType;
use App\Models\Team;
use App\Models\XAccount;
use App\Models\XApp;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<XAccount> */
class XAccountFactory extends Factory
{
    public function definition(): array
    {
        $username = fake()->unique()->userName();

        return [
            'team_id' => Team::factory(),
            'x_app_id' => XApp::factory(),
            'x_user_id' => (string) fake()->unique()->numberBetween(1_000_000, 999_999_999),
            'username' => Str::limit($username, 15, ''),
            'name' => fake()->name(),
            'avatar_url' => null,
            'followers_count' => fake()->numberBetween(0, 50_000),
            'following_count' => fake()->numberBetween(0, 2_000),
            'tweet_count' => fake()->numberBetween(0, 10_000),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'credential_type' => CredentialType::OAuth2,
            'access_token' => Str::random(60),
            'refresh_token' => Str::random(60),
            'scopes' => ['tweet.read', 'tweet.write', 'users.read', 'offline.access'],
            'token_expires_at' => now()->addHours(2),
            'status' => AccountStatus::Active,
            'health_score' => 100,
            'daily_post_cap' => 50,
        ];
    }

    public function oauth1(): static
    {
        return $this->state(fn () => [
            'credential_type' => CredentialType::OAuth1,
            'access_token' => fake()->numberBetween(1_000_000, 9_999_999).'-'.Str::random(40),
            'token_secret' => Str::random(45),
            'refresh_token' => null,
            'token_expires_at' => null,
        ]);
    }

    public function status(AccountStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /** A token that the refresh sweeper should pick up. */
    public function expiringToken(): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->addMinutes(2)]);
    }
}
