<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Post;
use App\Services\Publishing\TweetTextWeigher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Post */
class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'body' => $this->body,
            'weighted_length' => TweetTextWeigher::weight((string) $this->body),
            'reply_settings' => $this->reply_settings->value,
            'reply_settings_label' => $this->reply_settings->label(),

            'destination_type' => $this->destination_type->value,
            'website_url' => $this->website_url,
            'headline' => $this->headline,
            'app_store_url' => $this->app_store_url,

            'is_ai_generated' => $this->is_ai_generated,
            'promoted_only' => $this->promoted_only,

            'variant_strategy' => $this->variant_strategy->value,
            'schedule_mode' => $this->schedule_mode->value,
            'timezone' => $this->timezone,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'window_ends_at' => $this->window_ends_at?->toIso8601String(),
            'jitter_seconds' => $this->jitter_seconds,
            'per_minute_cap' => $this->per_minute_cap,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'published_at' => $this->published_at?->toIso8601String(),
            'is_editable' => $this->isEditable(),

            'progress' => [
                'total' => $this->targets_total,
                'pending' => $this->targets_pending,
                'published' => $this->targets_published,
                'failed' => $this->targets_failed,
                'percent' => $this->targets_total > 0
                    ? round((($this->targets_published + $this->targets_failed) / $this->targets_total) * 100, 1)
                    : 0.0,
            ],

            'audience' => $this->audience,
            'campaign' => new CampaignResource($this->whenLoaded('campaign')),
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ]),
            'media' => PostMediaResource::collection($this->whenLoaded('media')),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($v) => [
                'id' => $v->id,
                'body' => $v->body,
                'headline' => $v->headline,
                'website_url' => $v->website_url,
                'weight' => $v->weight,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
