<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Post;
use App\Models\PostVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PostVariant> */
class PostVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'body' => fake()->realText(150),
            'weight' => 1,
        ];
    }
}
