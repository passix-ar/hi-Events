<?php

namespace HiEvents\Validators\Rules;

use Closure;
use DateTimeZone;
use Exception;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidTimezoneRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        try {
            new DateTimeZone($value);
        } catch (Exception) {
            $fail(__('validation.timezone'));
        }
    }
}
