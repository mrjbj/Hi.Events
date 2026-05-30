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
     * Lowercases and writes $newEmail on every attendee whose contact_id matches and
     * whose event belongs to $accountId. Returns number of rows updated.
     */
    public function updateEmailByContactId(int $contactId, string $newEmail, int $accountId): int;

    /**
     * Bulk-set contact_link_ignored_at on attendees, scoped to the given account (verified via events JOIN).
     *
     * @param  int[]  $attendeeIds
     * @param  ?string  $timestamp  ISO-8601 value to set; null to clear the flag (un-ignore).
     * @return int Number of rows updated.
     */
    public function bulkUpdateContactLinkIgnoredAt(int $accountId, array $attendeeIds, ?string $timestamp): int;

    /**
     * Bulk-set contact_email_divergence_ignored_at on attendees, scoped to the given account (verified
     * via events JOIN). Marks an attendee whose email differs from its linked contact's email as
     * "reviewed, keep the divergence" so it drops out of the Sync "Email Changes" list.
     *
     * @param  int[]  $attendeeIds
     * @param  ?string  $timestamp  ISO-8601 value to set; null to clear the flag (re-surface the divergence).
     * @return int Number of rows updated.
     */
    public function bulkUpdateContactEmailDivergenceIgnoredAt(int $accountId, array $attendeeIds, ?string $timestamp): int;

    /**
     * Bulk-set contact_email_divergence_flagged_at on attendees, scoped to the given account (verified
     * via events JOIN). The flag marks an attendee whose email was deliberately edited (at the door or
     * via self-service) so it diverges from its linked contact — this is what drives the Sync "Email
     * Changes" review queue. A contact-side email change never sets it, so attendees merely left behind
     * by a contact rename stay out of the queue (their per-event email is historical fact, not a TODO).
     *
     * @param  int[]  $attendeeIds
     * @param  ?string  $timestamp  ISO-8601 value to set; null to clear the flag (reconciled / no longer diverging).
     * @return int Number of rows updated.
     */
    public function bulkUpdateContactEmailDivergenceFlaggedAt(int $accountId, array $attendeeIds, ?string $timestamp): int;

    /**
     * Count non-deleted attendees linked to a given contact. Used to tell a sole-owner contact (safe to
     * rename in place) from a shared contact (e.g. a table sponsor whose email seeded several guest
     * tickets) where an email change must split the edited attendee off rather than move the contact.
     */
    public function countActiveByContactId(int $contactId): int;

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
