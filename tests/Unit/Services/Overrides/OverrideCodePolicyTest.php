<?php

namespace Tests\Unit\Services\Overrides;

use App\Services\Overrides\OverrideCodePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ADR-016 section 6: an override code is 6 to 8 digits and not one an onlooker would try first. */
class OverrideCodePolicyTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function acceptable(): array
    {
        return ['six' => ['482916'], 'seven' => ['4829160'], 'eight' => ['48291604'], 'leading zero' => ['048291'], 'near a run' => ['123457'], 'pairs' => ['121314']];
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'too short' => ['12345'], 'too long' => ['123456789'], 'letters' => ['12ab56'], 'space' => ['482 916'], 'empty' => [''], 'trailing newline' => ["482916\n"],
            'all zero' => ['000000'], 'all nines' => ['99999999'], 'ascending' => ['123456'], 'ascending 8' => ['12345678'], 'descending' => ['654321'],
            'wrapping run' => ['890123'], 'wrapping down' => ['210987'], 'pair repeated' => ['121212'], 'triple repeated' => ['123123'], 'quad repeated' => ['12341234'],
        ];
    }

    #[DataProvider('acceptable')]
    public function test_a_reasonable_code_is_accepted(string $code): void
    {
        $this->assertNull(OverrideCodePolicy::violation($code));
    }

    #[DataProvider('refused')]
    public function test_a_malformed_or_trivial_code_is_refused_with_words_for_the_person(string $code): void
    {
        $this->assertNotNull(OverrideCodePolicy::violation($code));
    }
}
