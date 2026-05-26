<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Http\Actions\Orders\Payment\MercadoPago;

use HiEvents\Exceptions\CannotAcceptPaymentException;
use HiEvents\Exceptions\MercadoPago\CreateMercadoPagoPreferenceFailedException;
use HiEvents\Exceptions\MercadoPago\MercadoPagoClientConfigurationException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Order\Payment\MercadoPago\CreateMercadoPagoPreferenceHandler;
use HiEvents\Services\Application\Handlers\Order\Payment\MercadoPago\DTO\CreateMercadoPagoPreferenceDTO;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CreateMercadoPagoPreferenceActionPublic extends BaseAction
{
    public function __construct(
        private readonly CreateMercadoPagoPreferenceHandler $handler,
    ) {
    }

    public function __invoke(int $event_id, string $order_short_id): JsonResponse
    {
        try {
            $result = $this->handler->handle(new CreateMercadoPagoPreferenceDTO(
                eventId: $event_id,
                orderShortId: $order_short_id,
            ));
        } catch (MercadoPagoClientConfigurationException | CannotAcceptPaymentException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (CreateMercadoPagoPreferenceFailedException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->jsonResponse([
            'preference_id'      => $result->preferenceId,
            'init_point'         => $result->initPoint,
            'sandbox_init_point' => $result->sandboxInitPoint,
            'is_sandbox'         => $result->isSandbox,
        ]);
    }
}
