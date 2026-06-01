<?php

namespace HiEvents\Http\Actions\Events\Stats;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Event\UpsertEventExpensesRequest;
use HiEvents\Services\Application\Handlers\Event\UpsertEventExpensesHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class UpsertEventExpensesAction extends BaseAction
{
    public function __construct(
        private readonly UpsertEventExpensesHandler $handler,
    ) {}

    public function __invoke(int $eventId, UpsertEventExpensesRequest $request): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $reconciliation = $this->handler->handle(
            eventId: $eventId,
            expenses: (float) $request->validated('expenses'),
            recordedByUserId: $this->getAuthenticatedUser()->getId(),
        );

        return $this->resourceResponse(JsonResource::class, $reconciliation);
    }
}
