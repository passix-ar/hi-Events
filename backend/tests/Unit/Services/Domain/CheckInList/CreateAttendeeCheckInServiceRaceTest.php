<?php

namespace Tests\Unit\Services\Domain\CheckInList;

use HiEvents\DataTransferObjects\ErrorBagDTO;
use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Repository\Interfaces\AttendeeCheckInRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\AttendeeAndActionDTO;
use HiEvents\Services\Domain\CheckInList\CheckInListDataService;
use HiEvents\Services\Domain\CheckInList\CreateAttendeeCheckInService;
use HiEvents\Services\Domain\CheckInList\DTO\CreateAttendeeCheckInsResponseDTO;
use HiEvents\Services\Domain\Order\MarkOrderAsPaidService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Mockery as m;
use PDOException;
use Tests\TestCase;

class CreateAttendeeCheckInServiceRaceTest extends TestCase
{
    private AttendeeCheckInRepositoryInterface $attendeeCheckInRepository;
    private CheckInListDataService $checkInListDataService;
    private EventSettingsRepositoryInterface $eventSettingsRepository;
    private ConnectionInterface $db;
    private MarkOrderAsPaidService $markOrderAsPaidService;
    private CreateAttendeeCheckInService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeCheckInRepository = m::mock(AttendeeCheckInRepositoryInterface::class);
        $this->checkInListDataService = m::mock(CheckInListDataService::class);
        $this->eventSettingsRepository = m::mock(EventSettingsRepositoryInterface::class);
        $this->db = m::mock(ConnectionInterface::class);
        $this->markOrderAsPaidService = m::mock(MarkOrderAsPaidService::class);

        $this->service = new CreateAttendeeCheckInService(
            $this->attendeeCheckInRepository,
            $this->checkInListDataService,
            $this->eventSettingsRepository,
            $this->db,
            $this->markOrderAsPaidService,
        );
    }

    public function testConcurrentDuplicateIsReportedAsAlreadyCheckedIn(): void
    {
        $this->primeFlow();

        // The insert loses the race: the partial unique index rejects it with SQLSTATE 23505.
        $this->attendeeCheckInRepository
            ->shouldReceive('create')
            ->once()
            ->andThrow($this->queryException('23505'));

        // The catch re-reads the winning check-in to hand it back with the error.
        $existingCheckIn = m::mock(AttendeeCheckInDomainObject::class);
        $this->attendeeCheckInRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($existingCheckIn);

        $response = $this->service->checkInAttendees(
            'cil_test',
            '127.0.0.1',
            collect([new AttendeeAndActionDTO('A-TEST', AttendeeCheckInActionType::CHECK_IN)]),
        );

        $this->assertInstanceOf(CreateAttendeeCheckInsResponseDTO::class, $response);
        // No duplicate created: the existing check-in is returned, and the attendee is flagged with an error.
        $this->assertCount(1, $response->attendeeCheckIns);
        $this->assertSame($existingCheckIn, $response->attendeeCheckIns->first());
        $this->assertArrayHasKey('A-TEST', $response->errors->errors);
    }

    public function testNonUniqueViolationIsNotSwallowed(): void
    {
        $this->primeFlow();

        // A different database error (e.g. undefined column) must never be reported as a check-in.
        $this->attendeeCheckInRepository
            ->shouldReceive('create')
            ->once()
            ->andThrow($this->queryException('42703'));

        $this->attendeeCheckInRepository->shouldNotReceive('findFirstWhere');

        $this->expectException(QueryException::class);

        $this->service->checkInAttendees(
            'cil_test',
            '127.0.0.1',
            collect([new AttendeeAndActionDTO('A-TEST', AttendeeCheckInActionType::CHECK_IN)]),
        );
    }

    /**
     * Mocks every collaborator up to (and including) the transaction, so the test drives the insert
     * straight into the createCheckIn call where the race is decided.
     */
    private function primeFlow(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->andReturn(null);
        $checkInList->shouldReceive('getEventId')->andReturn(123);
        $checkInList->shouldReceive('getId')->andReturn(55);

        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getPublicId')->andReturn('A-TEST');
        $attendee->shouldReceive('getId')->andReturn(10);
        $attendee->shouldReceive('getOrderId')->andReturn(1);
        $attendee->shouldReceive('getProductId')->andReturn(2);
        $attendee->shouldReceive('getEventId')->andReturn(123);
        $attendee->shouldReceive('getStatus')->andReturn(AttendeeStatus::ACTIVE->name);
        $attendee->shouldReceive('getFullName')->andReturn('Juan Perez');

        $eventSettings = m::mock(EventSettingDomainObject::class);
        $eventSettings->shouldReceive('getAllowOrdersAwaitingOfflinePaymentToCheckIn')->andReturn(false);

        $this->checkInListDataService->shouldReceive('getCheckInList')->once()->andReturn($checkInList);
        $this->checkInListDataService->shouldReceive('getAttendees')->once()->andReturn(collect([$attendee]));
        $this->checkInListDataService->shouldReceive('verifyAttendeeBelongsToCheckInList')->once();

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->once()->andReturn($eventSettings);

        // No prior active check-in seen at read time — this is what makes the race possible.
        $this->attendeeCheckInRepository->shouldReceive('findWhereIn')->once()->andReturn(new Collection());

        // Run the transaction body inline so the create() throw surfaces to the service's catch.
        $this->db->shouldReceive('transaction')->once()->andReturnUsing(fn (callable $callback) => $callback());
    }

    private function queryException(string $sqlState): QueryException
    {
        $previous = new class($sqlState) extends PDOException {
            public function __construct(string $sqlState)
            {
                parent::__construct('SQLSTATE[' . $sqlState . ']');
                $this->code = $sqlState;
            }
        };

        return new QueryException('pgsql', 'insert into "attendee_check_ins"', [], $previous);
    }
}
