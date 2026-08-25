<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\XApp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never exposes client_secret / consumer_secret -- only whether they are set.
 *
 * @mixin XApp
 */
class XAppResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tier' => $this->tier->value,
            'client_id' => $this->client_id,
            'has_oauth2' => $this->supportsOAuth2(),
            'has_oauth1' => $this->supportsOAuth1(),
            'callback_url' => $this->callback_url,
            'scopes' => $this->scopes,

            'daily_post_cap' => $this->daily_post_cap,
            'monthly_post_cap' => $this->monthly_post_cap,
            'monthly_posts_used' => $this->monthly_posts_used,
            'budget_remaining' => max(0, $this->monthly_post_cap - $this->monthly_posts_used),
            'max_accounts' => $this->max_accounts,
            'assigned_count' => $this->assigned_count,
            'capacity_percent' => $this->max_accounts > 0
                ? round($this->assigned_count / $this->max_accounts * 100, 1)
                : 0.0,

            'weight' => $this->weight,
            'is_active' => $this->is_active,
            'cooldown_until' => $this->cooldown_until?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }
}
