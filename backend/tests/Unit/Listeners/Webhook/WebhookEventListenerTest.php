<?php

namespace Tests\Unit\Listeners\Webhook;

use HiEvents\Jobs\Order\Webhook\DispatchAttendeeWebhookJob;
use HiEvents\Jobs\Order\Webhook\DispatchCheckInWebhookJob;
use HiEvents\Jobs\Order\Webhook\DispatchOrderWebhookJob;
use HiEvents\Jobs\Order\Webhook\DispatchProductWebhookJob;
use HiEvents\Listeners\Webhook\WebhookEventListener;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\AttendeeEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\BaseDomainEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\CheckinEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\ProductEvent;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The listener already asks for a queue with onQueue(), but that is only honoured when the job
 * declares ShouldQueue. Without the interface the dispatch runs inline, inside the buyer's request,
 * so the organiser's webhook endpoint decides how long the checkout takes.
 *
 * Queue::fake() only intercepts queueable jobs, so these assertions fail if the interface is dropped.
 */
class WebhookEventListenerTest extends TestCase
{
    private const QUEUE_NAME = 'webhooks';

    private WebhookEventListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('queue.webhook_queue_name')
            ->andReturn(self::QUEUE_NAME);

        $this->listener = new WebhookEventListener($config);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public static function webhookJobProvider(): array
    {
        return [
            'order' => [new OrderEvent(DomainEventType::ORDER_CREATED, 42), DispatchOrderWebhookJob::class],
            'attendee' => [new AttendeeEvent(DomainEventType::ATTENDEE_CREATED, 42), DispatchAttendeeWebhookJob::class],
            'product' => [new ProductEvent(DomainEventType::PRODUCT_CREATED, 42), DispatchProductWebhookJob::class],
            'check-in' => [new CheckinEvent(DomainEventType::CHECKIN_CREATED, 42), DispatchCheckInWebhookJob::class],
        ];
    }

    #[DataProvider('webhookJobProvider')]
    public function testHandleQueuesTheWebhookJobInsteadOfRunningItInline(
        BaseDomainEvent $event,
        string $jobClass,
    ): void
    {
        $this->listener->handle($event);

        Queue::assertPushedOn(self::QUEUE_NAME, $jobClass);
    }

    #[DataProvider('webhookJobProvider')]
    public function testWebhookJobIsQueueable(BaseDomainEvent $event, string $jobClass): void
    {
        $this->assertTrue(
            is_subclass_of($jobClass, ShouldQueue::class),
            $jobClass . ' must implement ShouldQueue, otherwise dispatch() runs it inside the request.',
        );
    }
}
