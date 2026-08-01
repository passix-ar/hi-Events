<?php

// Added by Passix: disconnecting would leave published events unable to sell.
namespace HiEvents\Exceptions\MercadoPago;

use Exception;

class CannotDisconnectMercadoPagoException extends Exception
{
}
