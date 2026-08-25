<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Proxy;
use App\Models\XAccount;
use App\Services\X\TimezoneGuesser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * X API v2 carries no timezone on the user object, only a free-text `location`.
 * Everything here is therefore inference, and the tests care as much about the
 * confidence reported as the zone chosen.
 */
class TimezoneGuesserTest extends TestCase
{
    private TimezoneGuesser $guesser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guesser = new TimezoneGuesser;
    }

    private function guess(?string $location, ?string $proxyCountry = null): ?array
    {
        $account = new XAccount(['location' => $location]);
        $account->setRelation('proxy', $proxyCountry ? new Proxy(['country' => $proxyCountry]) : null);

        return $this->guesser->guess($account);
    }

    #[Test]
    #[DataProvider('cityLocations')]
    public function it_reads_a_city_out_of_the_profile(string $location, string $expected): void
    {
        $guess = $this->guess($location);

        $this->assertSame($expected, $guess['timezone']);
        $this->assertSame('location', $guess['source']);
        $this->assertSame('high', $guess['confidence']);
    }

    public static function cityLocations(): array
    {
        return [
            'accents' => ['Hà Nội, Việt Nam', 'Asia/Ho_Chi_Minh'],
            'old name' => ['Sài Gòn', 'Asia/Ho_Chi_Minh'],
            'abbreviation' => ['TP.HCM', 'Asia/Ho_Chi_Minh'],
            'initialism' => ['NYC', 'America/New_York'],
            'borough' => ['Brooklyn, NY', 'America/New_York'],
            'city and state' => ['San Francisco, CA', 'America/Los_Angeles'],
            'bare city' => ['London', 'Europe/London'],
            'city and country' => ['Tokyo, Japan', 'Asia/Tokyo'],
            'local spelling' => ['Deutschland', 'Europe/Berlin'],
            'portuguese accents' => ['São Paulo, Brasil', 'America/Sao_Paulo'],
            'renamed city' => ['Bengaluru, India', 'Asia/Kolkata'],
            'iana city' => ['Lagos, Nigeria', 'Africa/Lagos'],
        ];
    }

    #[Test]
    public function a_single_zone_country_is_reported_as_certain(): void
    {
        $guess = $this->guess('Japan');

        $this->assertSame('Asia/Tokyo', $guess['timezone']);
        $this->assertSame('high', $guess['confidence']);
    }

    #[Test]
    public function a_multi_zone_country_picks_the_populous_zone_and_says_it_is_unsure(): void
    {
        // tzdata lists US zones alphabetically, which starts at America/Adak --
        // an Aleutian island of a few hundred people, four hours off New York.
        $guess = $this->guess('United States');

        $this->assertSame('America/New_York', $guess['timezone']);
        $this->assertSame('low', $guess['confidence']);
        // Recorded as country-level so the UI can flag it as approximate.
        $this->assertSame('country', $guess['source']);
    }

    #[Test]
    public function a_country_wide_alias_is_not_reported_as_certain(): void
    {
        // Perth is three hours behind Sydney.
        $guess = $this->guess('Australia');

        $this->assertSame('Australia/Sydney', $guess['timezone']);
        $this->assertSame('low', $guess['confidence']);
        $this->assertSame('country', $guess['source']);
    }

    #[Test]
    public function it_falls_back_to_the_proxy_country(): void
    {
        $guess = $this->guess(null, 'VN');

        $this->assertSame('Asia/Ho_Chi_Minh', $guess['timezone']);
        $this->assertSame('proxy', $guess['source']);
    }

    #[Test]
    public function the_profile_outranks_the_proxy(): void
    {
        // Where the audience is beats where the connection comes from.
        $guess = $this->guess('Tokyo', 'VN');

        $this->assertSame('Asia/Tokyo', $guess['timezone']);
        $this->assertSame('location', $guess['source']);
    }

    #[Test]
    #[DataProvider('unguessableLocations')]
    public function it_gives_up_rather_than_inventing_one(?string $location): void
    {
        // An empty timezone is visible in the UI; a wrong one is not.
        $this->assertNull($this->guess($location));
    }

    public static function unguessableLocations(): array
    {
        return [
            'nothing' => [null],
            'blank' => ['   '],
            'a joke' => ['somewhere over the rainbow'],
            'emoji' => ['🌍'],
            'a url' => ['https://example.com'],
        ];
    }

    #[Test]
    public function a_short_word_does_not_match_inside_a_longer_one(): void
    {
        // "LA" must not fire on "Atlanta" or "Lagos".
        $this->assertSame('America/New_York', $this->guess('Atlanta')['timezone']);
        $this->assertSame('Africa/Lagos', $this->guess('Lagos')['timezone']);
    }
}
