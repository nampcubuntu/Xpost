<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PostTarget;
use App\Support\FailureContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PostTarget */
class PostTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'post_id' => $this->post_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'rendered_body' => $this->rendered_body,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'attempts' => $this->attempts,
            'x_tweet_id' => $this->x_tweet_id,
            'permalink' => $this->permalink,
            'duration_ms' => $this->duration_ms,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            // Which account, which proxy, which stage, and who to go and
            // fix -- a code on its own says the publisher gave up, not why.
            'failure' => FailureContext::for($this->resource),
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'username' => $this->account->username,
                'avatar_url' => $this->account->avatar_url,
            ]),
        ];
    }
}
