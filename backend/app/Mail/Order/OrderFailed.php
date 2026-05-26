<?php

// Modified by Passix on 2026-05-25: Fixed hardcoded hello@hi.events, now uses event support email config.
namespace HiEvents\Mail\Order;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Config;

/**
 * @uses /backend/resources/views/emails/orders/order-failed.blade.php
 */
class OrderFailed extends BaseMail
{
    public function __construct(
        private readonly OrderDomainObject        $order,
        private readonly EventDomainObject        $event,
        private readonly OrganizerDomainObject    $organizer,
        private readonly EventSettingDomainObject $eventSettings,
    )
    {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->eventSettings->getSupportEmail(),
            subject: __('Your order wasn\'t successful'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.orders.order-failed',
            with: [
                'event' => $this->event,
                'order' => $this->order,
                'organizer' => $this->organizer,
                'eventSettings' => $this->eventSettings,
                'eventUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::EVENT_HOMEPAGE),
                    $this->event->getId(),
                    $this->event->getSlug(),
                ),
                'supportEmail' => $this->eventSettings->getSupportEmail()
                    ?? Config::get('app.platform_support_email'),
            ]
        );
    }
}
