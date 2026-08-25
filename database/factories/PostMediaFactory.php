<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MediaCategory;
use App\Models\PostMedia;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PostMedia> */
class PostMediaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'disk' => 'local',
            'path' => 'media/'.Str::random(16).'.jpg',
            'original_name' => 'creative.jpg',
            'mime' => 'image/jpeg',
            'category' => MediaCategory::TweetImage,
            'size' => fake()->numberBetween(50_000, 3_000_000),
            'width' => 1600,
            'height' => 900,
            'sha256' => hash('sha256', Str::random(32)),
        ];
    }
}
