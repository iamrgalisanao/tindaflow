<?php

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\RoleCapabilityCatalog;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * A2 requirement 18: mechanically compares the code-level capability
 * catalog against the actual frozen docs/05-api/openapi.yaml -- parsed
 * directly, never a second hard-coded copy of either list, so this test
 * fails the moment the two sources drift apart.
 */
class CapabilityOpenApiTraceabilityTest extends TestCase
{
    /** @return array<string, mixed> */
    private function openApi(): array
    {
        return Yaml::parseFile(base_path('docs/05-api/openapi.yaml'));
    }

    public function test_openapi_capability_enum_equals_the_code_level_catalog(): void
    {
        $enum = $this->openApi()['components']['schemas']['Capability']['enum'];

        $sortedEnum = $enum;
        sort($sortedEnum);
        $sortedCatalog = RoleCapabilityCatalog::CAPABILITIES;
        sort($sortedCatalog);

        $this->assertSame($sortedEnum, $sortedCatalog, 'openapi.yaml Capability enum and RoleCapabilityCatalog::CAPABILITIES must be identical sets');
    }

    public function test_openapi_enum_has_no_duplicates(): void
    {
        $enum = $this->openApi()['components']['schemas']['Capability']['enum'];

        $this->assertCount(count($enum), array_unique($enum), 'openapi.yaml Capability enum contains a duplicate');
    }

    /** @return list<string> every x-capability value found anywhere in the document */
    private function everyXCapabilityValue(): array
    {
        $found = [];
        $walk = function ($node) use (&$walk, &$found): void {
            if (! is_array($node)) {
                return;
            }
            if (array_key_exists('x-capability', $node)) {
                $found[] = $node['x-capability'];
            }
            foreach ($node as $value) {
                $walk($value);
            }
        };
        $walk($this->openApi()['paths']);

        return $found;
    }

    public function test_every_x_capability_value_is_a_member_of_the_capability_enum(): void
    {
        $enum = $this->openApi()['components']['schemas']['Capability']['enum'];

        foreach ($this->everyXCapabilityValue() as $xCapability) {
            $this->assertContains($xCapability, $enum, "x-capability '$xCapability' is not in the Capability enum");
        }
    }

    public function test_every_x_capability_value_is_recognized_by_the_code_level_catalog(): void
    {
        foreach ($this->everyXCapabilityValue() as $xCapability) {
            $this->assertContains($xCapability, RoleCapabilityCatalog::CAPABILITIES, "x-capability '$xCapability' has no entry in RoleCapabilityCatalog::CAPABILITIES");
        }
    }

    // The three explicitly conditional capabilities are never used as a
    // whole-operation x-capability anywhere in the contract -- confirms
    // A2 was right not to blanket-gate any endpoint with them.
    public function test_conditional_capabilities_never_appear_as_a_whole_operation_x_capability(): void
    {
        $xCapabilities = $this->everyXCapabilityValue();

        foreach (['PRICE_OVERRIDE', 'DISCOUNT_OVERRIDE', 'CASH_OUT'] as $conditional) {
            $this->assertNotContains($conditional, $xCapabilities, "$conditional must remain a conditional, field-level check, never a whole-operation x-capability");
        }
    }
}
