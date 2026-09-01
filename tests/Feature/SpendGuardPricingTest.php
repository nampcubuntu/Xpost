<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Team;
use App\Models\XAccount;
use App\Services\Publishing\SpendGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a post is expected to cost, before it is sent.
 *
 * $0.20 against $0.015 is not a rounding difference: at a thousand accounts a
 * day it is $6,000 a month against $450. The rate turns on where the URL lands
 * in the request body, and the budget is held before anything is spent, so this
 * has to be decidable from the target alone.
 */
class SpendGuardPricingTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private SpendGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team] = $this->tenant();
        $this->guard = app(SpendGuard::class);
    }

    private function target(array $overrides = []): PostTarget
    {
        $post = Post::factory()->create(['team_id' => $this->team->id]);
        $account = XAccount::factory()->create(['team_id' => $this->team->id]);

        return PostTarget::factory()->create($overrides + [
            'post_id' => $post->id,
            'team_id' => $this->team->id,
            'x_account_id' => $account->id,
        ])->fresh();
    }

    #[Test]
    public function a_plain_post_is_the_cheap_rate(): void
    {
        $target = $this->target(['rendered_url' => null, 'rendered_media_ids' => null]);

        $this->assertSame(2, $this->guard->costCentsFor($target));
    }

    #[Test]
    public function a_url_in_the_text_is_the_expensive_rate(): void
    {
        /*
         * No media and no card, so TweetPayload appends the URL to the text --
         * which is the one shape nobody disputes is "a post with a URL".
         */
        $target = $this->target(['rendered_url' => 'https://twvideo.top/r/abc', 'rendered_media_ids' => null]);

        $this->assertSame(20, $this->guard->costCentsFor($target));
    }

    #[Test]
    public function a_card_post_is_charged_as_a_link_until_the_invoice_says_otherwise(): void
    {
        /*
         * The URL is inside the card, not the body. X's price list says "Post
         * Creation (with URL)" and does not say where it looks, and the two
         * readings are thirteen times apart -- so the default overcharges. A
         * campaign that stops early is recoverable; one that runs thirteen
         * times over its ceiling is an invoice.
         */
        $target = $this->target([
            'rendered_url' => 'https://twvideo.top/r/abc',
            'card_uri' => 'card://1234567890',
        ]);

        $this->assertSame(20, $this->guard->costCentsFor($target));
    }

    #[Test]
    public function a_card_post_becomes_cheap_when_the_usage_page_confirms_it(): void
    {
        // One env flip, once the Developer Console shows card posts landing on
        // the "Post creation" line rather than "Post creation (with URL)".
        config(['xpost.pricing.card_counts_as_link' => false]);

        $target = $this->target([
            'rendered_url' => 'https://twvideo.top/r/abc',
            'card_uri' => 'card://1234567890',
        ]);

        $this->assertSame(2, $this->guard->costCentsFor($target));
    }

    #[Test]
    public function media_alone_keeps_the_url_out_of_the_text(): void
    {
        /*
         * With media attached the URL goes in media.call_to_actions, never in
         * the body -- so it follows the same switch as a card, even though no
         * card exists yet. Whether one will be minted is not known here: the
         * budget is reserved before resolveCard() runs, because the money has
         * to be held before it can be spent.
         */
        config(['xpost.pricing.card_counts_as_link' => false]);

        $target = $this->target([
            'rendered_url' => 'https://twvideo.top/r/abc',
            'rendered_media_ids' => [1, 2],
            'card_uri' => null,
        ]);

        $this->assertSame(2, $this->guard->costCentsFor($target));
    }

    #[Test]
    public function the_switch_never_makes_a_text_link_cheap(): void
    {
        // The one shape that is certain stays certain: turning the switch off
        // must not reprice a post whose URL is plainly in the text.
        config(['xpost.pricing.card_counts_as_link' => false]);

        $target = $this->target([
            'rendered_url' => 'https://twvideo.top/r/abc',
            'rendered_media_ids' => null,
            'card_uri' => null,
        ]);

        $this->assertSame(20, $this->guard->costCentsFor($target));
    }
}
