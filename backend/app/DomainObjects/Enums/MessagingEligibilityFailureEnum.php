<?php

namespace HiEvents\DomainObjects\Enums;

enum MessagingEligibilityFailureEnum: string
{
    case PAYMENT_NOT_CONNECTED = 'payment_not_connected';
    case NO_PAID_ORDERS = 'no_paid_orders';
    case EVENT_TOO_NEW = 'event_too_new';
}
