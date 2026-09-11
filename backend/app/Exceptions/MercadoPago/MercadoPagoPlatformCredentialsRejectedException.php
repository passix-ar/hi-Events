<?php

// Added by Passix on 2026-09-11: MercadoPago rejected the platform's own
// client_id / client_secret. Every account fails the same way, so the caller
// should stop instead of repeating the failure per row.
namespace HiEvents\Exceptions\MercadoPago;

use HiEvents\Exceptions\BaseException;

class MercadoPagoPlatformCredentialsRejectedException extends BaseException
{
}
