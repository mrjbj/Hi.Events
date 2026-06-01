<?php

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\Services\Application\Handlers\Event\DTO\EventReconciliationResponseDTO;
use HiEvents\Services\Domain\Event\EventReconciliationService;
use HiEvents\Services\Domain\Event\UpsertEventExpensesService;

readonly class UpsertEventExpensesHandler
{
    public function __construct(
        private UpsertEventExpensesService $upsertEventExpensesService,
        private EventReconciliationService $eventReconciliationService,
    ) {}

    public function handle(int $eventId, float $expenses, ?int $recordedByUserId): EventReconciliationResponseDTO
    {
        $this->upsertEventExpensesService->upsert($eventId, $expenses, $recordedByUserId);

        return $this->eventReconciliationService->reconcile($eventId);
    }
}
