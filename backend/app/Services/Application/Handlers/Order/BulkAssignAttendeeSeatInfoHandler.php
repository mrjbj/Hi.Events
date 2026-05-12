<?php

namespace HiEvents\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\BulkAssignAttendeeSeatInfoDTO;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

/**
 * Assign the same seat_info value to every attendee on a given order. The
 * "order-level" UX shortcut: instead of editing each attendee one at a time,
 * organizers set the table once and we fan it out across the order's seats.
 *
 * No bundle-anchor heuristic here — this endpoint is explicit "set all to X",
 * so we overwrite customizations too. The UI defaults the input to whatever's
 * already present on any attendee, so customizations only get clobbered when
 * the organizer intends it.
 */
class BulkAssignAttendeeSeatInfoHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(BulkAssignAttendeeSeatInfoDTO $dto): int
    {
        $order = $this->orderRepository->findFirstWhere([
            'id' => $dto->orderId,
            'event_id' => $dto->eventId,
        ]);

        if ($order === null) {
            throw new ResourceNotFoundException(__('Order not found'));
        }

        $normalized = ($dto->seatInfo === null || $dto->seatInfo === '')
            ? null
            : $dto->seatInfo;

        return $this->databaseManager->transaction(function () use ($dto, $normalized) {
            return $this->attendeeRepository->updateWhere(
                attributes: [AttendeeDomainObjectAbstract::SEAT_INFO => $normalized],
                where: [
                    AttendeeDomainObjectAbstract::ORDER_ID => $dto->orderId,
                    AttendeeDomainObjectAbstract::EVENT_ID => $dto->eventId,
                ],
            );
        });
    }
}
