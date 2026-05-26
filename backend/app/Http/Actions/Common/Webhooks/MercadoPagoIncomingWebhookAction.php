<?php

namespace HiEvents\Http\Actions\Common\Webhooks;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Order\Payment\MercadoPago\DTO\MercadoPagoWebhookDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\MercadoPago\IncomingWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class MercadoPagoIncomingWebhookAction extends BaseAction
{
    public function __invoke(Request $request): Response
    {
        try {
            $payload    = $request->getContent();
            $xSignature = $request->header('x-signature', '');
            $xRequestId = $request->header('x-request-id', '');

            dispatch(static function (IncomingWebhookHandler $handler) use ($payload, $xSignature, $xRequestId) {
                $handler->handle(new MercadoPagoWebhookDTO(
                    payload: $payload,
                    xSignature: $xSignature,
                    xRequestId: $xRequestId,
                ));
            })->catch(function (Throwable $exception) use ($payload) {
                logger()->error(__('Failed to handle incoming MercadoPago webhook'), [
                    'exception' => $exception,
                    'payload'   => $payload,
                ]);
            });
        } catch (Throwable $exception) {
            logger()?->error($exception->getMessage(), $exception->getTrace());
            return $this->noContentResponse(ResponseCodes::HTTP_BAD_REQUEST);
        }

        return $this->noContentResponse();
    }
}
