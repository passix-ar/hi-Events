<?php

namespace Tests\Unit\Validators\Rules;

use HiEvents\Validators\Rules\SafeEmailRule;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeEmailRuleTest extends TestCase
{
    public static function headerInjectionPayloads(): array
    {
        return [
            'CRLF en local-part entrecomillado' => ["\"x\r\nBcc: attacker@evil\"@example.com"],
            'solo LF' => ["\"x\nBcc: attacker@evil\"@example.com"],
            'solo CR' => ["\"x\rBcc: attacker@evil\"@example.com"],
            'NUL byte' => ["victim@example.com\x00"],
            'tab' => ["\"x\tBcc: attacker@evil\"@example.com"],
            'DEL' => ["victim@example.com\x7F"],
        ];
    }

    #[DataProvider('headerInjectionPayloads')]
    public function test_rejects_control_characters(string $payload): void
    {
        $this->assertFalse(SafeEmailRule::isSafe($payload));
    }

    public function test_allows_ordinary_addresses(): void
    {
        foreach (['ana@passix.com', 'a.b+tag@sub.example.co.uk', 'ñoño@ejemplo.com'] as $email) {
            $this->assertTrue(SafeEmailRule::isSafe($email), $email);
        }
    }

    public function test_ignores_non_strings(): void
    {
        $this->assertTrue(SafeEmailRule::isSafe(null));
        $this->assertTrue(SafeEmailRule::isSafe(123));
    }

    public function test_is_registered_as_string_rule_and_rejects_payload(): void
    {
        $validator = Validator::make(
            ['email' => "\"x\r\nBcc: attacker@evil\"@example.com"],
            ['email' => ['required', 'email', SafeEmailRule::NAME]],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_string_rule_accepts_ordinary_address(): void
    {
        $validator = Validator::make(
            ['email' => 'ana@passix.com'],
            ['email' => ['required', 'email', SafeEmailRule::NAME]],
        );

        $this->assertFalse($validator->fails());
    }
}
