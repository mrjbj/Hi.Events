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
                fields: array_merge(
                    $request->only(['first_name', 'last_name', 'email', 'seat_info']),
                    $request->has('confirm_at_checkin')
                        ? ['confirm_at_checkin' => $request->boolean('confirm_at_checkin')]
                        : [],
                    $request->has('notify_email_change')
                        ? ['notify_email_change' => $request->boolean('notify_email_change')]
                        : [],
                    $request->filled('contact_resolution')
                        ? ['contact_resolution' => $request->string('contact_resolution')->toString()]
                        : [],
                ),
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
