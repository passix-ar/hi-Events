<?php

namespace HiEvents\Services\Domain\CheckInList\DTO;

use HiEvents\DataTransferObjects\BaseDTO;
use HiEvents\DataTransferObjects\ErrorBagDTO;
use Illuminate\Support\Collection;

class CreateAttendeeCheckInsResponseDTO extends BaseDTO
{
    public function __construct(
        /** Everything the scan resolved to, new or already on record: this is what the door gets back. */
        public Collection  $attendeeCheckIns,
        public ErrorBagDTO $errors,
        /**
         * The subset this request actually wrote. Domain events fire off this one, so a batch the
         * scanner re-sends after a lost response does not notify the same check-in twice.
         */
        public Collection  $createdCheckIns = new Collection(),
    )
    {
    }
}
