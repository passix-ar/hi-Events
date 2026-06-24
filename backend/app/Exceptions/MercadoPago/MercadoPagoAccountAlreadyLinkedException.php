<?php

// Added by Passix: raised when a MercadoPago seller is already linked to a
// different Passix account. Kept distinct so the callback can surface a clear,
// specific message instead of the generic OAuth failure.
namespace HiEvents\Exceptions\MercadoPago;

class MercadoPagoAccountAlreadyLinkedException extends MercadoPagoOAuthException
{
}
