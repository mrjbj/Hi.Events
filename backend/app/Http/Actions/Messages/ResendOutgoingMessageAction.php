<?php

namespace HiEvents\Http\Actions\Messages;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ContactEmailConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Message\OutgoingMessageResource;
use HiEvents\Services\Application\Handlers\DeliveryIssue\ResolveDeliveryIssueHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class ResendOutgoingMessageAction extends BaseAction
{
    public function __construct(
        private readonly ResolveDeliveryIssueHandler $handler,
    )
    {
    }

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(Request $request, int $eventId, int $messageId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $this->validate($request, [
            'email' => 'sometimes|nullable|email',
        ]);

        try {
            $result = $this->handler->handle(
                eventId: $eventId,
                messageId: $messageId,
                sourceType: ResolveDeliveryIssueHandler::SOURCE_ANNOUNCEMENT,
                newEmail: $request->input('email'),
                resend: true,
            );
        } catch (ContactEmailConflictException $e) {
            throw ValidationException::withMessages([
                'email' => [__('Another contact in this account already uses this email address.')],
            ]);
        }

        return $this->resourceResponse(OutgoingMessageResource::class, $result);
    }
}
