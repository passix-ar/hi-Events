<?php

namespace HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago\DTO;

use Spatie\LaravelData\Data;

class ConnectMercadoPagoAccountDTO extends Data
{
    public function __construct(
        public readonly int $accountId,
    ) {
    }
}
