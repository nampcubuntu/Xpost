<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaCategory: string
{
    case TweetImage = 'tweet_image';
    case TweetGif = 'tweet_gif';
    case TweetVideo = 'tweet_video';
    case AmplifyVideo = 'amplify_video';
    case Subtitles = 'subtitles';

    public static function fromMime(string $mime): self
    {
        return match (true) {
            $mime === 'image/gif' => self::TweetGif,
            str_starts_with($mime, 'video/') => self::TweetVideo,
            default => self::TweetImage,
        };
    }

    /** Chunked upload (INIT/APPEND/FINALIZE) is required above this size. */
    public function maxBytes(): int
    {
        return match ($this) {
            self::TweetImage => 5 * 1024 * 1024,
            self::TweetGif => 15 * 1024 * 1024,
            self::TweetVideo,
            self::AmplifyVideo => 512 * 1024 * 1024,
            self::Subtitles => 1024 * 1024,
        };
    }
}
