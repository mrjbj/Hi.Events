<?php

namespace HiEvents\Http\Actions\Orders;

use HiEvents\DomainObjects\Enums\OfflinePaymentMethod;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Order\MarkOrderAsPaidRequest;
use HiEvents\Resources\Order\OrderResource;
use HiEvents\Services\Application\Handlers\Order\DTO\MarkOrderAsPaidDTO;
use HiEvents\Services\Application\Handlers\Order\MarkOrderAsPaidHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class MarkOrderAsPaidAction extends BaseAction
{
    public function __construct(
        private readonly MarkOrderAsPaidHandler $markOrderAsPaidHandler,
    )
    {
    }

    public function __invoke(int $eventId, int $orderId, MarkOrderAsPaidRequest $request): JsonResponse|Response
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $validated = $request->validated();

        try {
            $order = $this->markOrderAsPaidHandler->handle(new MarkOrderAsPaidDTO(
                eventId: $eventId,
                orderId: $orderId,
                paymentMethod: OfflinePaymentMethod::from($validated['payment_method']),
                paymentReference: $validated['payment_reference'] ?? null,
                collectedAmount: isset($validated['collected_amount']) ? (float) $validated['collected_amount'] : null,
                adjustedByUserId: $this->getAuthenticatedUser()->getId(),
                adjustedByIp: $request->ip(),
            ));
        } catch (ResourceConflictException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->resourceResponse(
            resource: OrderResource::class,
            data: $order,
        );
    }
}
