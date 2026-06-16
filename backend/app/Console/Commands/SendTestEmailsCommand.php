<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\MessageDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\DomainObjects\WaitlistEntryDomainObject;
use HiEvents\Mail\Account\ConfirmEmailAddressEmail;
use HiEvents\Mail\Account\EmailConfirmationCodeEmail;
use HiEvents\Mail\Admin\MessagePendingReviewMail;
use HiEvents\Mail\Attendee\AttendeeDetailsChangedMail;
use HiEvents\Mail\Attendee\AttendeeTicketMail;
use HiEvents\Mail\Event\EventMessage;
use HiEvents\Mail\Order\OrderCancelled;
use HiEvents\Mail\Order\OrderDetailsChangedMail;
use HiEvents\Mail\Order\OrderFailed;
use HiEvents\Mail\Order\OrderRefunded;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Mail\Order\PaymentSuccessButOrderExpiredMail;
use HiEvents\Mail\Organizer\OrderSummaryForOrganizer;
use HiEvents\Mail\Organizer\OrganizerContactEmail;
use HiEvents\Mail\TicketLookup\TicketLookupEmail;
use HiEvents\Mail\User\ConfirmEmailChangeMail;
use HiEvents\Mail\User\ForgotPassword;
use HiEvents\Mail\User\ResetPasswordSuccess;
use HiEvents\Mail\User\UserInvited;
use HiEvents\Mail\Waitlist\WaitlistConfirmationMail;
use HiEvents\Mail\Waitlist\WaitlistOfferExpiredMail;
use HiEvents\Mail\Waitlist\WaitlistOfferMail;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Values\MoneyValue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * TEMPORARY / DISPOSABLE COMMAND — borrar después de usar.
 *
 * Envía los 22 correos transaccionales con datos de prueba (in-memory, sin tocar la DB)
 * a la casilla indicada, forzando el locale para verificar las traducciones y el branding.
 *
 *   php artisan passix:send-test-emails getpassix@gmail.com
 */
class SendTestEmailsCommand extends Command
{
    protected $signature = 'passix:send-test-emails {email : Destinatario de prueba} {--locale=es : Locale a forzar}';

    protected $description = '[TEMPORAL] Envía los 22 correos con datos de prueba para verificación visual.';

    public function handle(): int
    {
        $email = (string)$this->argument('email');
        $locale = (string)$this->option('locale');

        app()->setLocale($locale);

        $this->info("Enviando correos de prueba a {$email} (locale={$locale})...");
        $this->newLine();

        $mailables = $this->buildMailables($email);

        $ok = 0;
        $fail = 0;

        foreach ($mailables as $label => $mailable) {
            try {
                Mail::to($email)->locale($locale)->sendNow($mailable);
                $this->info("  ✓ {$label}");
                $ok++;
            } catch (Throwable $e) {
                $this->error("  ✗ {$label}: " . $e->getMessage());
                $fail++;
            }
            // Pequeña pausa para no saturar el proveedor SMTP.
            usleep(250_000);
        }

        $this->newLine();
        $this->info("Listo. Enviados: {$ok} · Fallidos: {$fail} · Total: " . count($mailables));

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, \HiEvents\Mail\BaseMail>
     */
    private function buildMailables(string $email): array
    {
        // ---- Organizer ----
        $organizer = new OrganizerDomainObject();
        $organizer->setId(1);
        $organizer->setName('Productora Passix');
        $organizer->setEmail('hola@getpassix.com');

        // ---- Event settings ----
        $eventSettings = new EventSettingDomainObject();
        $eventSettings->setSupportEmail('hola@getpassix.com');
        $eventSettings->setPostCheckoutMessage('<p>¡Gracias por tu compra! Llevá tu entrada en el celular y mostrala al ingresar.</p>');
        $eventSettings->setEmailFooterMessage('Passix · Eventos en Argentina');

        // ---- Event ----
        $event = new EventDomainObject();
        $event->setId(1);
        $event->setTitle('Evento de Prueba Passix');
        $event->setDescription('<p>Una noche de prueba para validar los correos de Passix. 🎟️</p>');
        $event->setStartDate('2026-09-12 00:00:00'); // UTC
        $event->setEndDate('2026-09-12 04:00:00');   // UTC
        $event->setTimezone('America/Argentina/Buenos_Aires');
        $event->setCurrency('ARS');
        $event->setStatus('LIVE');
        $event->setEventSettings($eventSettings);
        $event->setOrganizer($organizer);

        // ---- Order ----
        $order = new OrderDomainObject();
        $order->setId(1);
        $order->setPublicId('O-PASSIX-0001');
        $order->setShortId('passixorder1');
        $order->setFirstName('Juan');
        $order->setLastName('Pérez');
        $order->setEmail($email);
        $order->setCurrency('ARS');
        $order->setStatus('COMPLETED');
        $order->setTotalGross(85000.00);
        $order->setLocale('es');

        // ---- Attendee ----
        $attendee = new AttendeeDomainObject();
        $attendee->setId(1);
        $attendee->setShortId('passixatt1');
        $attendee->setFirstName('Juan');
        $attendee->setLastName('Pérez');
        $attendee->setEmail($email);
        $attendee->setLocale('es');

        // ---- User ----
        $user = new UserDomainObject();
        $user->setId(1);
        $user->setFirstName('Juan');
        $user->setLastName('Pérez');
        $user->setEmail($email);
        $user->setLocale('es');
        $user->setPendingEmail('juan.nuevo@example.com');

        // ---- Waitlist entry ----
        $waitlistEntry = new WaitlistEntryDomainObject();
        $waitlistEntry->setId(1);
        $waitlistEntry->setEventId(1);
        $waitlistEntry->setEmail($email);
        $waitlistEntry->setFirstName('Juan');
        $waitlistEntry->setLastName('Pérez');
        $waitlistEntry->setStatus('WAITING');
        $waitlistEntry->setPosition(3);
        $waitlistEntry->setLocale('es');
        $waitlistEntry->setOfferExpiresAt('2026-09-10 23:59:00');

        // ---- Account + Message (admin) ----
        $account = new AccountDomainObject();
        $account->setId(1);
        $account->setName('Cuenta Passix de Prueba');

        $message = new MessageDomainObject();
        $message->setId(1);
        $message->setSubject('Novedades del evento');
        $message->setMessage('<p>Mensaje de prueba pendiente de revisión.</p>');

        // ---- DTOs / values ----
        $refundAmount = MoneyValue::fromFloat(85000.00, 'ARS');

        $sendMessageDTO = new SendMessageDTO(
            account_id: 1,
            event_id: 1,
            subject: '📣 Novedades del evento — Passix',
            message: '<p>Hola, te escribimos desde <b>Passix</b> con novedades del evento. ¡Nos vemos pronto!</p>',
            type: MessageTypeEnum::ORDER_OWNER,
            is_test: true,
            send_copy_to_current_user: false,
            sent_by_user_id: 1,
        );

        $changedFieldsOrder = [
            'Nombre' => ['old' => 'Juan Pérez', 'new' => 'Juan A. Pérez'],
            'Email' => ['old' => 'juan@example.com', 'new' => 'juan.perez@example.com'],
        ];
        $changedFieldsAttendee = [
            'Nombre' => ['old' => 'Juan Pérez', 'new' => 'Juan A. Pérez'],
        ];

        $failures = ['stripe_not_connected', 'no_paid_orders', 'event_too_new'];

        // ---- Los 22 mailables ----
        return [
            // Cuenta / autenticación
            'ConfirmEmailAddressEmail' => new ConfirmEmailAddressEmail($user, 'token-confirm-123'),
            'EmailConfirmationCodeEmail' => new EmailConfirmationCodeEmail($user, '482913'),
            'ConfirmEmailChangeMail' => new ConfirmEmailChangeMail($user, 'token-change-123'),
            'UserInvited' => new UserInvited($user, (string)config('app.name'), 'https://app.getpassix.com/accept-invitation/1/token-invite-123'),
            'ForgotPassword' => new ForgotPassword($user, 'token-reset-123'),
            'ResetPasswordSuccess' => new ResetPasswordSuccess(),

            // Órdenes / pagos (comprador)
            'OrderSummary' => new OrderSummary($order, $event, $organizer, $eventSettings, null),
            'OrderFailed' => new OrderFailed($order, $event, $organizer, $eventSettings),
            'OrderCancelled' => new OrderCancelled($order, $event, $organizer, $eventSettings),
            'OrderRefunded' => new OrderRefunded($order, $event, $organizer, $eventSettings, $refundAmount),
            'OrderDetailsChangedMail' => new OrderDetailsChangedMail($event, $organizer, $eventSettings, $changedFieldsOrder),
            'PaymentSuccessButOrderExpiredMail' => new PaymentSuccessButOrderExpiredMail($order, $event, $eventSettings, $organizer),

            // Entradas (asistente)
            'AttendeeTicketMail' => new AttendeeTicketMail($order, $attendee, $event, $eventSettings, $organizer),
            'AttendeeDetailsChangedMail' => new AttendeeDetailsChangedMail('Entrada General', $event, $organizer, $eventSettings, $changedFieldsAttendee),

            // Organizador
            'OrderSummaryForOrganizer' => new OrderSummaryForOrganizer($order, $event),
            'OrganizerContactEmail' => new OrganizerContactEmail($organizer, 'María González', 'maria@example.com', 'Hola, quería consultar por la disponibilidad de entradas. ¡Gracias!'),

            // Lista de espera
            'WaitlistConfirmationMail' => new WaitlistConfirmationMail($waitlistEntry, $event, null, null, $organizer, $eventSettings),
            'WaitlistOfferMail' => new WaitlistOfferMail($waitlistEntry, $event, null, null, $organizer, $eventSettings, 'passixorder1', 'sess-test-123'),
            'WaitlistOfferExpiredMail' => new WaitlistOfferExpiredMail($waitlistEntry, $event, null, null, $organizer, $eventSettings),

            // Mensajería / lookup / admin
            'EventMessage' => new EventMessage($event, $eventSettings, $sendMessageDTO),
            'TicketLookupEmail' => new TicketLookupEmail($email, 'token-lookup-123', 2),
            'MessagePendingReviewMail' => new MessagePendingReviewMail($message, $event, $account, $failures),
        ];
    }
}
