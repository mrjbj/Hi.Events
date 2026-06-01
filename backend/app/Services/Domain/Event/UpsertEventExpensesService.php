<?php

namespace HiEvents\Services\Domain\Event;

use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use Illuminate\Database\DatabaseManager;

/**
 * Upserts the manually-entered total expenses for an event's reconciliation.
 * One row per event; re-saving overwrites the value.
 */
class UpsertEventExpensesService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    public function upsert(int $eventId, float $expenses, ?int $recordedByUserId): void
    {
        $currency = $this->eventRepository->findById($eventId)->getCurrency();
        $now = now()->toDateTimeString();

        $this->db->table('event_reconciliation_settings')->upsert(
            [[
                'event_id' => $eventId,
                'expenses' => round($expenses, 2),
                'currency' => $currency,
                'recorded_by_user_id' => $recordedByUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['event_id'],
            ['expenses', 'currency', 'recorded_by_user_id', 'updated_at'],
        );
    }
}
