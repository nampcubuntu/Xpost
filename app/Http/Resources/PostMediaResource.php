<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PostMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PostMedia */
class PostMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'mime' => $this->mime,
            'category' => $this->category->value,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'duration_ms' => $this->duration_ms,
            'alt_text' => $this->alt_text,
            'is_ai_generated' => $this->is_ai_generated,
            'original_name' => $this->original_name,
            // The media list shows an upload date next to each file.
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
