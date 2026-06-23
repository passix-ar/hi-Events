<?php

namespace HiEvents\Services\Application\Handlers\User;

use HiEvents\Services\Application\Handlers\User\DTO\ConfirmEmailChangeDTO;
use HiEvents\Services\Domain\User\EmailConfirmationService;
use HiEvents\Services\Infrastructure\Encryption\Exception\DecryptionFailedException;
use Throwable;

readonly class ConfirmEmailAddressHandler
{
    public function __construct(
        private EmailConfirmationService $emailConfirmationService,
    )
    {
    }

    /**
     * @throws DecryptionFailedException|Throwable
     */
    public function handle(ConfirmEmailChangeDTO $data): void
    {
        $this->emailConfirmationService->confirmEmailAddress($data->token, $data->accountId);
    }

    /**
     * Session-less confirmation: the signed token carries the identity, so no
     * authenticated account is required.
     *
     * @throws DecryptionFailedException|Throwable
     */
    public function handleFromToken(string $token): void
    {
        $this->emailConfirmationService->confirmEmailAddressFromToken($token);
    }
}
