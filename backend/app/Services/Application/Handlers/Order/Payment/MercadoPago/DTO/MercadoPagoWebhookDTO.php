<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\MercadoPago\DTO;

use Spatie\LaravelData\Data;

class MercadoPagoWebhookDTO extends Data
{
    public function __construct(
        public readonly string $payload,
        public readonly string $xSignature,
        public readonly string $xRequestId,
    ) {
    }
}
