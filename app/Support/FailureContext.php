<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PostTarget;

/**
 * Where a failure came from, rather than what it was called.
 *
 * `internal_error` is a status, not a diagnosis. It says the publisher reached
 * its catch-all, which is true of a dead proxy, a bug here, and a library
 * throwing something nobody anticipated -- three problems with three different
 * owners and nothing on the page telling them apart.
 *
 * So every failure is resolved into four things a person can act on: which
 * account it was publishing as, which proxy it went out through, which stage it
 * got to, and who is at fault. The stage comes from the code; the culprit comes
 * from the message where the message can prove it.
 *
 * The message is re-read here rather than at write time on purpose. Rows failed
 * before ProxyFailure existed still carry "HTTP request returned status code
 * 407", and rewriting stored errors would be rewriting history -- explaining
 * them on the way out costs nothing and fixes every row at once.
 */
final class FailureContext
{
    /** Which part of publishing the target had reached. */
    private const STAGES = [
        'card_unavailable' => 'Tạo card quảng cáo (Ads API)',
        'ads_api_error' => 'Tạo card quảng cáo (Ads API)',
        'proxy_rejected' => 'Kết nối ra ngoài qua proxy',
        'proxy_unreachable' => 'Kết nối ra ngoài qua proxy',
        'out_of_credit' => 'X từ chối vì hết số dư',
        'no_app_capacity' => 'Chọn X App còn hạn mức',
        'app_capacity' => 'Chọn X App còn hạn mức',
        'account_missing' => 'Trước khi gửi — tài khoản đã bị xoá',
        'job_failed' => 'Job trong hàng đợi',
        'abandoned' => 'Hàng đợi thu hồi job treo',
        'reclaimed' => 'Hàng đợi thu hồi job treo',
        'internal_error' => 'Trong lúc đăng',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public static function for(?PostTarget $target): ?array
    {
        if ($target === null || blank($target->error_code)) {
            return null;
        }

        $account = $target->account;
        $proxy = $account?->proxy;
        $raw = (string) $target->error_message;

        /*
         * The stored text, re-explained if it can be. A 407 written before this
         * existed still names nothing; run through the same rule it now names
         * the proxy that refused it.
         */
        $explained = $raw === '' ? null : ProxyFailure::fromThrowable(new \RuntimeException($raw), $proxy);

        return [
            'code' => $target->error_code,
            'message' => $explained ?? $raw,
            // Kept when it was rewritten, so nothing is hidden from whoever is
            // comparing this against a log.
            'raw' => $explained === null ? null : $raw,
            'stage' => self::STAGES[$target->error_code] ?? 'Trong lúc đăng',
            'blame' => self::blame((string) $target->error_code, $explained !== null),
            'account' => $account?->handle(),
            'proxy' => $proxy === null ? null : "{$proxy->host}:{$proxy->port}",
            'proxy_id' => $proxy?->id,
            'at' => $target->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Who to go and fix, in one word.
     *
     * Only claimed where it is provable: a proxy that refused in its own words,
     * or a code that can only mean one thing. `internal_error` whose message
     * says nothing stays "Xpost", because the catch-all is ours and an
     * unrecognised throw there is our problem until shown otherwise.
     */
    private static function blame(string $code, bool $provenProxy): string
    {
        if ($provenProxy || in_array($code, ['proxy_rejected', 'proxy_unreachable'], true)) {
            return 'proxy';
        }

        return match ($code) {
            'out_of_credit', 'ads_api_error', 'card_unavailable' => 'x',
            'account_missing' => 'account',
            default => 'xpost',
        };
    }
}
