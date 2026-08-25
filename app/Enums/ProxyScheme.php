<?php

declare(strict_types=1);

namespace App\Enums;

enum ProxyScheme: string
{
    case Http = 'http';
    case Https = 'https';
    case Socks4 = 'socks4';
    case Socks5 = 'socks5';
}
