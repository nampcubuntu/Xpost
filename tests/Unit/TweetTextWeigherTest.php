<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Publishing\TweetTextWeigher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TweetTextWeigherTest extends TestCase
{
    #[Test]
    public function plain_latin_text_weighs_one_per_character(): void
    {
        $this->assertSame(11, TweetTextWeigher::weight('hello world'));
    }

    #[Test]
    public function cjk_characters_weigh_two(): void
    {
        // Three CJK glyphs -> 6, which is why a "150 character" Japanese post
        // can still be rejected at 280.
        $this->assertSame(6, TweetTextWeigher::weight('日本語'));
    }

    #[Test]
    public function urls_are_normalised_to_twenty_three_characters(): void
    {
        $short = TweetTextWeigher::weight('https://x.com');
        $long = TweetTextWeigher::weight('https://example.com/a/very/long/path?with=query&and=more');

        $this->assertSame(23, $short);
        $this->assertSame(23, $long);
    }

    #[Test]
    public function url_weight_is_added_to_surrounding_text(): void
    {
        // "check " is 6, the URL counts as 23.
        $this->assertSame(29, TweetTextWeigher::weight('check https://example.com'));
    }

    #[Test]
    public function it_reports_remaining_budget(): void
    {
        $this->assertSame(269, TweetTextWeigher::remaining('hello world'));
        $this->assertTrue(TweetTextWeigher::fits(str_repeat('a', 280)));
        $this->assertFalse(TweetTextWeigher::fits(str_repeat('a', 281)));
    }

    #[Test]
    public function it_extracts_urls(): void
    {
        $urls = TweetTextWeigher::urls('see https://a.com and https://b.com/x');

        $this->assertSame(['https://a.com', 'https://b.com/x'], $urls);
    }
}
