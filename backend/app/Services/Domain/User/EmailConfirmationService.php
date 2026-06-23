<?php

namespace HiEvents\Services\Domain\User;

use Carbon\Carbon;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Mail\Account\ConfirmEmailAddressEmail;
use HiEvents\Mail\Account\EmailConfirmationCodeEmail;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Infrastructure\Encryption\EncryptedPayloadService;
use HiEvents\Services\Infrastructure\Encryption\Exception\DecryptionFailedException;
use HiEvents\Services\Infrastructure\Encryption\Exception\EncryptedPayloadExpiredException;
use HiEvents\Services\Infrastructure\User\EmailVerificationCodeService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

class EmailConfirmationService
{
    public function __construct(
        private readonly Mailer                          $mailer,
        private readonly EncryptedPayloadService         $encryptedPayloadService,
        private readonly UserRepositoryInterface         $userRepository,
        private readonly DatabaseManager                 $databaseManager,
        private readonly EmailVerificationCodeService    $emailVerificationCodeService,
        private readonly VerifyUserEmailService          $verifyUserEmailService,
        private readonly EventRepositoryInterface        $eventRepository,
        private readonly AccountUserRepositoryInterface  $accountUserRepository,
    )
    {
    }

    /**
     * @throws DecryptionFailedException
     * @throws EncryptedPayloadExpiredException|Throwable
     */
    public function confirmEmailAddress(string $token, int $accountId): void
    {
        $this->databaseManager->transaction(function () use ($accountId, $token) {
            ['id' => $userId] = $this->encryptedPayloadService->decryptPayload($token);

            $user = $this->userRepository->findByIdAndAccountId($userId, $accountId);

            $this->verifyUserEmailService->markEmailAsVerified($user, $accountId);
        });
    }

    /**
     * Confirm an email address using only the signed token, with no authenticated
     * session required. The encrypted token (unforgeable without APP_KEY) is the
     * proof of ownership, so the confirmation link works from any device/browser.
     *
     * @throws DecryptionFailedException
     * @throws EncryptedPayloadExpiredException|Throwable
     */
    public function confirmEmailAddressFromToken(string $token): void
    {
        $this->databaseManager->transaction(function () use ($token) {
            $payload = $this->encryptedPayloadService->decryptPayload($token);

            $userId = $payload['id'];
            // Older tokens only carried the user id; fall back to the user's owner account.
            $accountId = $payload['account_id'] ?? $this->resolveOwnerAccountId($userId);

            // Throws ResourceNotFoundException if the user is not part of the account.
            $user = $this->userRepository->findByIdAndAccountId($userId, $accountId);

            $this->verifyUserEmailService->markEmailAsVerified($user, $accountId);
        });
    }

    private function resolveOwnerAccountId(int $userId): int
    {
        $accountUser = $this->accountUserRepository->findFirstWhere([
            'user_id' => $userId,
            'is_account_owner' => true,
        ]);

        if ($accountUser === null) {
            throw new ResourceNotFoundException();
        }

        return $accountUser->getAccountId();
    }

    public function sendConfirmation(UserDomainObject $user, int $accountId): void
    {
        // If there are no events, we assume the user is registering for the first time
        $events = $this->eventRepository->findWhere([
            'account_id' => $accountId,
        ]);

        if (config('app.enforce_email_confirmation_during_registration') && $events->isEmpty()) {
            $this->mailer
                ->to($user->getEmail())
                ->locale($user->getLocale())
                ->send(new EmailConfirmationCodeEmail(
                    $user,
                    $this->emailVerificationCodeService->storeAndReturnCode($user->getEmail()),
                ));

            return;
        }

        $token = $this->encryptedPayloadService->encryptPayload([
            'id' => $user->getId(),
            'account_id' => $accountId,
        ], Carbon::now()->addHours(48));

        $this->mailer
            ->to($user->getEmail())
            ->send(new ConfirmEmailAddressEmail($user, $token));
    }
}
