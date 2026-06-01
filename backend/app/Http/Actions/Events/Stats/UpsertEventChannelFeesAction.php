<?php

namespace HiEvents\Http\Actions\Events\Stats;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Event\UpsertEventChannelFeesRequest;
use HiEvents\Services\Application\Handlers\Event\UpsertEventChannelFeesHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class UpsertEventChannelFeesAction extends BaseAction
{
    public function __construct(
        private readonly UpsertEventChannelFeesHandler $handler,
    ) {}

    public function __invoke(int $eventId, UpsertEventChannelFeesRequest $request): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $reconciliation = $this->handler->handle(
            eventId: $eventId,
            fees: $request->validated('fees'),
            recordedByUserId: $this->getAuthenticatedUser()->getId(),
        );

        return $this->resourceResponse(JsonResource::class, $reconciliation);
    }
}
