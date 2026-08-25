<?php

declare(strict_types=1);

namespace App\Exceptions;

class XAuthException extends XApiException
{
    public function __construct(string $message, array $payload = [])
    {
        parent::__construct($message, 401, 'unauthorized', $payload);
    }
}
