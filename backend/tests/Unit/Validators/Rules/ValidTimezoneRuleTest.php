<?php

namespace Tests\Unit\Validators\Rules;

use HiEvents\Validators\Rules\ValidTimezoneRule;
use Tests\TestCase;

class ValidTimezoneRuleTest extends TestCase
{
    private ValidTimezoneRule $rule;
    private array $failedMessages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new ValidTimezoneRule();
        $this->failedMessages = [];
    }

    private function validate(mixed $value): bool
    {
        $this->failedMessages = [];
        $failed = false;

        $this->rule->validate('timezone', $value, function ($message) use (&$failed) {
            $failed = true;
            $this->failedMessages[] = $message;
        });

        return !$failed;
    }

    public function testAcceptsCanonicalIanaTimezones(): void
    {
        $this->assertTrue($this->validate('UTC'));
        $this->assertTrue($this->validate('America/Argentina/Buenos_Aires'));
        $this->assertTrue($this->validate('America/Argentina/Cordoba'));
        $this->assertTrue($this->validate('Europe/London'));
    }

    public function testAcceptsLegacyBackwardCompatibleAliases(): void
    {
        // These are excluded from DateTimeZone::listIdentifiers() (which the old
        // `timezone:all` rule relied on) but are still valid, constructible timezones
        // that browsers can report via Intl.DateTimeFormat().resolvedOptions().timeZone.
        $this->assertTrue($this->validate('America/Cordoba'));
        $this->assertTrue($this->validate('America/Buenos_Aires'));
    }

    public function testRejectsInvalidTimezoneStrings(): void
    {
        $this->assertFalse($this->validate('not-a-timezone'));
        $this->assertFalse($this->validate('Argentina/Buenos_Aires'));
    }

    public function testTreatsEmptyOrNonStringValuesAsNoOp(): void
    {
        $this->assertTrue($this->validate(''));
        $this->assertTrue($this->validate(null));
    }
}
