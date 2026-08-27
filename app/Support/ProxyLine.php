<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\InvalidProxyLine;

/**
 * One pasted line turned into a proxy.
 *
 * Proxies arrive as a text blob from whoever sold them, and every seller picks
 * a different column order. Asking the buyer to reorder a thousand lines into
 * the one shape a form expects is the kind of work software is for, so nothing
 * here is positional: each field is recognised by what it looks like.
 *
 *   1.2.3.4:8080                      host and port, nothing else
 *   1.2.3.4|8080|user|pass            pipes
 *   8080:1.2.3.4:user:pass            port first -- some sellers do this
 *   user|pass|1.2.3.4|8080            credentials first -- so do others
 *   socks5://user:pass@1.2.3.4:1080   the URL form curl and Guzzle accept
 *
 * The rules, in the order they are applied:
 *
 *  - An IP wins as the host. Failing that, a name containing a dot.
 *  - The port is the numeric token *next to* the host. Adjacency is what
 *    separates a port from a numeric username: nobody writes a host and its
 *    port apart from each other, and plenty of sellers issue usernames that
 *    are all digits.
 *  - Whatever is left is the username, then the password, in the order they
 *    were written. Their order relative to each other is the one thing that
 *    cannot be inferred -- two opaque strings look alike -- so it is the one
 *    thing taken positionally.
 *
 * Credentials are optional throughout: an IP-authorised proxy is a complete
 * line at two tokens.
 */
final class ProxyLine
{
    /** Anything a seller might separate columns with, including a plain space. */
    private const SEPARATOR = '/([|:,;\t ]+)/';

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly ?string $scheme = null,
    ) {}

    /**
     * @throws InvalidProxyLine when the line has no host or no port
     */
    public static function parse(string $raw): self
    {
        $line = trim($raw);

        if ($line === '') {
            throw new InvalidProxyLine('Dòng trống.');
        }

        $scheme = null;

        if (preg_match('~^(https?|socks[45])://~i', $line, $matches)) {
            $scheme = strtolower($matches[1]);
            $line = substr($line, strlen($matches[0]));
        }

        /*
         * The URL form states the order itself, so it is read before the
         * guessing starts. Split on the *last* @: a password may contain one,
         * a host may not.
         */
        $username = $password = null;

        if (($at = strrpos($line, '@')) !== false) {
            [$username, $password] = array_pad(explode(':', substr($line, 0, $at), 2), 2, null);
            $line = substr($line, $at + 1);
        }

        [$tokens, $separators] = self::split($line);

        $host = self::hostIndex($tokens);

        if ($host === null) {
            throw new InvalidProxyLine('Không tìm thấy host (IP hoặc tên miền).');
        }

        $port = self::portIndex($tokens, $host);

        if ($port === null) {
            throw new InvalidProxyLine('Không tìm thấy port (số từ 1 đến 65535).');
        }

        if ($username === null) {
            [$username, $password] = self::credentials($tokens, $separators, $host, $port);
        }

        return new self(
            host: $tokens[$host],
            port: (int) $tokens[$port],
            username: blank($username) ? null : $username,
            password: blank($password) ? null : $password,
            scheme: $scheme,
        );
    }

    /**
     * Every line of a paste, with the ones that could not be read reported
     * rather than dropped.
     *
     * Silently skipping a bad line is how someone pastes 500 proxies, sees 480
     * appear, and never finds out which 20 are missing.
     *
     * @return array{0: list<self>, 1: list<array{line: string, reason: string}>}
     */
    public static function parseMany(string $text): array
    {
        $parsed = [];
        $skipped = [];

        foreach (preg_split('/\R/', $text) ?: [] as $raw) {
            if (trim($raw) === '') {
                continue;
            }

            try {
                $parsed[] = self::parse($raw);
            } catch (InvalidProxyLine $e) {
                $skipped[] = ['line' => trim($raw), 'reason' => $e->getMessage()];
            }
        }

        return [$parsed, $skipped];
    }

    /**
     * Tokens and the separators between them, kept apart.
     *
     * The separators are carried along so a password containing one can be put
     * back together verbatim -- `host:port:user:pa:ss` has five tokens and a
     * password of `pa:ss`, not a password of `pa` and a discarded `ss`.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function split(string $value): array
    {
        /*
         * Separators are trimmed off the ends before splitting rather than
         * empty tokens being filtered out after. Filtering re-indexes the
         * tokens and leaves the separators where they were, which puts the two
         * lists one apart and rebuilds a password out of the wrong pieces.
         */
        $pieces = preg_split(self::SEPARATOR, trim($value, "|:,;\t \r\n"), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        $tokens = [];
        $separators = [];

        foreach ($pieces as $index => $piece) {
            if ($index % 2 === 0) {
                $tokens[] = $piece;
            } else {
                $separators[] = $piece;
            }
        }

        return [$tokens, $separators];
    }

    /** An IP is unambiguous; a dotted name is the fallback. */
    private static function hostIndex(array $tokens): ?int
    {
        foreach ($tokens as $index => $token) {
            if (filter_var($token, FILTER_VALIDATE_IP) !== false) {
                return $index;
            }
        }

        foreach ($tokens as $index => $token) {
            $bare = str_replace(['.', '-'], '', $token);

            if (str_contains($token, '.') && ctype_alnum($bare) && ! ctype_digit($bare)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The numeric token beside the host, before any numeric token elsewhere.
     *
     * Adjacency is doing real work: `user|pass|1.2.3.4|8080` and
     * `8080|1.2.3.4|user|pass` both have digits-only usernames in the wild, and
     * taking the first numeric token would pick the username in one of them.
     */
    private static function portIndex(array $tokens, int $host): ?int
    {
        $looksLikePort = static fn (string $token) => ctype_digit($token)
            && (int) $token >= 1
            && (int) $token <= 65535;

        foreach ([$host + 1, $host - 1] as $index) {
            if (isset($tokens[$index]) && $looksLikePort($tokens[$index])) {
                return $index;
            }
        }

        foreach ($tokens as $index => $token) {
            if ($index !== $host && $looksLikePort($token)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * What is left after the host and port, in the order it was written.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function credentials(array $tokens, array $separators, int $host, int $port): array
    {
        $rest = array_values(array_diff(array_keys($tokens), [$host, $port]));

        if ($rest === []) {
            return [null, null];
        }

        if (count($rest) === 1) {
            return [$tokens[$rest[0]], null];
        }

        /*
         * The password runs to the end of the line, separators and all -- but
         * only when nothing else is out there. A host written last means the
         * tail is not one field, so take the single token instead of swallowing
         * the address into the password.
         */
        $tail = range($rest[1], count($tokens) - 1);
        $clean = count(array_diff($tail, [$host, $port])) === count($tail);

        return [
            $tokens[$rest[0]],
            $clean ? self::rejoin($tokens, $separators, $rest[1]) : $tokens[$rest[1]],
        ];
    }

    /** Tokens from $from to the end, with the separators they were split on. */
    private static function rejoin(array $tokens, array $separators, int $from): string
    {
        $out = $tokens[$from];

        for ($index = $from; $index < count($tokens) - 1; $index++) {
            $out .= ($separators[$index] ?? ':').$tokens[$index + 1];
        }

        return $out;
    }
}
