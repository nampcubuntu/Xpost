<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\XAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin XAccount */
class XAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'x_user_id' => $this->x_user_id,
            'username' => $this->username,
            'handle' => $this->handle(),
            'name' => $this->name,
            'avatar_url' => $this->avatar_url,
            'followers_count' => $this->followers_count,
            'tweet_count' => $this->tweet_count,
            'verified_type' => $this->verified_type,
            'location' => $this->location,
            'timezone' => $this->timezone,
            // 'manual' | 'location' | 'proxy' | null — lets the UI show whether
            // a timezone was typed or inferred.
            'timezone_source' => $this->timezone_source,

            'credential_type' => $this->credential_type->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'health_score' => $this->health_score,
            'daily_post_cap' => $this->daily_post_cap,
            'consecutive_failures' => $this->consecutive_failures,

            'token_expires_at' => $this->token_expires_at?->toIso8601String(),
            'needs_reauth' => $this->status->isRecoverableByReauth(),
            'cooldown_until' => $this->cooldown_until?->toIso8601String(),
            'last_posted_at' => $this->last_posted_at?->toIso8601String(),
            'last_error' => $this->last_error,

            'app' => $this->whenLoaded('app', fn () => [
                'id' => $this->app->id,
                'name' => $this->app->name,
                'tier' => $this->app->tier->value,
            ]),
            'proxy' => $this->whenLoaded('proxy', fn () => [
                'id' => $this->proxy->id,
                'label' => $this->proxy->label,
                'country' => $this->proxy->country,
                'health_status' => $this->proxy->health_status,
            ]),
            'groups' => $this->whenLoaded('groups', fn () => $this->groups->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'color' => $g->color,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
