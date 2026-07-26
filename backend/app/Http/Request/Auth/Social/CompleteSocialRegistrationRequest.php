<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Auth\Social;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Locale;
use HiEvents\Validators\Rules\RulesHelper;
use HiEvents\Validators\Rules\ValidTimezoneRule;
use Illuminate\Validation\Rule;

class CompleteSocialRegistrationRequest extends BaseRequest
{
    public function rules(): array
    {
        $currencies = include __DIR__ . '/../../../../../data/currencies.php';

        return [
            'registration_token' => ['required', 'string'],
            'business_name' => RulesHelper::REQUIRED_STRING,
            'timezone' => ['nullable', new ValidTimezoneRule()],
            'currency_code' => [Rule::in(array_values($currencies))],
            'locale' => ['nullable', Rule::in(Locale::getSupportedLocales())],
            'marketing_opt_in' => 'boolean|nullable',
            // UTM attribution fields
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'referrer_url' => ['nullable', 'string', 'max:2048'],
            'landing_page' => ['nullable', 'string', 'max:2048'],
            'gclid' => ['nullable', 'string', 'max:255'],
            'fbclid' => ['nullable', 'string', 'max:255'],
            'utm_raw' => ['nullable', 'array'],
        ];
    }
}
