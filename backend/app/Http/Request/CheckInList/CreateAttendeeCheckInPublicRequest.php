<?php

namespace HiEvents\Http\Request\CheckInList;

use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class CreateAttendeeCheckInPublicRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            // Capped because this endpoint is public and the service opens one transaction per
            // attendee: an uncapped array turns a single request into unbounded database work.
            // 100 leaves headroom over the scanner's own batch size (QUEUE_BATCH_SIZE = 50).
            'attendees' => ['required', 'array', 'max:100'],
            // Distinct so the same code cannot be repeated to multiply that work; the service
            // already de-duplicates before querying, so this changes nothing for a real scanner.
            'attendees.*.public_id' => ['required', 'string', 'max:255', 'distinct'],
            'attendees.*.action' => ['required', 'string', Rule::in(AttendeeCheckInActionType::valuesArray())],
        ];
    }
}
