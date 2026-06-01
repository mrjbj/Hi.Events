<?php

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\Services\Application\Handlers\Event\DTO\EventReconciliationResponseDTO;
use HiEvents\Services\Domain\Event\EventReconciliationService;
use HiEvents\Services\Domain\Event\UpsertEventChannelFeesService;

readonly class UpsertEventChannelFeesHandler
{
    public function __construct(
        private UpsertEventChannelFeesService $upsertEventChannelFeesService,
        private EventReconciliationService $eventReconciliationService,
    ) {}

    /**
     * @param  array<int, array{channel: string, fee_amount: float, note?: string|null}>  $fees
     */
    public function handle(int $eventId, array $fees, ?int $recordedByUserId): EventReconciliationResponseDTO
    {
        $this->upsertEventChannelFeesService->upsert($eventId, $fees, $recordedByUserId);

        return $this->eventReconciliationService->reconcile($eventId);
    }
}
