<?php

namespace HiEvents\Http\Actions\SelfService;

use HiEvents\Exceptions\SelfServiceDisabledException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\SelfService\EditAttendeePublicRequest;
use HiEvents\Services\Application\Handlers\SelfService\DTO\EditAttendeePublicDTO;
use HiEvents\Services\Application\Handlers\SelfService\EditAttendeePublicHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class EditAttendeePublicAction extends BaseAction
{
    public function __construct(
        private readonly EditAttendeePublicHandler $handler
    ) {
    }

    public function __invoke(
        EditAttendeePublicRequest $request,
        int $eventId,
        string $orderShortId,
        string $attendeeShortId
    ): JsonResponse {
        try {
            $result = $this->handler->handle(EditAttendeePublicDTO::from([
                'eventId' => $eventId,
                'orderShortId' => $orderShortId,
                'attendeeShortId' => $attendeeShortId,
                'firstName' => $request->input('first_name'),
                'lastName' => $request->input('last_name'),
                'email' => $request->input('email'),
                'ipAddress' => $this->getClientIp($request),
                'userAgent' => $request->userAgent(),
                'seatInfo' => $request->input('seat_info'),
                'confirmAtCheckin' => $request->has('confirm_at_checkin')
                    ? $request->boolean('confirm_at_checkin')
                    : null,
            ]));

            $response = [
                'message' => __('Attendee updated successfully'),
            ];

            if ($result->shortIdChanged && $result->newShortId) {
                $response['new_short_id'] = $result->newShortId;
            }

            if ($result->newContactToken !== null) {
                $response['new_contact_token'] = $result->newContactToken;
            }

            return $this->jsonResponse($response);
        } catch (SelfServiceDisabledException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode());
        } catch (ResourceNotFoundException $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }
    }
}
