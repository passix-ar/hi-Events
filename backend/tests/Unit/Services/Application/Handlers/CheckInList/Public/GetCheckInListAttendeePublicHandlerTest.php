<?php

namespace Tests\Unit\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\Public\GetCheckInListAttendeePublicHandler;
use HiEvents\Services\Domain\CheckInList\AttendeeOtherListCheckInsService;
use Mockery as m;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class GetCheckInListAttendeePublicHandlerTest extends TestCase
{
    private CheckInListRepositoryInterface $checkInListRepository;
    private AttendeeRepositoryInterface $attendeeRepository;
    private AttendeeOtherListCheckInsService $otherListCheckInsService;
    private GetCheckInListAttendeePublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkInListRepository = m::mock(CheckInListRepositoryInterface::class);
        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);

        $this->otherListCheckInsService = m::mock(AttendeeOtherListCheckInsService::class);
        $this->otherListCheckInsService->shouldReceive('attach')->byDefault();

        $this->handler = new GetCheckInListAttendeePublicHandler(
            $this->attendeeRepository,
            $this->checkInListRepository,
            $this->otherListCheckInsService,
        );
    }

    public function testHandleThrowsNotFoundIfCheckInListMissing(): void
    {
        $this->checkInListRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturnNull();

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle('short-id', 'attendee-public-id');
    }

    public function testHandleThrowsCannotCheckInIfListExpired(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->twice()->andReturn(now()->subMinute());

        $this->checkInListRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->expectException(CannotCheckInException::class);

        $this->handler->handle('short-id', 'attendee-public-id');
    }

    public function testHandleThrowsCannotCheckInIfListNotActiveYet(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->twice()->andReturn(now()->addMinute());

        $this->checkInListRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->expectException(CannotCheckInException::class);

        $this->handler->handle('short-id', 'attendee-public-id');
    }

    /**
     * A code that matches no attendee of this event is the ordinary outcome of scanning a QR from
     * another event, or from another app. It has to come back as null for the action to answer 404:
     * returned through a non-nullable type it was a TypeError, so every stray scan at the door
     * logged a 500.
     */
    public function testHandleReturnsNullWhenTheAttendeeDoesNotExist(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getEventId')->once()->andReturn(123);

        $this->checkInListRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->attendeeRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->attendeeRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturnNull();

        // Nothing to attach, and calling it with a null attendee would fault in its own right.
        $this->otherListCheckInsService->shouldNotReceive('attach');

        $this->assertNull($this->handler->handle('short-id', 'A-NOBODY'));
    }

    public function testHandleReturnsAttendeeSuccessfully(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getEventId')->once()->andReturn(123);

        $attendee = m::mock(AttendeeDomainObject::class);

        $this->checkInListRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        // Two relations: the attendee's check-ins, and the product whose title the door shows.
        $this->attendeeRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->attendeeRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                'public_id' => 'attendee-public-id',
                'event_id' => 123,
            ])
            ->andReturn($attendee);

        // The door needs to know if this ticket already entered through another
        // list of the event, so the single lookup gets the same treatment as
        // the full list.
        $this->otherListCheckInsService
            ->shouldReceive('attach')
            ->once()
            ->with(m::on(fn($attendees) => $attendees->count() === 1 && $attendees->first() === $attendee), $checkInList);

        $result = $this->handler->handle('short-id', 'attendee-public-id');

        $this->assertSame($attendee, $result);
    }
}
