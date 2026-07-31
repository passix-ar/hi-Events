<?php

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Exceptions\AccountNotVerifiedException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventStatusDTO;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Jobs\Event\Webhook\DispatchEventWebhookJob;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

readonly class UpdateEventStatusHandler
{
    public function __construct(
        private EventRepositoryInterface   $eventRepository,
        private AccountRepositoryInterface $accountRepository,
        private EventSettingsRepositoryInterface $eventSettingsRepository,
        private AccountMercadopagoPlatformRepositoryInterface $platformRepository,
        private LoggerInterface            $logger,
        private DatabaseManager            $databaseManager,
    )
    {
    }

    /**
     * @throws AccountNotVerifiedException|ResourceConflictException|Throwable
     */
    public function handle(UpdateEventStatusDTO $updateEventStatusDTO): EventDomainObject
    {
        return $this->databaseManager->transaction(function () use ($updateEventStatusDTO) {
            return $this->updateEventStatus($updateEventStatusDTO);
        });
    }

    /**
     * @throws AccountNotVerifiedException|ResourceConflictException
     */
    private function updateEventStatus(UpdateEventStatusDTO $updateEventStatusDTO): EventDomainObject
    {
        $account = $this->accountRepository->findById($updateEventStatusDTO->accountId);

        if ($account->getAccountVerifiedAt() === null) {
            throw new AccountNotVerifiedException(
                __('You must verify your account before you can update an event\'s status.
                You can resend the confirmation by visiting your profile page.'),
            );
        }

        $this->fetchExistingEvent($updateEventStatusDTO);

        if ($updateEventStatusDTO->status === EventStatus::LIVE->name) {
            $this->validateEventCanBePublished($updateEventStatusDTO->eventId, $updateEventStatusDTO->accountId);
        }

        $this->eventRepository->updateWhere(
            attributes: ['status' => $updateEventStatusDTO->status],
            where: [
                'id' => $updateEventStatusDTO->eventId,
                'account_id' => $updateEventStatusDTO->accountId,
            ]
        );

        $this->logger->info('Event status updated', [
            'eventId' => $updateEventStatusDTO->eventId,
            'status' => $updateEventStatusDTO->status
        ]);

        $event = $this->eventRepository->findFirstWhere([
            'id' => $updateEventStatusDTO->eventId,
            'account_id' => $updateEventStatusDTO->accountId,
        ]);

        $eventType = $updateEventStatusDTO->status === EventStatus::ARCHIVED->name
            ? DomainEventType::EVENT_ARCHIVED
            : DomainEventType::EVENT_UPDATED;

        DispatchEventWebhookJob::dispatch(
            $event->getId(),
            $eventType,
        );

        return $event;
    }

    private function fetchExistingEvent(UpdateEventStatusDTO $updateEventStatusDTO): EventDomainObject
    {
        $event = $this->eventRepository->findFirstWhere([
            'id' => $updateEventStatusDTO->eventId,
            'account_id' => $updateEventStatusDTO->accountId,
        ]);

        if ($event === null) {
            throw new ResourceNotFoundException(
                __('Event :id not found', ['id' => $updateEventStatusDTO->eventId])
            );
        }

        return $event;
    }

    /**
     * @throws ResourceConflictException
     */
    private function validateEventCanBePublished(int $eventId, int $accountId): void
    {
        $eventSettings = $this->eventSettingsRepository->findFirstWhere([
            'event_id' => $eventId,
        ]);

        $paymentProviders = $eventSettings?->getPaymentProviders();
        if (!is_array($paymentProviders)) {
            $paymentProviders = [];
        }

        $validProviders = array_filter($paymentProviders, fn($provider) =>
            $provider === PaymentProviders::MERCADOPAGO->value ||
            $provider === PaymentProviders::OFFLINE->value
        );

        if (empty($validProviders)) {
            throw new ResourceConflictException(
                __('Please configure at least one payment method (MercadoPago or Offline) before publishing this event.'),
            );
        }

        $hasOffline = in_array(PaymentProviders::OFFLINE->value, $validProviders, true);
        $hasMercadoPago = in_array(PaymentProviders::MERCADOPAGO->value, $validProviders, true);

        if ($hasMercadoPago && !$hasOffline && !$this->platformRepository->isSetupCompleteForAccount($accountId)) {
            throw new ResourceConflictException(
                __('MercadoPago is not connected. Please connect MercadoPago or enable offline payments before publishing.'),
            );
        }
    }
}
