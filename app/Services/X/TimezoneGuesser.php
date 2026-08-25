<?php

declare(strict_types=1);

namespace App\Services\X;

use App\Models\XAccount;
use DateTimeZone;
use Locale;

/**
 * Works out where an account's audience is, so "post at 09:00" can mean 09:00
 * there rather than 09:00 here.
 *
 * X API v2 carries no timezone on the user object -- only `location`, a free
 * text field the account holder typed. So this is inference, not lookup, and it
 * says how confident it is rather than pretending otherwise. A guess never
 * overwrites a timezone a human set.
 *
 * `source` is what gets stored, and it is deliberately four values rather than
 * a boolean: `location` matched a city and is exact, `country` narrowed it only
 * to a country and may be hours out, `proxy` is where the connection comes from
 * rather than where the audience is, and `manual` a person typed.
 */
final class TimezoneGuesser
{
    /**
     * What people actually write, mapped to the zone they mean.
     *
     * Only entries the IANA city match cannot get on its own: abbreviations,
     * local-language names, and cities whose zone is named after a neighbour.
     */
    private const ALIASES = [
        // Việt Nam
        'ha noi' => 'Asia/Ho_Chi_Minh',
        'hanoi' => 'Asia/Ho_Chi_Minh',
        'sai gon' => 'Asia/Ho_Chi_Minh',
        'saigon' => 'Asia/Ho_Chi_Minh',
        'hcm' => 'Asia/Ho_Chi_Minh',
        'hcmc' => 'Asia/Ho_Chi_Minh',
        'tphcm' => 'Asia/Ho_Chi_Minh',
        'da nang' => 'Asia/Ho_Chi_Minh',
        'viet nam' => 'Asia/Ho_Chi_Minh',
        'vietnam' => 'Asia/Ho_Chi_Minh',

        // US
        'nyc' => 'America/New_York',
        'new york city' => 'America/New_York',
        'brooklyn' => 'America/New_York',
        'manhattan' => 'America/New_York',
        'washington dc' => 'America/New_York',
        'dc' => 'America/New_York',
        'boston' => 'America/New_York',
        'philadelphia' => 'America/New_York',
        'atlanta' => 'America/New_York',
        'miami' => 'America/New_York',
        'sf' => 'America/Los_Angeles',
        'san francisco' => 'America/Los_Angeles',
        'bay area' => 'America/Los_Angeles',
        'silicon valley' => 'America/Los_Angeles',
        'la' => 'America/Los_Angeles',
        'seattle' => 'America/Los_Angeles',
        'austin' => 'America/Chicago',
        'dallas' => 'America/Chicago',
        'houston' => 'America/Chicago',
        'texas' => 'America/Chicago',

        // Rest
        'uk' => 'Europe/London',
        'england' => 'Europe/London',
        'britain' => 'Europe/London',
        'manchester' => 'Europe/London',
        'scotland' => 'Europe/London',
        'deutschland' => 'Europe/Berlin',
        'munich' => 'Europe/Berlin',
        'muenchen' => 'Europe/Berlin',
        'hamburg' => 'Europe/Berlin',
        'sp' => 'America/Sao_Paulo',
        'rio' => 'America/Sao_Paulo',
        'rio de janeiro' => 'America/Sao_Paulo',
        'brasil' => 'America/Sao_Paulo',
        'osaka' => 'Asia/Tokyo',
        'kyoto' => 'Asia/Tokyo',
        'nippon' => 'Asia/Tokyo',
        'japan' => 'Asia/Tokyo',
        'beijing' => 'Asia/Shanghai',
        'shenzhen' => 'Asia/Shanghai',
        'guangzhou' => 'Asia/Shanghai',
        'mumbai' => 'Asia/Kolkata',
        'bombay' => 'Asia/Kolkata',
        'delhi' => 'Asia/Kolkata',
        'new delhi' => 'Asia/Kolkata',
        'bangalore' => 'Asia/Kolkata',
        'bengaluru' => 'Asia/Kolkata',
        'india' => 'Asia/Kolkata',
        'melbourne' => 'Australia/Melbourne',
        'australia' => 'Australia/Sydney',
        'toronto' => 'America/Toronto',
        'vancouver' => 'America/Vancouver',
        'montreal' => 'America/Toronto',
    ];

    /**
     * Aliases that name a region spanning several zones.
     *
     * Perth runs three hours behind Sydney, so "Australia" is a pick, not an
     * answer, and it should not be reported with the same confidence as a city.
     */
    private const AMBIGUOUS_ALIASES = ['australia', 'brasil', 'texas'];

    /**
     * @return array{timezone: string, source: string, confidence: string}|null
     */
    public function guess(XAccount $account): ?array
    {
        $location = trim((string) ($account->location ?? ''));

        /*
         * Narrowest first. "São Paulo, Brasil" names both a city and a country;
         * matching the country alias first got the right zone but reported it
         * as a guess, when the city in the same string was an exact answer.
         */
        if ($location !== '') {
            if ($zone = $this->fromCityName($location)) {
                return ['timezone' => $zone, 'source' => 'location', 'confidence' => 'high'];
            }

            if ($alias = $this->fromAlias($location)) {
                return [
                    'timezone' => $alias['timezone'],
                    // A country-wide alias is no more precise than a country
                    // name, so it is recorded as one.
                    'source' => $alias['confidence'] === 'high' ? 'location' : 'country',
                    'confidence' => $alias['confidence'],
                ];
            }

            if ($guess = $this->fromCountryName($location)) {
                return $guess + ['source' => 'country'];
            }
        }

        /*
         * The proxy is where the account connects from, not necessarily where
         * its audience is -- so it ranks below the profile, but it is a real
         * signal and it is already in the database.
         */
        if ($country = $account->proxy?->country) {
            if ($guess = $this->fromCountryCode($country)) {
                return $guess + ['source' => 'proxy'];
            }
        }

        return null;
    }

    /**
     * @return array{timezone: string, confidence: string}|null
     */
    private function fromAlias(string $location): ?array
    {
        $needle = $this->normalise($location);

        foreach (self::ALIASES as $alias => $zone) {
            if ($this->mentions($needle, $alias)) {
                return [
                    'timezone' => $zone,
                    'confidence' => in_array($alias, self::AMBIGUOUS_ALIASES, true) ? 'low' : 'high',
                ];
            }
        }

        return null;
    }

    /** "Asia/Ho_Chi_Minh" is literally a city name; most profiles write one. */
    private function fromCityName(string $location): ?string
    {
        $needle = $this->normalise($location);

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $city = strrchr($identifier, '/');

            if ($city === false) {
                continue;
            }

            $city = $this->normalise(str_replace('_', ' ', substr($city, 1)));

            // Two-letter zone cities do not exist, but guard anyway: a short
            // needle matches far too much.
            if (strlen($city) >= 4 && $this->mentions($needle, $city)) {
                return $identifier;
            }
        }

        return null;
    }

    /**
     * @return array{timezone: string, confidence: string}|null
     */
    private function fromCountryName(string $location): ?array
    {
        $needle = $this->normalise($location);

        foreach ($this->countryNames() as $iso => $name) {
            if (strlen($name) >= 4 && $this->mentions($needle, $name)) {
                return $this->fromCountryCode($iso);
            }
        }

        return null;
    }

    /**
     * Where most of a multi-zone country's users are.
     *
     * tzdata lists a country's zones alphabetically, which for the US means
     * America/Adak -- an Aleutian island of about three hundred people, four
     * hours off the east coast. Only countries whose alphabetical first is
     * wrong need an entry here; the rest fall through.
     */
    private const PRIMARY_ZONES = [
        'US' => 'America/New_York',
        'CA' => 'America/Toronto',
        'RU' => 'Europe/Moscow',
        'BR' => 'America/Sao_Paulo',
        'AU' => 'Australia/Sydney',
        'MX' => 'America/Mexico_City',
        'ID' => 'Asia/Jakarta',
        'CN' => 'Asia/Shanghai',
        'KZ' => 'Asia/Almaty',
        'AR' => 'America/Argentina/Buenos_Aires',
        'ES' => 'Europe/Madrid',
        'PT' => 'Europe/Lisbon',
        'CL' => 'America/Santiago',
        'EC' => 'America/Guayaquil',
        'UA' => 'Europe/Kyiv',
        'NZ' => 'Pacific/Auckland',
        'CD' => 'Africa/Kinshasa',
        'MN' => 'Asia/Ulaanbaatar',
        'PG' => 'Pacific/Port_Moresby',
        'PS' => 'Asia/Gaza',
        'GL' => 'America/Nuuk',
        'MY' => 'Asia/Kuala_Lumpur',
        'PF' => 'Pacific/Tahiti',
        'KI' => 'Pacific/Tarawa',
        'FM' => 'Pacific/Pohnpei',
    ];

    /**
     * A country's zones, straight from tzdata -- no table to maintain beyond
     * the primaries above.
     *
     * One zone means one answer. Several means we are picking, so it is marked
     * low: the US alone spans six, and a three-hour error is still better than
     * publishing on Hanoi's clock.
     *
     * @return array{timezone: string, confidence: string}|null
     */
    private function fromCountryCode(string $iso): ?array
    {
        $iso = strtoupper($iso);
        $zones = @DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $iso) ?: [];

        if ($zones === []) {
            return null;
        }

        if (count($zones) === 1) {
            return ['timezone' => $zones[0], 'confidence' => 'high'];
        }

        $primary = self::PRIMARY_ZONES[$iso] ?? null;

        return [
            'timezone' => $primary !== null && in_array($primary, $zones, true) ? $primary : $zones[0],
            'confidence' => 'low',
        ];
    }

    /** @return array<string, string> ISO-2 => lowercase English name */
    private function countryNames(): array
    {
        static $names;

        if ($names !== null) {
            return $names;
        }

        $names = [];

        // Every ISO-3166 alpha-2 that tzdata knows about, named through intl.
        foreach (range('A', 'Z') as $first) {
            foreach (range('A', 'Z') as $second) {
                $iso = $first.$second;

                if (@DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $iso)) {
                    $name = Locale::getDisplayRegion('-'.$iso, 'en');

                    if ($name !== '' && $name !== $iso) {
                        $names[$iso] = $this->normalise($name);
                    }
                }
            }
        }

        // Longest first, so "United States Minor Outlying Islands" is tested
        // before "United States" can claim it.
        uasort($names, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $names;
    }

    /** Whole-word containment, so "la" does not match "Atlanta". */
    private function mentions(string $haystack, string $needle): bool
    {
        return (bool) preg_match('/(?<![a-z0-9])'.preg_quote($needle, '/').'(?![a-z0-9])/', $haystack);
    }

    /** Lowercase, accents stripped, punctuation flattened to spaces. */
    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));

        // "Hà Nội" and "Ha Noi" have to reach the same key.
        if (class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
            $value = $transliterator?->transliterate($value) ?? $value;
        }

        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
