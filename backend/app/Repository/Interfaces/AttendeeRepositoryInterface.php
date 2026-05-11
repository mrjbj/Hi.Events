<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<AttendeeDomainObject>
 */
interface AttendeeRepositoryInterface extends RepositoryInterface
{
    public function findByEventId(int $eventId, QueryParamsDTO $params): LengthAwarePaginator;

    public function findByEventIdForExport(int $eventId): Collection;

    public function getAttendeesByCheckInShortId(string $shortId, QueryParamsDTO $params): Paginator;

    /**
     * Returns a list of "{order_id}:{product_price_id}" keys for order_items on this
     * check-in list whose quantity > 1. Used to flag attendees that arrived via a
     * group/bundle purchase so the check-in UI can prompt staff to verify names.
     *
     * @return string[]
     */
    public function getGroupPurchaseKeysByCheckInShortId(string $shortId): array;

    /**
     * Finds an attendee by public_id, scoped to a check-in list (the attendee's
     * product must be on this list). Returns null if not found or not on the list.
     */
    public function findAttendeeOnCheckInList(string $checkInListShortId, string $attendeePublicId): ?AttendeeDomainObject;
}
