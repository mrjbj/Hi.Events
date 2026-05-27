<?php

namespace HiEvents\Http\Actions\CheckInLists;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\CheckInList\GetUncoveredProductsHandler;
use Illuminate\Http\JsonResponse;

class GetUncoveredProductsAction extends BaseAction
{
    public function __construct(
        private readonly GetUncoveredProductsHandler $handler,
    ) {}

    public function __invoke(int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $products = $this->handler->handle($eventId);

        return $this->jsonResponse([
            'data' => $products->map(static fn ($p) => [
                'product_id' => $p->product_id,
                'title' => $p->title,
                'attendee_count' => $p->attendee_count,
            ])->all(),
        ]);
    }
}
