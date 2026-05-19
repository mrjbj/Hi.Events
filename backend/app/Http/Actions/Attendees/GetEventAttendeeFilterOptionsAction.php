<?php

namespace HiEvents\Http\Actions\Attendees;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Attendee\GetEventAttendeeFilterOptionsHandler;
use Illuminate\Http\JsonResponse;

class GetEventAttendeeFilterOptionsAction extends BaseAction
{
    public function __construct(
        private readonly GetEventAttendeeFilterOptionsHandler $handler,
    ) {}

    public function __invoke(int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $options = $this->handler->handle($eventId);

        return $this->jsonResponse($options, wrapInData: true);
    }
}
