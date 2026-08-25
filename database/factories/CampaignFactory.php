<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Campaign> */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => User::factory(),
            'name' => fake()->catchPhrase(),
            'status' => 'active',
            'timezone' => 'Asia/Ho_Chi_Minh',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ];
    }
}
