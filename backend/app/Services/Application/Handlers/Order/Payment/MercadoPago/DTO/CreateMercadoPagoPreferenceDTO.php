<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
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
