<?php

namespace Tests\Unit;

use App\Support\TrustedProxies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TRUSTED_PROXIES parsing: a typo or an over-wide entry must trust less,
 * never more. Pure PHPUnit, no Laravel app.
 */
class TrustedProxiesTest extends TestCase
{
    public static function trustsNobody(): array
    {
        return [
            'unset' => [null],
            'empty' => [''],
            'spaces and commas' => [' , ,'],
            'trust everyone' => ['**'],
            'whole IPv4 internet' => ['0.0.0.0/0'],
            'half the IPv4 internet' => ['128.0.0.0/1'],
            'IPv4 wider than /8' => ['10.0.0.0/7'],
            'whole IPv6 internet' => ['::/0'],
            'IPv6 wider than /16' => ['2a01::/15'],
            'not an address' => ['azure-front-door'],
            'hostname' => ['example.com'],
            'bad prefix' => ['10.0.0.0/33'],
            'non-numeric prefix' => ['10.0.0.0/eight'],
            'lowercase keyword' => ['remote_addr'],
        ];
    }

    #[DataProvider('trustsNobody')]
    public function test_values_that_trust_nobody(?string $value): void
    {
        $this->assertNull(TrustedProxies::parse($value));
    }

    public function test_the_calling_proxy_keyword_and_its_star_alias(): void
    {
        $this->assertSame(['REMOTE_ADDR'], TrustedProxies::parse('REMOTE_ADDR'));
        $this->assertSame(['REMOTE_ADDR'], TrustedProxies::parse('*'));
        $this->assertSame(['REMOTE_ADDR'], TrustedProxies::parse(' * , REMOTE_ADDR '));
    }

    public function test_addresses_and_ranges_are_kept_and_invalid_entries_dropped(): void
    {
        $this->assertSame(
            ['REMOTE_ADDR', '147.243.0.0/16', '2a01:111:2050::/44', '10.1.2.3', '::1'],
            TrustedProxies::parse('REMOTE_ADDR, 147.243.0.0/16,2a01:111:2050::/44, not-an-ip, 10.1.2.3, 0.0.0.0/0, ::1, 147.243.0.0/16')
        );
    }

    public function test_narrowest_allowed_prefixes(): void
    {
        $this->assertSame(['10.0.0.0/8', '10.0.0.1/32', '2a01::/16', '2a01::1/128'], TrustedProxies::parse('10.0.0.0/8,10.0.0.1/32,2a01::/16,2a01::1/128'));
    }
}
