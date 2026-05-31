<?php

namespace HiEvents\Http\Actions\Orders;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Order\ReverseOrderPaymentRequest;
use HiEvents\Resources\Order\OrderResource;
use HiEvents\Services\Application\Handlers\Order\DTO\ReverseOrderPaymentDTO;
use HiEvents\Services\Application\Handlers\Order\ReverseOrderPaymentHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ReverseOrderPaymentAction extends BaseAction
{
    public function __construct(
        private readonly ReverseOrderPaymentHandler $reverseOrderPaymentHandler,
    ) {}

    public function __invoke(int $eventId, int $orderId, int $paymentId, ReverseOrderPaymentRequest $request): JsonResponse|Response
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $validated = $request->validated();

        try {
            $order = $this->reverseOrderPaymentHandler->handle(new ReverseOrderPaymentDTO(
                eventId: $eventId,
                orderId: $orderId,
                paymentId: $paymentId,
                note: $validated['note'],
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
