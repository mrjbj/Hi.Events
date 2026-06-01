<?php

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\Services\Application\Handlers\Event\DTO\EventReconciliationResponseDTO;
use HiEvents\Services\Domain\Event\EventReconciliationService;

readonly class GetEventReconciliationHandler
{
    public function __construct(private EventReconciliationService $eventReconciliationService) {}

    public function handle(int $eventId): EventReconciliationResponseDTO
    {
        return $this->eventReconciliationService->reconcile($eventId);
    }
}
