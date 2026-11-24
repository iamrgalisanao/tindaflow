<?php

namespace Tests\Unit\Services\License;

use App\Services\License\FingerprintMatcher;
use PHPUnit\Framework\TestCase;

/** ADR-018 decision 4: the host is accepted when at least 2 of the 3 hardware hashes match the registration. */
class FingerprintMatcherTest extends TestCase
{
    /** @return array<string, string> */
    private static function registered(): array
    {
        return ['smbios_uuid' => str_repeat('a', 64), 'board_serial' => str_repeat('b', 64), 'bios_serial' => str_repeat('c', 64)];
    }

    public function test_it_counts_how_many_components_match(): void
    {
        $matcher = new FingerprintMatcher;
        $registered = self::registered();

        $this->assertSame(3, $matcher->matchedCount($registered, $registered));
        $this->assertSame(2, $matcher->matchedCount($registered, ['bios_serial' => str_repeat('x', 64)] + $registered));
        $this->assertSame(1, $matcher->matchedCount($registered, ['board_serial' => str_repeat('x', 64), 'bios_serial' => str_repeat('y', 64)] + $registered));
        $this->assertSame(0, $matcher->matchedCount($registered, ['smbios_uuid' => 'x', 'board_serial' => 'y', 'bios_serial' => 'z']));
    }

    public function test_a_missing_component_is_not_a_match(): void
    {
        $observed = self::registered();
        unset($observed['board_serial']);

        $this->assertSame(2, (new FingerprintMatcher)->matchedCount(self::registered(), $observed));
    }

    public function test_the_order_of_the_components_does_not_matter(): void
    {
        $registered = self::registered();

        $this->assertSame(3, (new FingerprintMatcher)->matchedCount($registered, array_reverse($registered, true)));
    }

    public function test_two_of_three_is_acceptable_and_one_is_not(): void
    {
        $matcher = new FingerprintMatcher;

        $this->assertTrue($matcher->isAcceptable(3));
        $this->assertTrue($matcher->isAcceptable(2));
        $this->assertFalse($matcher->isAcceptable(1));
        $this->assertFalse($matcher->isAcceptable(0));
    }
}
