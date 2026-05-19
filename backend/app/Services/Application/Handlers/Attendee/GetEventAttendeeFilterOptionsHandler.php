<?php

namespace HiEvents\Services\Application\Handlers\Attendee;

use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;

class GetEventAttendeeFilterOptionsHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
    ) {}

    /**
     * @return array{tables:string[], groups:array{order_id:int,label:string}[]}
     */
    public function handle(int $eventId): array
    {
        return $this->attendeeRepository->getEventAttendeeFilterOptions($eventId);
    }
}
