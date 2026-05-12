<?php

namespace HiEvents\Http\Actions\Orders;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Order\BulkAssignAttendeeSeatInfoRequest;
use HiEvents\Services\Application\Handlers\Order\BulkAssignAttendeeSeatInfoHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\BulkAssignAttendeeSeatInfoDTO;
use Illuminate\Http\JsonResponse;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

class BulkAssignAttendeeSeatInfoAction extends BaseAction
{
    public function __construct(
        private readonly BulkAssignAttendeeSeatInfoHandler $handler,
    ) {}

    /**
     * @throws Throwable
     */
    public function __invoke(
        BulkAssignAttendeeSeatInfoRequest $request,
        int $eventId,
        int $orderId,
    ): JsonResponse {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        try {
            $updatedCount = $this->handler->handle(new BulkAssignAttendeeSeatInfoDTO(
                eventId: $eventId,
                orderId: $orderId,
                seatInfo: $request->input('seat_info'),
            ));
        } catch (ResourceNotFoundException $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }

        return $this->jsonResponse([
            'updated_count' => $updatedCount,
        ]);
    }
}
