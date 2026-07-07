<?php

namespace HiEvents\Http\Request\Account;

use HiEvents\Validators\Rules\ValidTimezoneRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function rules(): array
    {
        $currencies = include __DIR__ . '/../../../../data/currencies.php';

        return [
            'name' => 'required|string',
            'timezone' => ['required', new ValidTimezoneRule()],
            'currency_code' => [Rule::in(array_values($currencies))],
        ];
    }
}
