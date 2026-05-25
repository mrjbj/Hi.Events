<?php

namespace HiEvents\Http\Actions\TransactionMessages;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ContactEmailConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\TransactionMessage\OutgoingTransactionMessageResource;
use HiEvents\Services\Application\Handlers\DeliveryIssue\ResolveDeliveryIssueHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ResendTransactionMessageAction extends BaseAction
{
    public function __construct(
        private readonly ResolveDeliveryIssueHandler $handler,
    )
    {
    }

    public function __invoke(Request $request, int $eventId, int $messageId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $request->validate([
            'email' => 'sometimes|nullable|email',
        ]);

        try {
            $message = $this->handler->handle(
                eventId: $eventId,
                messageId: $messageId,
                sourceType: ResolveDeliveryIssueHandler::SOURCE_TRANSACTION,
                newEmail: $request->input('email'),
                resend: true,
            );

            return $this->resourceResponse(OutgoingTransactionMessageResource::class, $message);
        } catch (ContactEmailConflictException $e) {
            throw ValidationException::withMessages([
                'email' => [__('Another contact in this account already uses this email address.')],
            ]);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->getMessage());
        }
    }
}
