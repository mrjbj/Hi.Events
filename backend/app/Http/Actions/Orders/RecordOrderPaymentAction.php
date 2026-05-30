<?php

namespace HiEvents\Http\Actions\Orders;

use HiEvents\DomainObjects\Enums\OrderPaymentType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Order\RecordOrderPaymentRequest;
use HiEvents\Resources\Order\OrderResource;
use HiEvents\Services\Application\Handlers\Order\DTO\RecordOrderPaymentDTO;
use HiEvents\Services\Application\Handlers\Order\RecordOrderPaymentHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class RecordOrderPaymentAction extends BaseAction
{
    public function __construct(
        private readonly RecordOrderPaymentHandler $recordOrderPaymentHandler,
    )
    {
    }

    public function __invoke(int $eventId, int $orderId, RecordOrderPaymentRequest $request): JsonResponse|Response
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $validated = $request->validated();

        try {
            $order = $this->recordOrderPaymentHandler->handle(new RecordOrderPaymentDTO(
                eventId: $eventId,
                orderId: $orderId,
                type: OrderPaymentType::from($validated['type']),
                amount: (float)$validated['amount'],
                reference: $validated['reference'] ?? null,
                note: $validated['note'] ?? null,
                recordedByUserId: $this->getAuthenticatedUser()->getId(),
                recordedByIp: $request->ip(),
            ));
        } catch (ResourceConflictException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_CONFLICT);
        } catch (ResourceNotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_NOT_FOUND);
        }

        return $this->resourceResponse(
            resource: OrderResource::class,
            data: $order,
        );
    }
}
