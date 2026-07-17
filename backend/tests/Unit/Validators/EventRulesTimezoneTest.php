<?php

namespace Tests\Unit\Validators;

use HiEvents\Validators\EventRules;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class EventRulesTimezoneTest extends TestCase
{
    /**
     * @return array the `timezone` validation rule produced by EventRules::eventRules()
     */
    private function timezoneRule(): array
    {
        $rulesProvider = new class {
            use EventRules;

            public function input($key = null, $default = null)
            {
                return $default;
            }
        };

        return ['timezone' => $rulesProvider->eventRules()['timezone']];
    }

    private function passes(?string $timezone): bool
    {
        return Validator::make(['timezone' => $timezone], $this->timezoneRule())->passes();
    }

    public function testEventRulesAcceptLegacyTimezoneAlias(): void
    {
        // Regression: account.timezone can hold a backward-compatibility alias
        // (e.g. "America/Cordoba") that the old `timezone:all` rule rejected on
        // event creation. It must be accepted, like every other timezone field.
        $this->assertTrue($this->passes('America/Cordoba'));
        $this->assertTrue($this->passes('America/Buenos_Aires'));
    }

    public function testEventRulesAcceptCanonicalTimezone(): void
    {
        $this->assertTrue($this->passes('America/Argentina/Cordoba'));
        $this->assertTrue($this->passes('UTC'));
    }

    public function testEventRulesRejectInvalidTimezone(): void
    {
        $this->assertFalse($this->passes('not-a-timezone'));
    }
}
