<?php

// Added by Passix on 2026-08-07: defence in depth against email header injection.
declare(strict_types=1);

namespace HiEvents\Validators\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects control characters in email addresses.
 *
 * Laravel's `email` rule accepts RFC-5322 quoted local-parts that may carry raw
 * CRLF (e.g. "x\r\nBcc: attacker@evil"@example.com). Those addresses reach the
 * mailer, which writes them verbatim into message headers and SMTP commands,
 * turning the line break into an injected header or protocol command.
 *
 * Always pair this with `email`: this rule only screens characters, it does not
 * validate address format. Registered as the string rule `safe_email` in
 * AppServiceProvider so it can be used inside constant rule arrays too.
 */
class SafeEmailRule implements ValidationRule
{
    public const NAME = 'safe_email';

    /**
     * C0 controls plus DEL. No legitimate email address contains these.
     */
    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/';

    public static function isSafe(mixed $value): bool
    {
        if (! is_string($value)) {
            return true;
        }

        return ! preg_match(self::CONTROL_CHARACTERS, $value);
    }

    public static function message(): string
    {
        return __('The :attribute contains invalid characters.');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isSafe($value)) {
            $fail(self::message());
        }
    }
}
