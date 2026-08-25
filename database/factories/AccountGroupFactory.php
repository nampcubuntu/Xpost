<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AccountGroup;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccountGroup> */
class AccountGroupFactory extends Factory
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
