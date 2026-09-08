<?php

// Added by Passix: disconnecting would leave published events unable to sell.
namespace HiEvents\Exceptions\MercadoPago;

use HiEvents\Exceptions\BaseException;

class CannotDisconnectMercadoPagoException extends BaseException
{
}
