<?php

namespace HiEvents\Http\Actions\Auth;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\User\ConfirmEmailAddressHandler;
use HiEvents\Services\Infrastructure\Encryption\Exception\DecryptionFailedException;
use HiEvents\Services\Infrastructure\Encryption\Exception\EncryptedPayloadExpiredException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

/**
 * Public, session-less email confirmation. The signed token sent to the user's
 * inbox is the proof of ownership, so the confirmation link works regardless of
 * whether the user is logged in or which device/browser they open it on.
 */
class ConfirmEmailAddressPublicAction extends BaseAction
{
    public function __construct(
        private readonly ConfirmEmailAddressHandler $confirmEmailAddressHandler,
    )
    {
    }

    /**
     * @throws Throwable
     */
    public function __invoke(string $token): Response|JsonResponse
    {
        try {
            $this->confirmEmailAddressHandler->handleFromToken($token);
        } catch (EncryptedPayloadExpiredException) {
            return $this->errorResponse(__('The email confirmation link has expired. Please request a new one.'));
        } catch (DecryptionFailedException | ResourceNotFoundException) {
            return $this->errorResponse(__('The email confirmation link is invalid.'));
        }

        return $this->noContentResponse();
    }
}
