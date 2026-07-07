<?php

namespace HiEvents\Http\Request\Auth;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Validators\Rules\RulesHelper;
use HiEvents\Validators\Rules\ValidTimezoneRule;

class AcceptInvitationRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'first_name' => RulesHelper::REQUIRED_STRING,
            'last_name' => RulesHelper::STRING,
            'password' => 'required|string|min:8|confirmed',
            'timezone' => ['required', new ValidTimezoneRule()],
        ];
    }
}
