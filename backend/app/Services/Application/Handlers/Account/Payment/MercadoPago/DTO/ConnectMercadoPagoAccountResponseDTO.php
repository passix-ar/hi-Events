<?php

namespace HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago\DTO;

use Spatie\LaravelData\Data;

class ConnectMercadoPagoAccountResponseDTO extends Data
{
    public function __construct(
        public readonly string $authorizationUrl,
        public readonly bool   $isConnected,
    ) {
    }
}
