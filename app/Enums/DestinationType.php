<?php

declare(strict_types=1);

namespace App\Enums;

enum DestinationType: string
{
    case None = 'none';
    case Website = 'website';
    case App = 'app';

    public function requiresUrl(): bool
    {
        return $this === self::Website;
    }
}
