<?php

namespace Tests\Unit\Services\Domain\CheckInList;

use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Services\Domain\CheckInList\AttendeeOtherListCheckInsService;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class AttendeeOtherListCheckInsServiceTest extends TestCase
{
    private AttendeeOtherListCheckInsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $lists = m::mock(CheckInListRepositoryInterface::class);
        $lists->shouldReceive('findWhere')
            ->once()
            ->with(['event_id' => 7])
            ->andReturn(new Collection([
                (new CheckInListDomainObject)->setId(1)->setEventId(7)->setName('Puerta Caro'),
                (new CheckInListDomainObject)->setId(2)->setEventId(7)->setName('Puerta Nahiara'),
            ]));

        $this->service = new AttendeeOtherListCheckInsService($lists);
    }

    public function test_splits_check_ins_into_this_list_and_other_lists_with_their_names(): void
    {
        // Anoche una misma entrada entro por "Caro" y 40 minutos despues por
        // "Nahiara": cada lista es independiente y ninguna se entero de la otra.
        $onOtherList = (new AttendeeCheckInDomainObject)->setId(10)->setCheckInListId(1);
        $onThisList = (new AttendeeCheckInDomainObject)->setId(11)->setCheckInListId(2);
        $attendee = (new AttendeeDomainObject)->setId(100)->setCheckIns(new Collection([$onOtherList, $onThisList]));

        $this->service->attach(new Collection([$attendee]), (new CheckInListDomainObject)->setId(2)->setEventId(7));

        $this->assertSame($onThisList, $attendee->getCheckIn());
        $this->assertCount(1, $attendee->getOtherCheckIns());
        $this->assertSame('Puerta Caro', $attendee->getOtherCheckIns()->first()->getCheckInList()->getName());
    }

    public function test_an_attendee_without_check_ins_gets_none(): void
    {
        $attendee = (new AttendeeDomainObject)->setId(100)->setCheckIns(null);

        $this->service->attach(new Collection([$attendee]), (new CheckInListDomainObject)->setId(2)->setEventId(7));

        $this->assertNull($attendee->getCheckIn());
        $this->assertCount(0, $attendee->getOtherCheckIns());
    }
}
