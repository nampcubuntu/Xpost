<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Proxy;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Telling a proxy's refusal apart from X's.
 *
 * Every call an account makes goes through its proxy, so a failure can come
 * from either end of the wire -- and the two need completely different actions.
 * The message that reached the failed column said "HTTP request returned status
 * code 407", which is Laravel describing a response it does not know the origin
 * of, and the log line above it said "X API error". Both blame X. X never saw
 * the request.
 *
 * 407 is the unambiguous one: RFC 9110 defines it as a proxy asking for
 * credentials, so it cannot have come from the origin server. The transport
 * failures are matched on curl's own numbers, which are stable and specific.
 *
 * Everything else passes through untouched. A wrong explanation is worse than
 * a generic one -- it sends whoever reads it somewhere else entirely.
 */
final class ProxyFailure
{
    /**
     * A status that could only have come from the proxy, explained.
     *
     * Returns null when the status says nothing about the proxy, which is the
     * normal case and means the caller should carry on treating it as X's.
     */
    public static function fromStatus(int $status, ?Proxy $proxy): ?string
    {
        if ($status !== 407) {
            return null;
        }

        return 'Proxy '.self::name($proxy).' từ chối xác thực (407). '
            .'Sai user/password, hoặc nhà cung cấp yêu cầu whitelist IP máy chủ. '
            .'Yêu cầu chưa từng đi tới X.';
    }

    /**
     * A transport failure, explained in terms of the hop that failed.
     *
     * The curl numbers rather than the prose: the text is localised and
     * reworded between versions, and 7 against a proxy has one meaning.
     */
    public static function fromThrowable(Throwable $e, ?Proxy $proxy): ?string
    {
        $message = $e->getMessage();

        if ($proxy === null) {
            return null;
        }

        /*
         * A 407 that arrived as a response rather than as a failed tunnel.
         *
         * curl surfaces the proxy's refusal either way depending on the scheme
         * and the proxy, and Laravel wraps the response form in a
         * RequestException whose message is "HTTP request returned status code
         * 407" -- which names neither the proxy nor what 407 means. That exact
         * sentence is what reached the failed column.
         */
        if ($e instanceof RequestException && $e->response->status() === 407) {
            return self::fromStatus(407, $proxy);
        }

        // The same thing having passed through a layer that kept only the text.
        if (str_contains($message, 'status code 407')) {
            return self::fromStatus(407, $proxy);
        }

        /*
         * A transport error that names some other endpoint is not this proxy's.
         *
         * "Pusher error: cURL error 28: Failed to connect to 127.0.0.1 port
         * 8080" is Reverb being down, and matching on the curl number alone
         * blamed the account's proxy for it -- on rows where the proxy was
         * fine. That is exactly the wrong turn this class exists to prevent,
         * and it took real data to notice, because the sentence it produced
         * read perfectly.
         */
        if (self::namesAnotherHost($message, $proxy)) {
            return null;
        }

        // curl 7 through a proxy is the CONNECT tunnel being refused, and it
        // carries the proxy's own status when there was one.
        if (str_contains($message, 'CONNECT tunnel failed')) {
            return str_contains($message, '407')
                ? self::fromStatus(407, $proxy)
                : 'Proxy '.self::name($proxy).' từ chối mở đường tới X. '.self::tail($message);
        }

        if (str_contains($message, 'cURL error 7')) {
            return 'Không kết nối được tới proxy '.self::name($proxy).'. '
                .'Proxy đã tắt, sai cổng, hoặc đang chặn IP máy chủ.';
        }

        if (str_contains($message, 'cURL error 28')) {
            return 'Proxy '.self::name($proxy).' không trả lời kịp thời gian chờ. '
                .'Proxy quá tải hoặc đường tới nó đang tắc.';
        }

        // 35 is the TLS handshake, 56 a broken read: both mean the hop died
        // mid-conversation, which a proxy is the usual cause of.
        if (str_contains($message, 'cURL error 35') || str_contains($message, 'cURL error 56')) {
            return 'Kết nối qua proxy '.self::name($proxy).' bị ngắt giữa chừng. '.self::tail($message);
        }

        return null;
    }

    /**
     * Whether curl is complaining about somewhere other than this proxy.
     *
     * curl names the endpoint it could not reach -- "Failed to connect to HOST
     * port N" -- and when that is not the proxy, the proxy is not the story.
     * Only the connect phrasing is matched: a refused CONNECT tunnel names the
     * *destination* after "for https://…", which is X and says nothing about
     * where the fault lies.
     */
    private static function namesAnotherHost(string $message, Proxy $proxy): bool
    {
        preg_match_all('/connect to\s+([A-Za-z0-9._-]+)\s+port\s+(\d+)/i', $message, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $host, $port]) {
            if (strcasecmp($host, (string) $proxy->host) !== 0 || (int) $port !== (int) $proxy->port) {
                return true;
            }
        }

        return false;
    }

    /** host:port, because that is what the proxy page shows and sorts by. */
    private static function name(?Proxy $proxy): string
    {
        return $proxy === null ? '(không rõ)' : "{$proxy->host}:{$proxy->port}";
    }

    /** The original text, kept short enough to sit inside one column. */
    private static function tail(string $message): string
    {
        return '('.mb_substr(trim($message), 0, 120).')';
    }
}
