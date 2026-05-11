<?php

namespace HiEvents\Http\Actions\CheckInLists\Public;

use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\CheckInList\PatchCheckInListAttendeePublicRequest;
use HiEvents\Resources\Attendee\AttendeeWithCheckInPublicResource;
use HiEvents\Services\Application\Handlers\CheckInList\Public\PatchCheckInListAttendeePublicHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PatchCheckInListAttendeePublicAction extends BaseAction
{
    public function __construct(
        private readonly PatchCheckInListAttendeePublicHandler $handler,
    ) {}

    public function __invoke(
        string $shortId,
        string $attendeePublicId,
        PatchCheckInListAttendeePublicRequest $request,
    ): JsonResponse {
        try {
            $attendee = $this->handler->handle(
                shortId: $shortId,
                attendeePublicId: $attendeePublicId,
                fields: $request->only(['first_name', 'last_name', 'email']),
            );
        } catch (CannotCheckInException $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
                statusCode: Response::HTTP_FORBIDDEN,
            );
        }

        return $this->resourceResponse(
            resource: AttendeeWithCheckInPublicResource::class,
            data: $attendee,
        );
    }
}
