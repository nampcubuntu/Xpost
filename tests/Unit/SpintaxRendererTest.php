<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\XAccount;
use App\Services\Publishing\SpintaxRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpintaxRendererTest extends TestCase
{
    private SpintaxRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new SpintaxRenderer;
    }

    #[Test]
    public function it_picks_one_option_from_a_group(): void
    {
        $output = $this->renderer->render('{a|b|c}');

        $this->assertContains($output, ['a', 'b', 'c']);
    }

    #[Test]
    public function it_resolves_nested_groups(): void
    {
        $output = $this->renderer->render('{x{1|2}|y}');

        $this->assertContains($output, ['x1', 'x2', 'y']);
    }

    #[Test]
    public function text_without_spintax_is_returned_untouched(): void
    {
        $this->assertSame('plain text', $this->renderer->render('plain text'));
    }

    #[Test]
    public function the_same_seed_reproduces_the_same_output(): void
    {
        $first = $this->renderer->render('{a|b|c|d|e}-{1|2|3|4|5}', 42);
        $second = $this->renderer->render('{a|b|c|d|e}-{1|2|3|4|5}', 42);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function it_counts_permutations(): void
    {
        // 3 x 2 = 6 distinct outputs.
        $this->assertSame(6, $this->renderer->permutationCount('{a|b|c} {x|y}'));
        $this->assertSame(1, $this->renderer->permutationCount('no spintax here'));
    }

    #[Test]
    public function it_substitutes_account_tokens(): void
    {
        $account = new XAccount(['username' => 'vnhandle', 'name' => 'VN Handle']);

        $output = $this->renderer->substitute('Xin chào [[name]] ([[handle]])', $account);

        $this->assertSame('Xin chào VN Handle (@vnhandle)', $output);
    }

    #[Test]
    public function unknown_tokens_are_left_visible(): void
    {
        $account = new XAccount(['username' => 'vnhandle']);

        // A typo should be obvious in the preview rather than silently blank.
        $this->assertSame('[[nope]]', $this->renderer->substitute('[[nope]]', $account));
    }
}
