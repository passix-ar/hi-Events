<?php

namespace HiEvents\Resources\Auth;

use HiEvents\Services\Application\Handlers\Auth\Social\DTO\SocialAuthResultDTO;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SocialAuthResultDTO
 */
class SocialRegistrationRequiredResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'registration_required' => true,
            'registration_token' => $this->registrationToken,
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
        ];
    }
}
