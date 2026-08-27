<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    | Applied to every outbound call to the X API. Timeouts are deliberately
    | short: a publish worker blocked for 60s on one account is 60s of
    | throughput lost for every other account queued behind it.
    */

    'http' => [
        'timeout' => (int) env('XPOST_HTTP_TIMEOUT', 30),
        'connect_timeout' => (int) env('XPOST_HTTP_CONNECT_TIMEOUT', 10),
        'user_agent' => env('XPOST_USER_AGENT', 'Xpost/1.0 (+https://xpost.local)'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Publishing defaults
    |--------------------------------------------------------------------------
    | Per-account caps are the main lever against looking automated. 50/day is
    | a conservative default; raise it per account once an account has a track
    | record (health_score stays at 100).
    */

    'publishing' => [
        'default_account_daily_cap' => (int) env('XPOST_ACCOUNT_DAILY_CAP', 50),

        /*
         * How long a finished target stays in MySQL.
         *
         * post_targets is a work queue, not a ledger: every publish is already
         * in ClickHouse, partitioned by month and expiring on its own. Left to
         * accumulate this is the one table that grows with the whole pool --
         * a thousand teams of ten thousand accounts posting four times a day
         * is forty million rows a day.
         */
        'target_retention_days' => (int) env('XPOST_TARGET_RETENTION_DAYS', 30),

        /*
         * How a post with media and a website destination is rendered.
         *
         *   card_only  Always a video_website card -- video playing inline with
         *              a "From <domain>" caption. An account that cannot build
         *              one FAILS rather than publishing a lesser version, so a
         *              campaign looks the same everywhere it appears.
         *   auto       Card where possible, a call-to-action button elsewhere.
         *              Everything publishes, but the same post renders two
         *              different ways across the pool.
         *
         * card_only is a deliberate trade: a card needs an ads account, and X
         * allows exactly one FULL promotable user per ads account, so it can
         * never cover a pool of thousands. Choosing it means choosing
         * consistency over reach.
         *
         * Posts with no media are unaffected either way -- there is nothing to
         * build a card from, so the URL goes in the text and X makes a card
         * from the destination's own meta tags.
         */
        'destination_render' => env('XPOST_DESTINATION_RENDER', 'card_only'),

        /*
         * Which call to action carries the destination link on a post with
         * media, when destination_render allows one. X documents `visit_site`
         * and `watch_now` identically -- "HTTPS URL the CTA links to" -- and
         * says nothing about how either renders.
         *
         * Measured on a live post, 26 Aug 2026:
         *   visit_site  a "Visit <domain>" button over the video     ← works
         *   watch_now   accepted, 201, published, and X rendered no
         *               link at all. The URL is silently dropped.
         *
         * Left configurable in case X starts honouring watch_now, but there is
         * no reason to choose it today.
         */
        'media_cta' => env('XPOST_MEDIA_CTA', 'visit_site'),
        'app_burst_per_15min' => (int) env('XPOST_APP_BURST_15MIN', 300),
        'max_targets_per_post' => (int) env('XPOST_MAX_TARGETS_PER_POST', 20_000),
        'dispatch_limit_per_run' => (int) env('XPOST_DISPATCH_LIMIT', 1_000),
        'claim_lease_minutes' => (int) env('XPOST_CLAIM_LEASE_MINUTES', 15),
        'max_attempts' => (int) env('XPOST_MAX_ATTEMPTS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Composer limits
    |--------------------------------------------------------------------------
    | Mirrors what the X composer itself enforces, so the Vue counter and the
    | server-side validator agree on what "280" means.
    */

    'composer' => [
        'max_weighted_length' => 280,
        'max_media_per_post' => 4,
        'max_name_length' => 255,
        'max_headline_length' => 70,
        'allowed_image_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'allowed_video_mimes' => ['video/mp4', 'video/quicktime'],
        'max_upload_mb' => (int) env('XPOST_MAX_UPLOAD_MB', 512),
    ],

    /*
    |--------------------------------------------------------------------------
    | X pay-per-use pricing
    |--------------------------------------------------------------------------
    | What X bills per POST /2/tweets, so the composer and the schedule preview
    | quote the same number. A link is worth flagging in the UI: it makes a post
    | more than thirteen times more expensive, and a daily schedule multiplies
    | that by the audience every single day.
    |
    | These are list prices as of Feb 2026 -- override them if your contract
    | says otherwise.
    */

    'pricing' => [
        'post_usd' => (float) env('XPOST_PRICE_POST_USD', 0.015),
        'post_with_link_usd' => (float) env('XPOST_PRICE_POST_LINK_USD', 0.20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Account pool
    |--------------------------------------------------------------------------
    */

    'accounts' => [
        'max_per_team' => (int) env('XPOST_MAX_ACCOUNTS_PER_TEAM', 10_000),
        'max_per_app' => (int) env('XPOST_MAX_ACCOUNTS_PER_APP', 500),
        'max_per_proxy' => (int) env('XPOST_MAX_ACCOUNTS_PER_PROXY', 50),
        'cooldown_after_failures' => (int) env('XPOST_COOLDOWN_AFTER_FAILURES', 3),
        'disable_after_failures' => (int) env('XPOST_DISABLE_AFTER_FAILURES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | ClickHouse
    |--------------------------------------------------------------------------
    | Optional. With it off, analytics fall back to MySQL aggregates -- correct,
    | just slower over long ranges.
    */

    'clickhouse' => [
        'enabled' => (bool) env('CLICKHOUSE_ENABLED', false),
        'host' => env('CLICKHOUSE_HOST', '127.0.0.1'),
        'port' => (int) env('CLICKHOUSE_PORT', 8123),
        'username' => env('CLICKHOUSE_USERNAME', 'default'),
        'password' => env('CLICKHOUSE_PASSWORD', ''),
        'database' => env('CLICKHOUSE_DATABASE', 'xpost'),
        'timeout' => (float) env('CLICKHOUSE_TIMEOUT', 10),
        'connect_timeout' => (int) env('CLICKHOUSE_CONNECT_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics sync
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'lookback_hours' => (int) env('XPOST_METRICS_LOOKBACK_HOURS', 72),
        'accounts_per_run' => (int) env('XPOST_METRICS_ACCOUNTS_PER_RUN', 1_000),
        'flush_batch_size' => (int) env('XPOST_METRICS_FLUSH_SIZE', 5_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Links (Cloudflare Workers KV)
    |--------------------------------------------------------------------------
    | The key/value store a Worker reads to resolve short links. Managed from
    | Xpost so the pairs are not maintained by hand in the Cloudflare dashboard,
    | which does not scale past a few dozen.
    |
    | The token wants exactly one permission -- Workers KV Storage: Edit -- and
    | should be a scoped token, never the Global API Key, which can do anything
    | to the whole account.
    */

    'links' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'namespace_id' => env('CLOUDFLARE_KV_NAMESPACE_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        // Where a short code resolves, e.g. https://l.example.com -- used to
        // build the URL a post carries, never to call Cloudflare.
        'base_url' => rtrim((string) env('XPOST_LINK_BASE_URL', ''), '/'),

        /*
         * Shortening the short link again, through TinyURL.
         *
         * The token is optional: without one the legacy endpoint is used, which
         * needs no account but cannot set an alias or report clicks. Get a
         * token at tinyurl.com/app/settings/api to lift both.
         */
        'tinyurl' => [
            'enabled' => (bool) env('TINYURL_ENABLED', false),
            'token' => env('TINYURL_TOKEN'),
            'domain' => env('TINYURL_DOMAIN', 'tinyurl.com'),
        ],
    ],

];
