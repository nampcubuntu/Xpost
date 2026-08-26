<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\XAccount;
use App\Models\XApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountSearchTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team] = $this->tenant();

        $app = XApp::factory()->create(['team_id' => $this->team->id]);

        foreach ([['goodboy_9x', 'goodboy ads'], ['goodboyX9x', 'kẻ mạo danh'], ['khac', 'Tài khoản khác']] as [$handle, $name]) {
            XAccount::factory()->create([
                'team_id' => $this->team->id,
                'x_app_id' => $app->id,
                'username' => $handle,
                'name' => $name,
            ]);
        }
    }

    #[Test]
    public function it_finds_a_handle_pasted_with_its_at_sign(): void
    {
        // The UI renders handles as "@goodboy_9x" and the search box asks for
        // one, so pasting exactly what is on screen has to work.
        $this->assertSame(1, XAccount::search('@goodboy_9x')->count());
    }

    #[Test]
    public function an_underscore_is_not_treated_as_a_wildcard(): void
    {
        // LIKE reads `_` as "any character", which would also match goodboyX9x.
        $this->assertSame(
            ['goodboy_9x'],
            XAccount::search('goodboy_9x')->pluck('username')->all(),
        );
    }

    #[Test]
    public function it_searches_the_display_name_too(): void
    {
        $this->assertSame(1, XAccount::search('goodboy ads')->count());
    }

    #[Test]
    public function a_blank_term_does_not_filter(): void
    {
        $this->assertSame(3, XAccount::search(null)->count());
        $this->assertSame(3, XAccount::search('   ')->count());
        $this->assertSame(3, XAccount::search('@')->count());
    }
}
