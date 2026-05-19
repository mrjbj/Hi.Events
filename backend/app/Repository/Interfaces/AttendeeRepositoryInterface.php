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

    public function findCheckedInAttendees(int $eventId, ?int $checkInListId = null, array $columns = ['*']): Collection;

    public function findNotCheckedInAttendees(int $eventId, ?int $checkInListId = null, array $columns = ['*']): Collection;

    public function countCheckedInAttendees(int $eventId, ?int $checkInListId = null): int;

    public function countNotCheckedInAttendees(int $eventId, ?int $checkInListId = null): int;

    /**
     * Bulk-set contact_link_ignored_at on attendees, scoped to the given account (verified via events JOIN).
     *
     * @param  int[]  $attendeeIds
     * @param  ?string  $timestamp  ISO-8601 value to set; null to clear the flag (un-ignore).
     * @return int Number of rows updated.
     */
    public function bulkUpdateContactLinkIgnoredAt(int $accountId, array $attendeeIds, ?string $timestamp): int;

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

    /**
     * Returns dropdown filter options for the check-in UI, sourced from the entire
     * database (not just the page the client has loaded). Shape:
     *   [
     *     'tables' => string[],                                  // distinct non-empty seat_info values
     *     'groups' => array{order_id:int,label:string}[],        // multi-ticket purchases with buyer label
     *   ]
     */
    public function getCheckInListFilterOptions(string $shortId): array;

    /**
     * Same shape as {@see getCheckInListFilterOptions}, but scoped to a whole
     * event rather than a single check-in list — used by the attendees admin
     * page so staff can filter attendees by table or group across the event.
     *
     * @return array{tables:string[], groups:array{order_id:int,label:string}[]}
     */
    public function getEventAttendeeFilterOptions(int $eventId): array;
}
