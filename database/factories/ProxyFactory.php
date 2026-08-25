<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProxyScheme;
use App\Models\Proxy;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Proxy> */
class ProxyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'label' => 'Proxy '.fake()->unique()->numberBetween(1, 9999),
            'scheme' => ProxyScheme::Http,
            'host' => fake()->ipv4(),
            'port' => fake()->numberBetween(1024, 65000),
            'username' => fake()->userName(),
            'password' => fake()->password(),
            'country' => 'VN',
            'max_accounts' => 50,
            'is_active' => true,
            'health_status' => 'healthy',
        ];
    }
}
