<?php

namespace Tests\Unit\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DataTransferObjects\ErrorBagDTO;
use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\Services\Application\Handlers\CheckInList\Public\CreateAttendeeCheckInPublicHandler;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\CreateAttendeeCheckInPublicDTO;
use HiEvents\Services\Domain\CheckInList\CreateAttendeeCheckInService;
use HiEvents\Services\Domain\CheckInList\DTO\CreateAttendeeCheckInsResponseDTO;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\CheckinEvent;
use Illuminate\Support\Collection;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * The door scanner queues its check-ins and re-sends a batch until the server answers, so the same
 * request arrives more than once as a matter of course — a dropped response at a venue is normal.
 * The second time round the server finds the check-ins already on record and hands them back so the
 * scanner can confirm them, which is right; what must not happen is a fresh checkin.created for each
 * of those, because every one of them reaches the event organiser's webhook.
 */
class CreateAttendeeCheckInPublicHandlerTest extends TestCase
{
    private CreateAttendeeCheckInService $createAttendeeCheckInService;
    private DomainEventDispatcherService $domainEventDispatcherService;
    private CreateAttendeeCheckInPublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAttendeeCheckInService = m::mock(CreateAttendeeCheckInService::class);
        $this->domainEventDispatcherService = m::mock(DomainEventDispatcherService::class);

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->byDefault();

        $this->handler = new CreateAttendeeCheckInPublicHandler(
            $this->createAttendeeCheckInService,
            $logger,
            $this->domainEventDispatcherService,
        );
    }

    public function testEveryNewCheckInIsAnnounced(): void
    {
        $created = collect([$this->checkIn(1), $this->checkIn(2)]);
        $this->primeService($created, $created);

        $announced = [];
        $this->domainEventDispatcherService
            ->shouldReceive('dispatch')
            ->twice()
            ->andReturnUsing(function (CheckinEvent $event) use (&$announced) {
                $this->assertSame(DomainEventType::CHECKIN_CREATED, $event->type);
                $announced[] = $event->attendeeCheckinId;
            });

        $this->handler->handle($this->dto());

        $this->assertSame([1, 2], $announced);
    }

    public function testACheckInThatAlreadyExistedIsNotAnnouncedAgain(): void
    {
        // What a re-sent batch looks like: the check-in comes back so the scanner can confirm it,
        // but this request did not write it.
        $this->primeService(returned: collect([$this->checkIn(1)]), created: collect());

        $this->domainEventDispatcherService->shouldNotReceive('dispatch');

        $response = $this->handler->handle($this->dto());

        // Still returned to the door — the scanner keys its optimistic mark off this.
        $this->assertCount(1, $response->attendeeCheckIns);
    }

    public function testOnlyTheNewOneIsAnnouncedInAMixedBatch(): void
    {
        $this->primeService(
            returned: collect([$this->checkIn(1), $this->checkIn(2)]),
            created: collect([$this->checkIn(2)]),
        );

        $this->domainEventDispatcherService
            ->shouldReceive('dispatch')
            ->once()
            ->with(m::on(fn(CheckinEvent $event) => $event->attendeeCheckinId === 2));

        $this->handler->handle($this->dto());
    }

    private function primeService(Collection $returned, Collection $created): void
    {
        $this->createAttendeeCheckInService
            ->shouldReceive('checkInAttendees')
            ->once()
            ->andReturn(new CreateAttendeeCheckInsResponseDTO(
                attendeeCheckIns: $returned,
                errors: new ErrorBagDTO(),
                createdCheckIns: $created,
            ));
    }

    private function checkIn(int $id): AttendeeCheckInDomainObject
    {
        $checkIn = m::mock(AttendeeCheckInDomainObject::class);
        $checkIn->shouldReceive('getId')->andReturn($id);
        $checkIn->shouldReceive('getAttendeeId')->andReturn($id * 10);

        return $checkIn;
    }

    private function dto(): CreateAttendeeCheckInPublicDTO
    {
        return CreateAttendeeCheckInPublicDTO::from([
            'checkInListUuid' => 'cil_test',
            'checkInUserIpAddress' => '127.0.0.1',
            'attendeesAndActions' => [
                ['public_id' => 'A-ONE', 'action' => 'check-in'],
            ],
        ]);
    }
}
