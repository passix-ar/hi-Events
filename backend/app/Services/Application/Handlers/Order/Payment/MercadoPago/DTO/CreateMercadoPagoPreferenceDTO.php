<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\MercadoPago\DTO;

use Spatie\LaravelData\Data;

class CreateMercadoPagoPreferenceDTO extends Data
{
    public function __construct(
        public readonly int    $eventId,
        public readonly string $orderShortId,
    ) {
    }
}
