<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\Tools\MessageBuyersTool;
use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\MessageStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Jobs\Event\SendMessagesJob;
use HiEvents\Jobs\Message\MessagePendingReviewJob;
use HiEvents\Models\AccountMessagingTier;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\Message;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

class AssistantMessageBuyersTest extends TestCase
{
    use DatabaseTransactions;

    private const SUBJECT = 'Cambio de horario del evento';
    private const MESSAGE = "Hola! Les avisamos que el evento arranca **una hora más tarde**.\n\nGracias por la paciencia.\nEl equipo";

    private AssistantFixture $mine;
    private AssistantFixture $theirs;
    private AssistantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string)config('filesystems.public'));
        Queue::fake();
        Mail::fake();

        $this->mine = AssistantFixture::create('A');
        $this->theirs = AssistantFixture::create('B');
        $this->actingAs($this->mine->user, 'api');

        // The fixture has an order but no attendee rows; the attendee audiences
        // need them. Two tickets in the order -> two attendees, one of them cancelled.
        $this->attendee($this->mine, AttendeeStatus::ACTIVE);
        $this->attendee($this->mine, AttendeeStatus::CANCELLED);
        $this->attendee($this->theirs, AttendeeStatus::ACTIVE);

        $user = UserDomainObject::hydrateFromModel($this->mine->user);
        $user->setCurrentAccountUser($this->app->make(AccountUserRepositoryInterface::class)->findFirstWhere([
            'user_id' => $this->mine->user->id,
            'account_id' => $this->mine->account->id,
        ]));
        $this->context = $this->app->make(AssistantContextFactory::class)->create(
            user: $user, accountId: $this->mine->account->id, organizerId: $this->mine->organizer->id,
        );
    }

    private function attendee(AssistantFixture $fixture, AttendeeStatus $status): Attendee
    {
        return Attendee::create([
            'order_id' => $fixture->order->id,
            'event_id' => $fixture->event->id,
            'product_id' => $fixture->product->id,
            'product_price_id' => $fixture->price->id,
            'status' => $status->name,
            'first_name' => 'Asistente',
            'last_name' => $status->name,
            'email' => Str::lower($status->name) . '-' . $fixture->order->id . '@test.passix',
            'public_id' => Str::random(20),
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
        ]);
    }

    private function tool(): Tool
    {
        return $this->app->make(MessageBuyersTool::class, ['context' => $this->context]);
    }

    private function runTool(Tool $tool, mixed ...$arguments): array
    {
        $output = $tool->handle(...$arguments);
        $json = $output instanceof ToolError ? $output->message : (string)$output;

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Untrusted accounts (the default) go to pending review; a trusted tier
     * takes the direct path where SendMessagesJob is dispatched.
     */
    private function trustAccount(): void
    {
        $tier = AccountMessagingTier::where('name', 'Trusted')->firstOrFail();
        $this->mine->account->update(['account_messaging_tier_id' => $tier->id]);
    }

    private function baseArguments(array $overrides = []): array
    {
        return array_merge([
            'event_id' => $this->mine->event->id,
            'audience' => 'all_attendees',
            'subject' => self::SUBJECT,
            'message' => self::MESSAGE,
        ], $overrides);
    }

    // ── preview / confirmation gates ─────────────────────────────────────

    public function test_preview_shows_the_recipient_count_and_sends_nothing(): void
    {
        $result = $this->runTool($this->tool(), ...$this->baseArguments());

        $this->assertSame('needs_confirmation', $result['status']);
        $this->assertSame(1, $result['would_create']['recipient_count'], 'only the ACTIVE attendee counts');
        $this->assertSame('all_attendees', $result['would_create']['audience']);
        $this->assertSame(self::SUBJECT, $result['would_create']['subject']);
        $this->assertSame(self::MESSAGE, $result['would_create']['message']);
        $this->assertStringContainsString('ENVIAR', $result['would_create']['what_happens']);

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('messages', ['event_id' => $this->mine->event->id]);
    }

    public function test_confirm_without_the_phrase_sends_nothing(): void
    {
        $result = $this->runTool($this->tool(), ...$this->baseArguments(['confirm' => true]));
        $this->assertSame('confirmation_phrase_required', $result['error']);

        $result = $this->runTool($this->tool(), ...$this->baseArguments(['confirm' => true, 'confirmation_phrase' => 'enviar']));
        $this->assertSame('confirmation_phrase_required', $result['error'], 'the phrase is case sensitive');

        $result = $this->runTool($this->tool(), ...$this->baseArguments(['confirm' => false, 'confirmation_phrase' => 'ENVIAR']));
        $this->assertSame('needs_confirmation', $result['status'], 'the phrase alone is not a confirmation');

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('messages', ['event_id' => $this->mine->event->id]);
    }

    // ── happy paths ──────────────────────────────────────────────────────

    public function test_confirm_with_the_phrase_sends_to_all_attendees(): void
    {
        $this->trustAccount();

        $result = $this->runTool($this->tool(), ...$this->baseArguments(['confirm' => true, 'confirmation_phrase' => 'ENVIAR']));

        $this->assertSame('sent', $result['status'], json_encode($result));
        $this->assertSame(1, $result['recipient_count']);
        $this->assertStringContainsString('copy', $result['next_steps']);

        $message = Message::where('event_id', $this->mine->event->id)->firstOrFail();
        $this->assertSame($result['message_id'], $message->id);
        $this->assertSame(MessageTypeEnum::ALL_ATTENDEES->name, $message->type);
        $this->assertSame(MessageStatus::PROCESSING->name, $message->status);
        $this->assertSame(self::SUBJECT, $message->subject);
        $this->assertSame($this->mine->user->id, $message->sent_by_user_id);
        $this->assertFalse($message->send_data['is_test']);
        $this->assertTrue($message->send_data['send_copy_to_current_user']);
        $this->assertSame($this->mine->account->id, $message->send_data['account_id']);

        // Plain text became safe HTML: paragraphs, <br>, bold, no raw markup.
        $this->assertStringContainsString('<strong>una hora más tarde</strong>', $message->message);
        $this->assertStringContainsString('<br', $message->message);
        $this->assertStringStartsWith('<p>', $message->message);
        $this->assertStringNotContainsString('**', $message->message);

        Queue::assertPushed(SendMessagesJob::class, 1);
        Queue::assertNotPushed(MessagePendingReviewJob::class);
    }

    public function test_untrusted_account_lands_in_pending_review(): void
    {
        $result = $this->runTool($this->tool(), ...$this->baseArguments(['confirm' => true, 'confirmation_phrase' => 'ENVIAR']));

        $this->assertSame('pending_review', $result['status'], json_encode($result));
        $this->assertDatabaseHas('messages', [
            'id' => $result['message_id'],
            'event_id' => $this->mine->event->id,
            'status' => MessageStatus::PENDING_REVIEW->name,
        ]);

        Queue::assertPushed(MessagePendingReviewJob::class, 1);
        Queue::assertNotPushed(SendMessagesJob::class);
    }

    public function test_order_owners_audience_targets_completed_orders_through_every_product(): void
    {
        $this->trustAccount();

        $preview = $this->runTool($this->tool(), ...$this->baseArguments(['audience' => 'order_owners']));
        $this->assertSame(1, $preview['would_create']['recipient_count']);

        $result = $this->runTool($this->tool(), ...$this->baseArguments([
            'audience' => 'order_owners', 'confirm' => true, 'confirmation_phrase' => 'ENVIAR',
        ]));

        $this->assertSame('sent', $result['status'], json_encode($result));

        $message = Message::where('event_id', $this->mine->event->id)->firstOrFail();
        $this->assertSame(MessageTypeEnum::ORDER_OWNERS_WITH_PRODUCT->name, $message->type);
        $this->assertSame([$this->mine->product->id], $message->product_ids);
        $this->assertSame(['COMPLETED'], $message->send_data['order_statuses']);

        Queue::assertPushed(SendMessagesJob::class, 1);
    }

    public function test_ticket_type_audience_targets_holders_of_that_product(): void
    {
        $this->trustAccount();

        $result = $this->runTool($this->tool(), ...$this->baseArguments([
            'audience' => 'ticket_type',
            'product_id' => $this->mine->product->id,
            'confirm' => true,
            'confirmation_phrase' => 'ENVIAR',
        ]));

        $this->assertSame('sent', $result['status'], json_encode($result));
        $this->assertSame(1, $result['recipient_count']);

        $message = Message::where('event_id', $this->mine->event->id)->firstOrFail();
        $this->assertSame(MessageTypeEnum::TICKET_HOLDERS->name, $message->type);
        $this->assertSame([$this->mine->product->id], $message->product_ids);

        Queue::assertPushed(SendMessagesJob::class, 1);
    }

    // ── refusals ─────────────────────────────────────────────────────────

    public function test_ticket_type_with_a_product_of_another_event_is_rejected(): void
    {
        $result = $this->runTool($this->tool(), ...$this->baseArguments([
            'audience' => 'ticket_type',
            'product_id' => $this->theirs->product->id,
            'confirm' => true,
            'confirmation_phrase' => 'ENVIAR',
        ]));

        $this->assertSame('invalid_arguments', $result['error']);

        $result = $this->runTool($this->tool(), ...$this->baseArguments(['audience' => 'ticket_type']));
        $this->assertSame('invalid_arguments', $result['error'], 'ticket_type without product_id');

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('messages', ['event_id' => $this->mine->event->id]);
    }

    public function test_cross_tenant_event_is_not_found(): void
    {
        $result = $this->runTool($this->tool(), ...$this->baseArguments([
            'event_id' => $this->theirs->event->id,
            'confirm' => true,
            'confirmation_phrase' => 'ENVIAR',
        ]));

        $this->assertSame(['error' => 'event_not_found'], $result);

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('messages', ['event_id' => $this->theirs->event->id]);
    }

    public function test_event_without_anyone_to_email_returns_no_recipients(): void
    {
        $draft = Event::withoutEvents(fn(): Event => Event::create([
            'title' => 'Borrador sin ventas',
            'account_id' => $this->mine->account->id,
            'organizer_id' => $this->mine->organizer->id,
            'user_id' => $this->mine->user->id,
            'status' => 'DRAFT',
            'start_date' => now()->addMonth(),
            'end_date' => now()->addMonth()->addHours(2),
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'short_id' => Str::random(8),
        ]));

        foreach (['all_attendees', 'order_owners'] as $audience) {
            $result = $this->runTool($this->tool(), ...$this->baseArguments([
                'event_id' => $draft->id,
                'audience' => $audience,
                'confirm' => true,
                'confirmation_phrase' => 'ENVIAR',
            ]));

            $this->assertSame('no_recipients', $result['error'], $audience);
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('messages', ['event_id' => $draft->id]);
    }

    public function test_subject_and_message_lengths_are_validated(): void
    {
        $result = $this->runTool($this->tool(), ...$this->baseArguments(['subject' => 'Hi']));
        $this->assertSame('invalid_arguments', $result['error']);

        $result = $this->runTool($this->tool(), ...$this->baseArguments(['message' => 'Muy corto.']));
        $this->assertSame('invalid_arguments', $result['error']);

        $result = $this->runTool($this->tool(), ...$this->baseArguments(['audience' => 'everyone']));
        $this->assertSame('invalid_arguments', $result['error']);

        Queue::assertNothingPushed();
    }

    public function test_tier_limit_violations_are_reported_as_cannot_send(): void
    {
        // The default (Untrusted) tier forbids links in the body.
        $result = $this->runTool($this->tool(), ...$this->baseArguments([
            'message' => 'Tienen toda la info en https://ejemplo.com/evento, nos vemos ahí!',
            'confirm' => true,
            'confirmation_phrase' => 'ENVIAR',
        ]));

        $this->assertSame('cannot_send', $result['error'], json_encode($result));
        $this->assertNotEmpty($result['details']);

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('messages', ['event_id' => $this->mine->event->id]);
    }
}
