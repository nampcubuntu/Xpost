<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Models\PostTarget;
use App\Models\Team;
use Illuminate\Support\Facades\Redis;

/**
 * What a team has spent at X today, and whether it may spend more.
 *
 * Posting is pay-per-use with no free tier, so the only thing that stopped a
 * team before this was the balance running out -- which arrives too late to be
 * a control, and stops every other team with it. A recurring schedule pointed
 * at ten thousand accounts spends whatever it is given, overnight, and the
 * first anyone knows is the invoice.
 *
 * Held in Redis in whole cents. Floats do not add up: a hundred thousand posts
 * at $0.015 accumulate a visible error, and a budget that drifts is not a
 * budget.
 */
final class SpendGuard
{
    /**
     * Add to today's spend and answer whether it stayed inside the budget,
     * in one round trip. The TTL is set only on the first write of the day.
     */
    private const CONSUME = <<<'LUA'
        local spent = redis.call('INCRBY', KEYS[1], ARGV[1])
        if spent == tonumber(ARGV[1]) then
            redis.call('EXPIRE', KEYS[1], ARGV[3])
        end
        if tonumber(ARGV[2]) >= 0 and spent > tonumber(ARGV[2]) then
            return {0, spent}
        end
        return {1, spent}
    LUA;

    public function __construct(private readonly string $prefix = 'xpost:spend') {}

    /**
     * What X will charge for this target, in cents.
     *
     * A link is the expensive case by an order of magnitude -- $0.20 against
     * $0.015 -- so this is not a rounding difference. At a thousand accounts a
     * day it is the difference between $450 a month and $6,000.
     *
     * The rate turns on where the URL actually lands in the request body, which
     * is TweetPayload's rule and is decidable here:
     *
     *   no media, no card  ->  the URL is appended to the text
     *   media attached     ->  it goes in media.call_to_actions
     *   card attached      ->  it lives inside the card
     *
     * Only the first is unambiguously "a post with a URL". X's price list says
     * that phrase and does not say where it looks, so the other two are charged
     * at the link rate by default -- overcharging a budget stops a campaign
     * early, undercharging it lets one run thirteen times over.
     *
     * Set XPOST_PRICE_CARD_AS_LINK=false once the Usage page has been read and
     * the cheap rate is confirmed for cards.
     */
    public function costCentsFor(PostTarget $target): int
    {
        $rate = $this->carriesLink($target)
            ? (float) config('xpost.pricing.post_with_link_usd', 0.20)
            : (float) config('xpost.pricing.post_usd', 0.015);

        return (int) round($rate * 100);
    }

    private function carriesLink(PostTarget $target): bool
    {
        if (trim((string) $target->rendered_url) === '') {
            return false;
        }

        // Whether a card will be minted is not known yet -- the reservation
        // happens before resolveCard(), because the budget has to be held
        // before anything is spent. Media is known, and media alone is enough
        // to keep the URL out of the text.
        $inText = blank($target->card_uri) && empty($target->rendered_media_ids);

        return $inText || (bool) config('xpost.pricing.card_counts_as_link', true);
    }

    /**
     * Reserve the cost of one post against today's budget.
     *
     * @return bool false when the team has spent its budget for the day
     */
    public function reserve(int $teamId, int $cents): bool
    {
        $budget = $this->budgetCentsFor($teamId);

        [$allowed] = Redis::eval(self::CONSUME, 1, $this->key($teamId), $cents, $budget, $this->secondsLeftToday());

        return (int) $allowed === 1;
    }

    /**
     * Hand back what was reserved for a post that never went out.
     *
     * Reserving before the call to X is what makes the ceiling hold under
     * concurrency; not refunding a refusal is what makes it drift downwards
     * until a team is stopped by money it never spent.
     */
    public function release(int $teamId, int $cents): void
    {
        Redis::decrby($this->key($teamId), $cents);
    }

    public function spentCentsToday(int $teamId): int
    {
        return (int) Redis::get($this->key($teamId));
    }

    /** Null when the team has no ceiling, which is the default. */
    public function budgetCentsFor(int $teamId): int
    {
        $budget = Team::query()->whereKey($teamId)->value('daily_budget_usd');

        // -1 rather than null: the Lua script compares numbers, and "no
        // ceiling" has to be a value it can be handed.
        return $budget === null ? -1 : (int) round((float) $budget * 100);
    }

    /**
     * The counter expires at midnight rather than after a rolling day, so a
     * budget means "today" the way an invoice does.
     */
    private function secondsLeftToday(): int
    {
        return max(60, (int) now()->diffInSeconds(now()->endOfDay(), false) + 1);
    }

    private function key(int $teamId): string
    {
        return "{$this->prefix}:{$teamId}:".now()->format('Ymd');
    }
}
