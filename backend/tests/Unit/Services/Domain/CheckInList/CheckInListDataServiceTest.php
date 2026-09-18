<?php

namespace Tests\Unit\Services\Domain\CheckInList;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Services\Domain\CheckInList\CheckInListDataService;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class CheckInListDataServiceTest extends TestCase
{
    private CheckInListRepositoryInterface $checkInListRepository;
    private AttendeeRepositoryInterface $attendeeRepository;
    private CheckInListDataService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkInListRepository = m::mock(CheckInListRepositoryInterface::class);
        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);

        $this->service = new CheckInListDataService(
            $this->checkInListRepository,
            $this->attendeeRepository,
        );
    }

    /**
     * The check-in endpoints are public and keyed only by a shareable short id, so the lookup has to
     * be scoped to the list's event. Unscoped, a public_id from another event resolves, and the
     * refusal that follows names the attendee — leaking a name out of an unrelated event.
     */
    public function testAttendeesAreLookedUpScopedToTheEvent(): void
    {
        $this->attendeeRepository
            ->shouldReceive('findWhereIn')
            ->once()
            ->with(
                m::on(fn($field) => $field === AttendeeDomainObjectAbstract::PUBLIC_ID),
                m::on(fn($values) => $values === ['A-ONE']),
                m::on(fn($where) => $where === [AttendeeDomainObjectAbstract::EVENT_ID => 123]),
            )
            ->andReturn(new Collection());

        $this->service->getAttendees(collect(['A-ONE']), 123);
    }

    /**
     * The same code scanned twice in one batch must not be queried twice.
     */
    public function testRepeatedPublicIdsAreQueriedOnce(): void
    {
        $this->attendeeRepository
            ->shouldReceive('findWhereIn')
            ->once()
            ->with(
                m::any(),
                m::on(fn($values) => array_values($values) === ['A-ONE', 'A-TWO']),
                m::any(),
            )
            ->andReturn(new Collection());

        $this->service->getAttendees(collect(['A-ONE', 'A-TWO', 'A-ONE']), 123);
    }

    public function testAttendeeOnAProductTheListCoversIsAccepted(): void
    {
        $error = $this->service->validateAttendeeBelongsToCheckInList(
            $this->checkInListCovering([7, 9]),
            $this->attendeeOnProduct(9),
        );

        $this->assertNull($error);
    }

    public function testAttendeeOnAProductTheListDoesNotCoverIsRefused(): void
    {
        $error = $this->service->validateAttendeeBelongsToCheckInList(
            $this->checkInListCovering([7, 9]),
            $this->attendeeOnProduct(11),
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('Juan Perez', $error);
    }

    private function checkInListCovering(array $productIds): CheckInListDomainObject
    {
        $products = collect($productIds)->map(function (int $id) {
            $product = m::mock(ProductDomainObject::class);
            $product->shouldReceive('getId')->andReturn($id);

            return $product;
        });

        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getProducts')->andReturn($products);

        return $checkInList;
    }

    private function attendeeOnProduct(int $productId): AttendeeDomainObject
    {
        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getProductId')->andReturn($productId);
        $attendee->shouldReceive('getFullName')->andReturn('Juan Perez');

        return $attendee;
    }
}
