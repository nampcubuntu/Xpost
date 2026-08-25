<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\XAppTier;
use App\Models\Team;
use App\Models\XApp;
use App\Services\X\XOAuth2Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<XApp> */
class XAppFactory extends Factory
{
    public function definition(): array
    {
        $tier = XAppTier::Basic;

        return [
            'team_id' => Team::factory(),
            'name' => 'App '.fake()->unique()->numberBetween(1, 9999),
            'tier' => $tier,
            'client_id' => Str::random(32),
            'client_secret' => Str::random(48),
            'consumer_key' => Str::random(25),
            'consumer_secret' => Str::random(50),
            'callback_url' => 'https://xpost.test/x/oauth/callback',
            'scopes' => XOAuth2Service::DEFAULT_SCOPES,
            'daily_post_cap' => $tier->defaultDailyPostCap(),
            'monthly_post_cap' => $tier->defaultMonthlyPostCap(),
            'usage_period_started_on' => now()->toDateString(),
            'max_accounts' => 500,
            'weight' => 1,
            'is_active' => true,
        ];
    }

    public function tier(XAppTier $tier): static
    {
        return $this->state(fn () => [
            'tier' => $tier,
            'daily_post_cap' => $tier->defaultDailyPostCap(),
            'monthly_post_cap' => $tier->defaultMonthlyPostCap(),
        ]);
    }

    /** An app that has spent its whole monthly budget. */
    public function exhausted(): static
    {
        return $this->state(fn (array $attributes) => [
            'monthly_posts_used' => $attributes['monthly_post_cap'] ?? 3000,
        ]);
    }
}
