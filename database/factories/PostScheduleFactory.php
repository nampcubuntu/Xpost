<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PostSchedule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PostSchedule> */
class PostScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => User::factory(),
            'name' => fake()->sentence(3),
            'template' => [
                'body' => '{Chào|Xin chào} [[handle]]',
                'reply_settings' => 'everyone',
                'destination_type' => 'none',
                'variant_strategy' => 'spintax',
                'audience' => ['mode' => 'all'],
            ],
            'times' => ['09:00', '13:00', '19:00'],
            'days_of_week' => null,
            'timezone' => 'Asia/Ho_Chi_Minh',
            'is_active' => true,
            'catch_up_minutes' => 15,
        ];
    }

    /** Named atTimes(), not times(): Factory::times() already exists and is static. */
    public function atTimes(array $times): static
    {
        return $this->state(fn () => ['times' => $times]);
    }

    public function onDays(array $days): static
    {
        return $this->state(fn () => ['days_of_week' => $days]);
    }

    public function paused(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
