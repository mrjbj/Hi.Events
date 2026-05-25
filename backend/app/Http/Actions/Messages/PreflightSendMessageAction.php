<?php

namespace HiEvents\Http\Actions\Messages;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Message\SendMessageRequest;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Application\Handlers\Message\PreflightSendMessageHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class PreflightSendMessageAction extends BaseAction
{
    public function __construct(
        private readonly PreflightSendMessageHandler $handler,
    )
    {
    }

    /**
     * @throws ValidationException
     */
    public function __invoke(SendMessageRequest $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $promotesEventId = $request->input('promotes_event_id');
        if ($promotesEventId !== null && (int)$promotesEventId !== $eventId) {
            $this->isActionAuthorized((int)$promotesEventId, EventDomainObject::class);
        }

        $user = $this->getAuthenticatedUser();

        $result = $this->handler->handle(SendMessageDTO::fromArray([
            'event_id' => $eventId,
            'subject' => $request->input('subject'),
            'message' => $request->input('message'),
            'type' => $request->input('message_type'),
            'is_test' => false,
            'order_id' => $request->input('order_id'),
            'attendee_ids' => $request->input('attendee_ids'),
            'product_ids' => $request->input('product_ids'),
            'order_statuses' => $request->input('order_statuses'),
            'send_copy_to_current_user' => false,
            'sent_by_user_id' => $user->getId(),
            'account_id' => $this->getAuthenticatedAccountId(),
            'scheduled_at' => null,
            'check_in_list_id' => $request->input('check_in_list_id'),
            'promotes_event_id' => $promotesEventId !== null ? (int)$promotesEventId : null,
        ]));

        return $this->jsonResponse($result);
    }
}
