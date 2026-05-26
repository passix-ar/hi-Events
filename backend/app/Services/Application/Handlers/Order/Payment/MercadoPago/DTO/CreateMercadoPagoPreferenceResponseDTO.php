<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Services\Application\Handlers\Order\Payment\MercadoPago\DTO;

use Spatie\LaravelData\Data;

class CreateMercadoPagoPreferenceResponseDTO extends Data
{
    public function __construct(
        public readonly string $preferenceId,
        public readonly string $initPoint,
        public readonly string $sandboxInitPoint,
        public readonly bool   $isSandbox,
    ) {
    }
}
