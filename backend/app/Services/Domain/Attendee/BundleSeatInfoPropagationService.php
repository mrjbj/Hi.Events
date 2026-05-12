<?php

namespace HiEvents\Services\Domain\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;

/**
 * Propagates a bundle anchor's seat_info to its sibling attendees. The
 * "anchor" is the lowest-id attendee on a given (order_id, product_id) — the
 * first row inserted at order completion, conventionally the buyer's seat.
 * Only the anchor's edits propagate; sibling edits are local-only. Among
 * siblings, only those whose seat_info is null or matches the anchor's prior
 * value are overwritten — anything else has been customized by staff and
 * stays put.
 */
class BundleSeatInfoPropagationService
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly ProductRepositoryInterface $productRepository,
    ) {}

    public function propagate(
        int $attendeeId,
        int $orderId,
        int $productId,
        int $eventId,
        ?string $newSeatInfo,
        ?string $previousSeatInfo,
    ): void {
        if ($newSeatInfo === $previousSeatInfo) {
            return;
        }

        /** @var ProductDomainObject|null $product */
        $product = $this->productRepository->findFirstWhere([
            ProductDomainObjectAbstract::ID => $productId,
        ]);
        if ($product === null || ($product->getMinPerOrder() ?? 1) <= 1) {
            return;
        }

        $siblings = $this->attendeeRepository->findWhere([
            AttendeeDomainObjectAbstract::ORDER_ID => $orderId,
            AttendeeDomainObjectAbstract::PRODUCT_ID => $productId,
        ]);
        if ($siblings->isEmpty()) {
            return;
        }

        $anchorId = $siblings->min(fn (AttendeeDomainObject $a) => $a->getId());
        if ($attendeeId !== $anchorId) {
            return;
        }

        foreach ($siblings as $sibling) {
            if ($sibling->getId() === $attendeeId) {
                continue;
            }
            $current = $sibling->getSeatInfo();
            if ($current !== null && $current !== $previousSeatInfo) {
                continue;
            }
            $this->attendeeRepository->updateByIdWhere(
                $sibling->getId(),
                [AttendeeDomainObjectAbstract::SEAT_INFO => $newSeatInfo],
                [AttendeeDomainObjectAbstract::EVENT_ID => $eventId],
            );
        }
    }
}
