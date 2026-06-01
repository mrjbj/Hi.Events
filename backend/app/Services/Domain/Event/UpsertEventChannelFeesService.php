<?php

namespace HiEvents\Services\Domain\Event;

use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use Illuminate\Database\DatabaseManager;

/**
 * Upserts the manually-entered per-channel processing fees for an event. One row
 * per (event_id, channel); re-saving a channel overwrites its fee.
 */
class UpsertEventChannelFeesService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    /**
     * @param  array<int, array{channel: string, fee_amount: float, note?: string|null}>  $fees
     */
    public function upsert(int $eventId, array $fees, ?int $recordedByUserId): void
    {
        if ($fees === []) {
            return;
        }

        $currency = $this->eventRepository->findById($eventId)->getCurrency();
        $now = now()->toDateTimeString();

        $rows = array_map(static fn (array $fee) => [
            'event_id' => $eventId,
            'channel' => $fee['channel'],
            'fee_amount' => round((float) $fee['fee_amount'], 2),
            'currency' => $currency,
            'note' => $fee['note'] ?? null,
            'recorded_by_user_id' => $recordedByUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $fees);

        $this->db->table('event_channel_fees')->upsert(
            $rows,
            ['event_id', 'channel'],
            ['fee_amount', 'currency', 'note', 'recorded_by_user_id', 'updated_at'],
        );
    }
}
