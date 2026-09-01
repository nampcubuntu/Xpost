<?php

declare(strict_types=1);

namespace App\Services\X;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Whether X is quietly limiting what an account can reach.
 *
 * There is no shadowban endpoint anywhere. Sorsa's own is a browser tool, not
 * an API -- so this composes the answer from two calls that do exist, which is
 * why it is a service rather than a client method:
 *
 *   search-users  with the bare handle  -> does X's own people-search return
 *                                          it, and what does the profile say
 *                                          about itself
 *   search-tweets with `from:handle`    -> do the account's posts appear in
 *                                          search at all
 *
 * Two requests, not four: the user search answers the suggestion ban, the
 * sensitive flag and the protected flag together.
 *
 * Every verdict distinguishes three states, never two. "Not restricted" and
 * "could not tell" are different answers, and collapsing them is how a page
 * ends up reporting a clean bill of health for an account it never reached --
 * which is the same lie as a proxy showing "healthy" from a check five days
 * ago.
 */
final class ShadowbanChecker
{
    public const OK = 'ok';

    public const RESTRICTED = 'restricted';

    public const UNKNOWN = 'unknown';

    public function isConfigured(): bool
    {
        return filled(config('xpost.links.sorsa.key'));
    }

    /**
     * One handle, as far as two requests can tell.
     *
     * A handle rather than an account: the question "is X limiting this" is
     * asked about names, and the useful ones include accounts not connected to
     * this install at all -- a competitor, a handle being bought, one that was
     * disconnected after it went quiet.
     *
     * @return array{state: string, checked_at: string, detail: array<string, mixed>}
     */
    public function check(string $handle): array
    {
        $handle = ltrim(trim($handle), '@');

        $profile = $this->searchUsers($handle);
        $posts = $this->searchTweets($handle);
        $found = $posts['found'] ?? null;

        $detail = [
            /*
             * A handle X will not autocomplete is the classic search suggestion
             * ban. Matched case-insensitively and across the whole page,
             * because the account is rarely the first result for its own name.
             */
            'search_suggestion' => $profile === null
                ? self::UNKNOWN
                : ($profile['found'] ? self::OK : self::RESTRICTED),

            /*
             * `from:handle` returning nothing is a search ban -- unless the
             * account has never posted, which is not a restriction and must not
             * be reported as one.
             *
             * The comment above said this before the code did. Running it
             * against the live API is what found the gap: the first account
             * tested came back with zero results, and only its tweets_count
             * settled whether that meant anything.
             */
            'search' => $this->searchVerdict($found, $profile['tweets_count'] ?? null),

            /*
             * X limiting who can see an account's replies. Read off the
             * posts the search already returned, so it costs nothing --
             * and it is a restriction the other two tests cannot see.
             */
            'replies' => $posts === null
                ? self::UNKNOWN
                : ($posts['replies_limited'] > 0 ? self::RESTRICTED : self::OK),

            'posts_found' => $found,
            // True when search had more pages, so posts_found is a page
            // rather than a total and must not be printed as one.
            'posts_more' => $posts['has_more'] ?? null,
            'replies_limited' => $posts['replies_limited'] ?? null,
            'posts_total' => $profile['tweets_count'] ?? null,

            /*
             * These two are the account's own settings rather than anything X
             * imposed, and they are here because they explain a restricted
             * verdict above: a protected account cannot appear in search at
             * all, so a search ban reading on one means nothing.
             */
            'protected' => $profile['protected'] ?? null,
            'sensitive' => $profile['sensitive'] ?? null,
        ];

        return [
            'state' => $this->summarise($detail),
            'checked_at' => now()->toIso8601String(),
            'detail' => $detail,
        ];
    }

    /**
     * What an empty `from:handle` result actually means.
     *
     * Nothing came back is only a ban if there was something to come back. An
     * account that has never posted is not being hidden, and reporting it as
     * hidden sends someone looking for a restriction X never applied.
     *
     * When the post count could not be read the answer is unknown rather than
     * either -- guessing in this direction invents the more alarming of the two.
     */
    private function searchVerdict(?int $found, ?int $tweetsCount): string
    {
        if ($found === null) {
            return self::UNKNOWN;
        }

        if ($found > 0) {
            return self::OK;
        }

        return match (true) {
            $tweetsCount === null => self::UNKNOWN,
            $tweetsCount === 0 => self::UNKNOWN,
            default => self::RESTRICTED,
        };
    }

    /**
     * The row's one-word answer.
     *
     * A protected account is reported as its own state rather than as
     * restricted: the tests above cannot see it by design, and calling that a
     * shadowban would send someone hunting for something X never did.
     */
    private function summarise(array $detail): string
    {
        if ($detail['protected'] === true) {
            return 'protected';
        }

        if (in_array(self::RESTRICTED, [$detail['search_suggestion'], $detail['search'], $detail['replies']], true)) {
            return self::RESTRICTED;
        }

        // Unknown wins over ok: a check that could not reach X has not cleared
        // anything.
        if (in_array(self::UNKNOWN, [$detail['search_suggestion'], $detail['search'], $detail['replies']], true)) {
            return self::UNKNOWN;
        }

        return self::OK;
    }

    /**
     * The handle as X's people search sees it, or null when the call failed.
     *
     * @return array{found: bool, protected: ?bool, sensitive: ?bool}|null
     */
    private function searchUsers(string $handle): ?array
    {
        $body = $this->post('search-users', ['query' => $handle]);

        if ($body === null) {
            return null;
        }

        foreach ((array) ($body['users'] ?? []) as $user) {
            if (strcasecmp((string) ($user['username'] ?? ''), $handle) !== 0) {
                continue;
            }

            return [
                'found' => true,
                'protected' => (bool) ($user['protected'] ?? false),
                'sensitive' => (bool) ($user['possibly_sensitive'] ?? false),
                // How many posts X thinks the account has. Free -- the same
                // call already returns it -- and it is the difference between
                // a search ban and an account that has never posted.
                'tweets_count' => isset($user['tweets_count']) ? (int) $user['tweets_count'] : null,
            ];
        }

        // Reached X, and the handle was not among the results.
        return ['found' => false, 'protected' => null, 'sensitive' => null, 'tweets_count' => null];
    }

    /**
     * What search returns for `from:handle`, or null when the call failed.
     *
     * More than a count, because the count on its own was being read as "this
     * many posts are findable" -- and it is a page. The response carries a
     * next_cursor, so twenty means twenty-and-more, and the page has to say so
     * rather than print a number that looks total.
     *
     * is_replies_limited comes from the same response and is the one restriction
     * neither of these two calls was being asked about: X limiting who can see
     * an account's replies, which no amount of searching for its posts reveals.
     *
     * @return array{found: int, has_more: bool, replies_limited: int}|null
     */
    private function searchTweets(string $handle): ?array
    {
        $body = $this->post('search-tweets', [
            'query' => "from:{$handle}",
            // Newest first: an old post surfacing says nothing about whether
            // what was published this week can be found.
            'order' => 'latest',
        ]);

        if ($body === null) {
            return null;
        }

        $tweets = (array) ($body['tweets'] ?? []);

        return [
            'found' => count($tweets),
            // Cloudflare-style empty string, not null, on the last page.
            'has_more' => filled($body['next_cursor'] ?? null),
            'replies_limited' => count(array_filter(
                $tweets,
                fn (array $t) => ($t['is_replies_limited'] ?? false) === true,
            )),
        ];
    }

    /**
     * @return array<string, mixed>|null null when the call did not succeed
     */
    private function post(string $path, array $json): ?array
    {
        try {
            $response = Http::withHeaders(['ApiKey' => (string) config('xpost.links.sorsa.key')])
                ->acceptJson()
                ->timeout((int) config('xpost.http.timeout', 30))
                ->post(rtrim((string) config('xpost.links.sorsa.base_url'), '/').'/'.$path, $json);

            return $response->successful() ? (array) $response->json() : null;
        } catch (Throwable) {
            /*
             * Swallowed into null rather than thrown. A visibility check is
             * something the operator asked for out of curiosity; it runs over
             * a thousand accounts, and Sorsa being down is a row that says
             * "unknown", not a failed sweep.
             */
            return null;
        }
    }
}
