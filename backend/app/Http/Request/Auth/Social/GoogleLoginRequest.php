<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Auth\Social;

use HiEvents\Http\Request\BaseRequest;

class GoogleLoginRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            // Length is bounded so an oversized body is rejected before any crypto work.
            // The nonce is not accepted here: it is read from the signed token instead,
            // so the client cannot choose which value gets checked.
            'id_token' => ['required', 'string', 'max:4096'],
            'account_id' => ['nullable', 'integer'],
        ];
    }
}
