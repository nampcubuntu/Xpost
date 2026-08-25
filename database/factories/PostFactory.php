<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DestinationType;
use App\Enums\PostStatus;
use App\Enums\ReplySettings;
use App\Enums\ScheduleMode;
use App\Enums\VariantStrategy;
use App\Models\Post;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Post> */
class PostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => User::factory(),
            'name' => fake()->sentence(3),
            'body' => fake()->realText(180),
            'reply_settings' => ReplySettings::Everyone,
            'destination_type' => DestinationType::None,
            'variant_strategy' => VariantStrategy::Same,
            'schedule_mode' => ScheduleMode::Immediate,
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => PostStatus::Draft,
            'audience' => ['mode' => 'all', 'shuffle' => false],
        ];
    }

    public function scheduled(?\DateTimeInterface $at = null): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Scheduled,
            'schedule_mode' => ScheduleMode::FixedTime,
            'scheduled_at' => $at ?? now()->addHour(),
        ]);
    }

    public function withWebsite(string $url = 'https://example.com'): static
    {
        return $this->state(fn () => [
            'destination_type' => DestinationType::Website,
            'website_url' => $url,
            'headline' => fake()->sentence(4),
        ]);
    }

    public function spintax(): static
    {
        return $this->state(fn () => [
            'variant_strategy' => VariantStrategy::Spintax,
            'body' => '{Xin chào|Chào bạn|Hello} — {ưu đãi|khuyến mãi} {hôm nay|tuần này}!',
        ]);
    }
}
