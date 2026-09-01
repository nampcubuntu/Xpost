<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Proxy;
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
