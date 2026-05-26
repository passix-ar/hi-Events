<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Services\Application\Handlers\Order\Payment\MercadoPago;

use HiEvents\DomainObjects\AccountMercadopagoPlatformDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\MercadopagoPreferenceDomainObjectAbstract;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\CannotAcceptPaymentException;
use HiEvents\Exceptions\MercadoPago\CreateMercadoPagoPreferenceFailedException;
use HiEvents\Exceptions\MercadoPago\MercadoPagoClientConfigurationException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\Repository\Interfaces\MercadopagoPreferenceRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\MercadoPago\DTO\CreateMercadoPagoPreferenceDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\MercadoPago\DTO\CreateMercadoPagoPreferenceResponseDTO;
use HiEvents\Services\Domain\Payment\MercadoPago\MercadoPagoPreferenceService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Illuminate\Config\Repository as Config;
use Illuminate\Routing\UrlGenerator;

class CreateMercadoPagoPreferenceHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface                      $orderRepository,
        private readonly AccountMercadopagoPlatformRepositoryInterface $platformRepository,
        private readonly MercadopagoPreferenceRepositoryInterface      $preferenceRepository,
        private readonly MercadoPagoPreferenceService                  $preferenceService,
        private readonly CheckoutSessionManagementService              $sessionService,
        private readonly Config                                        $config,
        private readonly UrlGenerator                                  $urlGenerator,
    ) {
    }

    /**
     * @throws UnauthorizedException
     * @throws ResourceConflictException
     * @throws CannotAcceptPaymentException
     * @throws MercadoPagoClientConfigurationException
     * @throws CreateMercadoPagoPreferenceFailedException
     */
    public function handle(CreateMercadoPagoPreferenceDTO $command): CreateMercadoPagoPreferenceResponseDTO
    {
        $order = $this->orderRepository
            ->loadRelation(new Relationship(OrderItemDomainObject::class))
            ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
            ->findByShortId($command->orderShortId);

        if (!$order || !$this->sessionService->verifySession($order->getSessionId())) {
            throw new UnauthorizedException(__('Sorry, we could not verify your session. Please create a new order.'));
        }

        if ($order->getStatus() !== OrderStatus::RESERVED->name || $order->isReservedOrderExpired()) {
            throw new ResourceConflictException(__('Sorry, this order has expired or is not in a valid state.'));
        }

        $event = $order->getEvent();
        if (!$event) {
            throw new CannotAcceptPaymentException(__('Event not found for this order.'));
        }

        $platform = $this->platformRepository->findFirstWhere([
            'account_id' => $event->getAccountId(),
        ]);

        if (!$platform || !$platform->isSetupComplete()) {
            throw new MercadoPagoClientConfigurationException(
                __('MercadoPago is not connected for this organizer. Please contact the event organizer.')
            );
        }

        $existing = $this->preferenceRepository->findFirstWhere([
            MercadopagoPreferenceDomainObjectAbstract::ORDER_ID => $order->getId(),
        ]);

        if ($existing) {
            $isSandbox = (bool) $this->config->get('mercadopago.sandbox', true);
            return new CreateMercadoPagoPreferenceResponseDTO(
                preferenceId: $existing->getPreferenceId(),
                initPoint: $existing->getInitPoint() ?? '',
                sandboxInitPoint: $existing->getSandboxInitPoint() ?? '',
                isSandbox: $isSandbox,
            );
        }

        $baseUrl = $this->config->get('app.frontend_url', config('app.url'));
        $successUrl = $baseUrl . "/checkout/{$command->eventId}/{$command->orderShortId}/summary?payment=approved";
        $failureUrl = $baseUrl . "/checkout/{$command->eventId}/{$command->orderShortId}/payment?payment=rejected";
        $pendingUrl  = $baseUrl . "/checkout/{$command->eventId}/{$command->orderShortId}/summary?payment=pending";
        $webhookUrl  = $this->urlGenerator->route('webhooks.mercadopago');

        $preference = $this->preferenceService->createPreference(
            order: $order,
            platform: $platform,
            successUrl: $successUrl,
            failureUrl: $failureUrl,
            pendingUrl: $pendingUrl,
            webhookUrl: $webhookUrl,
        );

        $this->preferenceRepository->create([
            MercadopagoPreferenceDomainObjectAbstract::ORDER_ID          => $order->getId(),
            MercadopagoPreferenceDomainObjectAbstract::PREFERENCE_ID     => $preference->id,
            MercadopagoPreferenceDomainObjectAbstract::INIT_POINT        => $preference->init_point,
            MercadopagoPreferenceDomainObjectAbstract::SANDBOX_INIT_POINT => $preference->sandbox_init_point,
            MercadopagoPreferenceDomainObjectAbstract::STATUS            => 'pending',
        ]);

        $isSandbox = (bool) $this->config->get('mercadopago.sandbox', true);

        return new CreateMercadoPagoPreferenceResponseDTO(
            preferenceId: $preference->id,
            initPoint: $preference->init_point ?? '',
            sandboxInitPoint: $preference->sandbox_init_point ?? '',
            isSandbox: $isSandbox,
        );
    }
}
