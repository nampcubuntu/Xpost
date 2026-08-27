<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\InvalidProxyLine;
use App\Support\ProxyLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading a pasted proxy list.
 *
 * Every seller writes the columns in a different order, so nothing here is
 * positional except the one thing that cannot be inferred: which of two opaque
 * strings is the username and which is the password.
 */
class ProxyLineTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array{string, int, ?string, ?string, ?string}}>
     */
    public static function lines(): array
    {
        return [
            'host and port only' => ['1.2.3.4:8080', ['1.2.3.4', 8080, null, null, null]],
            'pipes' => ['1.2.3.4|8080|user|pass', ['1.2.3.4', 8080, 'user', 'pass', null]],
            'colons' => ['1.2.3.4:8080:user:pass', ['1.2.3.4', 8080, 'user', 'pass', null]],
            'commas' => ['1.2.3.4,8080,user,pass', ['1.2.3.4', 8080, 'user', 'pass', null]],
            'spaces' => ['1.2.3.4 8080 user pass', ['1.2.3.4', 8080, 'user', 'pass', null]],
            'tabs, as a spreadsheet pastes' => ["1.2.3.4\t8080\tuser\tpass", ['1.2.3.4', 8080, 'user', 'pass', null]],

            // The orders sellers actually ship.
            'port first' => ['8080:1.2.3.4:user:pass', ['1.2.3.4', 8080, 'user', 'pass', null]],
            'credentials first' => ['user|pass|1.2.3.4|8080', ['1.2.3.4', 8080, 'user', 'pass', null]],
            'credentials first, port before host' => ['user|pass|8080|1.2.3.4', ['1.2.3.4', 8080, 'user', 'pass', null]],

            'hostname instead of an IP' => ['gate.smartproxy.com:7000', ['gate.smartproxy.com', 7000, null, null, null]],
            'username but no password' => ['1.2.3.4:8080:user', ['1.2.3.4', 8080, 'user', null, null]],

            // The form curl and Guzzle already accept.
            'url form' => ['socks5://user:pass@1.2.3.4:1080', ['1.2.3.4', 1080, 'user', 'pass', 'socks5']],
            'url form without credentials' => ['http://1.2.3.4:8080', ['1.2.3.4', 8080, null, null, 'http']],
            'url form, password holds an @' => ['http://user:p@ss@1.2.3.4:8080', ['1.2.3.4', 8080, 'user', 'p@ss', 'http']],

            /*
             * A digits-only username is common enough that taking the first
             * numeric token would misread these. The port is the number beside
             * the host, whichever side that is.
             */
            'numeric credentials, host first' => ['1.2.3.4|8080|12345|67890', ['1.2.3.4', 8080, '12345', '67890', null]],
            'numeric credentials, host last' => ['12345|67890|1.2.3.4|8080', ['1.2.3.4', 8080, '12345', '67890', null]],

            // The password runs to the end of the line, separators and all.
            'separator inside the password' => ['1.2.3.4:8080:user:pa:ss', ['1.2.3.4', 8080, 'user', 'pa:ss', null]],

            'stray separators at the ends' => ['|1.2.3.4|8080|', ['1.2.3.4', 8080, null, null, null]],
            'surrounding whitespace' => ['   1.2.3.4:8080   ', ['1.2.3.4', 8080, null, null, null]],
        ];
    }

    #[Test]
    #[DataProvider('lines')]
    public function it_reads_a_line(string $raw, array $expected): void
    {
        $line = ProxyLine::parse($raw);

        $this->assertSame(
            $expected,
            [$line->host, $line->port, $line->username, $line->password, $line->scheme],
            $raw
        );
    }

    #[Test]
    public function a_line_with_no_host_is_refused(): void
    {
        // Rather than inventing one: a proxy pointed at the wrong address fails
        // at publish time, hours later, as an X error nobody can trace back.
        $this->expectException(InvalidProxyLine::class);

        ProxyLine::parse('hello world');
    }

    #[Test]
    public function a_line_with_no_port_is_refused(): void
    {
        $this->expectException(InvalidProxyLine::class);

        ProxyLine::parse('1.2.3.4');
    }

    #[Test]
    public function a_port_outside_the_range_is_not_a_port(): void
    {
        // 99999 is a typo, not a port, and reading it as one stores a value the
        // column cannot hold.
        $this->expectException(InvalidProxyLine::class);

        ProxyLine::parse('1.2.3.4:99999');
    }

    #[Test]
    public function a_bad_line_does_not_take_the_paste_down_with_it(): void
    {
        /*
         * Silently dropping it would be worse: someone pastes 500 proxies, sees
         * 499 appear, and has no way to find the one that did not.
         */
        [$parsed, $skipped] = ProxyLine::parseMany(
            "1.2.3.4:8080\n\nnot a proxy\n5.6.7.8|3128|u|p\n"
        );

        $this->assertCount(2, $parsed);
        $this->assertSame('1.2.3.4', $parsed[0]->host);
        $this->assertSame('5.6.7.8', $parsed[1]->host);

        $this->assertCount(1, $skipped);
        $this->assertSame('not a proxy', $skipped[0]['line']);
        $this->assertNotEmpty($skipped[0]['reason']);
    }

    #[Test]
    public function blank_lines_are_not_errors(): void
    {
        // A pasted block ends with a newline nearly every time.
        [$parsed, $skipped] = ProxyLine::parseMany("\n\n1.2.3.4:8080\n\n\n");

        $this->assertCount(1, $parsed);
        $this->assertSame([], $skipped);
    }
}
