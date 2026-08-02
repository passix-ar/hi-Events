<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Generated\AccountMercadopagoPlatformDomainObjectAbstract;
use HiEvents\Exceptions\MercadoPago\MercadoPagoAccountAlreadyLinkedException;
use HiEvents\Exceptions\MercadoPago\MercadoPagoOAuthException;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Domain\Payment\MercadoPago\MercadoPagoOAuthService;
use Psr\Log\LoggerInterface;
use Throwable;

class MercadoPagoOAuthCallbackHandler
{
    public function __construct(
        private readonly MercadoPagoOAuthService                      $oauthService,
        private readonly AccountMercadopagoPlatformRepositoryInterface $platformRepository,
        private readonly EventRepositoryInterface                      $eventRepository,
        private readonly EventSettingsRepositoryInterface              $eventSettingsRepository,
        private readonly LoggerInterface                               $logger,
    ) {
    }

    /**
     * @throws MercadoPagoOAuthException
     * @throws Throwable
     */
    public function handle(string $code, string $state): void
    {
        $accountId = $this->oauthService->decodeState($state);
        $tokenData = $this->oauthService->exchangeCodeForToken($code);

        $this->logger->info('MercadoPago OAuth callback received', [
            'account_id'  => $accountId,
            'mp_user_id'  => $tokenData['user_id'] ?? null,
        ]);

        $mpUserId = (string) ($tokenData['user_id'] ?? '');

        // One MercadoPago seller = one Passix account. The DB enforces this with a
        // unique index on mp_user_id (which also covers soft-deleted rows in Postgres).
        // If this seller is already linked to a *different* account, reject with a
        // clear message instead of crashing on the constraint or hijacking it.
        // Select only the columns we need for these checks: hydrating the full row
        // would decrypt the token casts, so a single corrupted row would make even
        // this lookup blow up.
        if ($mpUserId !== '') {
            $sellerRow = $this->platformRepository
                ->includeDeleted()
                ->findFirstWhere(
                    [AccountMercadopagoPlatformDomainObjectAbstract::MP_USER_ID => $mpUserId],
                    [
                        AccountMercadopagoPlatformDomainObjectAbstract::ID,
                        AccountMercadopagoPlatformDomainObjectAbstract::ACCOUNT_ID,
                        AccountMercadopagoPlatformDomainObjectAbstract::MP_USER_ID,
                    ],
                );

            if ($sellerRow && $sellerRow->getAccountId() !== $accountId) {
                throw new MercadoPagoAccountAlreadyLinkedException(
                    __('This MercadoPago account is already connected to another Passix account.')
                );
            }
        }

        // The account's own connection, if it reconnects (active or soft-deleted).
        $existing = $this->platformRepository
            ->includeDeleted()
            ->findFirstWhere(
                [AccountMercadopagoPlatformDomainObjectAbstract::ACCOUNT_ID => $accountId],
                [
                    AccountMercadopagoPlatformDomainObjectAbstract::ID,
                    AccountMercadopagoPlatformDomainObjectAbstract::ACCOUNT_ID,
                ],
            );

        $expiresAt = isset($tokenData['expires_in'])
            ? Carbon::now()->addSeconds($tokenData['expires_in'])->toDateTimeString()
            : null;

        $attributes = [
            AccountMercadopagoPlatformDomainObjectAbstract::ACCOUNT_ID        => $accountId,
            AccountMercadopagoPlatformDomainObjectAbstract::MP_USER_ID        => $mpUserId,
            AccountMercadopagoPlatformDomainObjectAbstract::ACCESS_TOKEN      => $tokenData['access_token'] ?? null,
            AccountMercadopagoPlatformDomainObjectAbstract::REFRESH_TOKEN     => $tokenData['refresh_token'] ?? null,
            AccountMercadopagoPlatformDomainObjectAbstract::PUBLIC_KEY        => $tokenData['public_key'] ?? null,
            AccountMercadopagoPlatformDomainObjectAbstract::TOKEN_EXPIRES_AT  => $expiresAt,
            AccountMercadopagoPlatformDomainObjectAbstract::SETUP_COMPLETED_AT => now()->toDateTimeString(),
        ];

        if ($existing) {
            // updateFromArray fills + saves the model, so the `encrypted` casts run and
            // the tokens are stored encrypted. (A raw builder update like updateWhere
            // bypasses the casts and would persist the tokens in plaintext, which then
            // fails to decrypt on read.)
            $this->platformRepository->includeDeleted()->updateFromArray($existing->getId(), $attributes);
        } else {
            $this->platformRepository->create($attributes);
        }

        // Only on the account's first connection: a re-auth while already connected
        // must not re-tick MercadoPago on events where the organizer disabled it.
        // (Disconnect hard-deletes the row, so disconnect + reconnect counts as first.)
        if ($existing === null) {
            try {
                $this->enableMercadoPagoOnAllEvents($accountId);
            } catch (Throwable $e) {
                $this->logger->warning('Failed to auto-enable MercadoPago on events', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function enableMercadoPagoOnAllEvents(int $accountId): void
    {
        $events = $this->eventRepository->findWhere(['account_id' => $accountId]);

        foreach ($events as $event) {
            $settings = $this->eventSettingsRepository->findFirstWhere([
                'event_id' => $event->getId(),
            ]);

            if (!$settings) {
                continue;
            }

            $providers = $settings->getPaymentProviders();
            if (!is_array($providers)) {
                $providers = [];
            }

            if (!in_array(PaymentProviders::MERCADOPAGO->value, $providers, true)) {
                $providers[] = PaymentProviders::MERCADOPAGO->value;
                $this->eventSettingsRepository->updateWhere(
                    attributes: ['payment_providers' => $providers],
                    where: ['event_id' => $event->getId()],
                );
            }
        }

        $this->logger->info('MercadoPago enabled on all events for account', ['account_id' => $accountId]);
    }
}
