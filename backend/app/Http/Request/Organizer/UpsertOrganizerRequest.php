<?php

namespace HiEvents\Http\Request\Organizer;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Validators\Rules\ValidTimezoneRule;
use Illuminate\Validation\Rule;

class UpsertOrganizerRequest extends BaseRequest
{
    public function rules(): array
    {
        $currencies = include __DIR__ . '/../../../../data/currencies.php';

        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['email', 'safe_email', 'required'],
            'phone' => ['string', "nullable", 'max:25'],
            'website' => ['url', 'nullable', 'max:255'],
            'description' => ['string', 'nullable', 'max:1200'],
            'timezone' => ['required', new ValidTimezoneRule()],
            'currency' => ['required', Rule::in(array_values($currencies))],
        ];
    }
}
