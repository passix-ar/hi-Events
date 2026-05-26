<?php

namespace HiEvents\DomainObjects\Enums;

enum PaymentProviders: string
{
    use BaseEnum;

    case STRIPE = 'STRIPE';
    case MERCADOPAGO = 'MERCADOPAGO';
    case OFFLINE = 'OFFLINE';
}
