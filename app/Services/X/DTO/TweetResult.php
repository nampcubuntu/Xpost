<?php

declare(strict_types=1);

namespace App\Services\X\DTO;

final class TweetResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $text,
        public readonly ?string $conversationId = null,
        public readonly array $raw = [],
    ) {}

    public static function fromResponse(array $body): self
    {
        $data = $body['data'] ?? [];

        return new self(
            id: (string) ($data['id'] ?? ''),
            text: (string) ($data['text'] ?? ''),
            conversationId: isset($data['conversation_id']) ? (string) $data['conversation_id'] : null,
            raw: $body,
        );
    }

    public function permalink(string $username): string
    {
        return "https://x.com/{$username}/status/{$this->id}";
    }
}
