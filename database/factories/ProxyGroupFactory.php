<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProxyGroup;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProxyGroup> */
class ProxyGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->words(2, true),
            'color' => fake()->hexColor(),
        ];
    }
}
