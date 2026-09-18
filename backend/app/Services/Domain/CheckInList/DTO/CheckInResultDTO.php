<?php

namespace HiEvents\Services\Domain\CheckInList\DTO;

class CheckInResultDTO
{
    public function __construct(
        public readonly ?object $checkIn = null,
        public readonly ?string $error = null,
        /**
         * Whether this check-in was written by this request. A check-in that already existed is
         * still returned — the scanner needs it to confirm its optimistic mark — but it is not a
         * new event, and the scanner re-sends a batch until it gets an answer.
         */
        public readonly bool    $wasCreated = false,
    )
    {
    }
}
