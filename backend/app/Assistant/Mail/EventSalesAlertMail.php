<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Mail;

use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * @uses /backend/app/Assistant/resources/views/mail/event-sales-alert.blade.php
 */
class EventSalesAlertMail extends BaseMail
{
    public function __construct(
        public readonly int    $eventId,
        public readonly string $title,
        public readonly int    $sold,
        public readonly int    $capacity,
        public readonly float  $soldPct,
        public readonly int    $daysLeft,
        public readonly ?float $benchmarkPct,
    )
    {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        $days = $this->daysLeft === 1 ? '1 día' : $this->daysLeft . ' días';

        return new Envelope(
            subject: sprintf('%s va al %s%% a %s del evento', $this->title, $this->formatPct($this->soldPct), $days),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'assistant::mail.event-sales-alert',
            with: [
                'eventId' => $this->eventId,
                'title' => $this->title,
                'sold' => $this->sold,
                'capacity' => $this->capacity,
                'soldPct' => $this->formatPct($this->soldPct),
                'daysLeft' => $this->daysLeft,
                'benchmarkPct' => $this->benchmarkPct === null ? null : $this->formatPct($this->benchmarkPct),
                'panelUrl' => rtrim((string)config('app.frontend_url'), '/') . '/manage/event/' . $this->eventId . '/dashboard',
            ],
        );
    }

    private function formatPct(float $pct): string
    {
        return rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.');
    }
}
