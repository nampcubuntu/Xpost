<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Team;
use App\Models\XAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PostTarget> */
class PostTargetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'x_account_id' => XAccount::factory(),
            'team_id' => Team::factory(),
            'rendered_body' => fake()->realText(150),
            'status' => TargetStatus::Pending,
            'scheduled_at' => now(),
            'attempts' => 0,
        ];
    }

    public function due(): static
    {
        return $this->state(fn () => [
            'status' => TargetStatus::Pending,
            'scheduled_at' => now()->subMinute(),
            'available_at' => null,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => TargetStatus::Published,
            'x_tweet_id' => (string) fake()->numberBetween(10 ** 17, 10 ** 18 - 1),
            'published_at' => now(),
            'duration_ms' => fake()->numberBetween(120, 2500),
        ]);
    }

    public function failed(string $code = 'duplicate_content'): static
    {
        return $this->state(fn () => [
            'status' => TargetStatus::Failed,
            'error_code' => $code,
            'error_message' => 'Simulated failure',
            'attempts' => 1,
        ]);
    }
}
