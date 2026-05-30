<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\Enums\AttendeeContactResolutionAction;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Domain\Contact\DTO\AttendeeContactResolutionDTO;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reconciles attendees.contact_id after an attendee's email is edited, the single
 * place that decides whether an email change should link, split, rename, or be
 * queued for review. Used by both the check-in door PATCH and the self-service
 * "edit my ticket" path so the two surfaces never drift apart.
 *
 * The governing principle: a contact is the *current* canonical identity; an
 * attendee row is the *historical fact* of what was used at one event. So an
 * email edit never blindly rewrites siblings — it re-points just this attendee,
 * renames a contact only when that's safe, or defers to a human.
 *
 * Rules (newEmail = the attendee's email after the edit):
 *   1. No email                                  → UNCHANGED.
 *   2. Contactless attendee + real email         → LINKED (find-or-create + link).
 *   3. Email already matches the linked contact  → UNCHANGED (clear any stale flag).
 *   4. Diverges, contact is SOLE-owner:
 *        - $renameSoleOwnerContact (self-service) → CONTACT_RENAMED in place
 *          (falls back to SPLIT if another contact already owns the address).
 *        - otherwise (door staff)                 → FLAGGED for review.
 *   5. Diverges, contact is SHARED (>1 attendee — e.g. a sponsor's bundle):
 *        - name matches the order buyer (sponsor) AND decision = same_person
 *                                                  → CONTACT_RENAMED (sponsor's new
 *            address; siblings keep their historical emails, no cascade).
 *        - name matches the buyer, no decision     → FLAGGED (can't auto-decide).
 *        - else (a guest individualizing, or decision = different_person)
 *                                                  → SPLIT (re-point this attendee
 *            to its own found/created contact; the shared contact is untouched).
 */
class AttendeeContactLinkResolver
{
    public const SPONSOR_SAME_PERSON = 'same_person';

    public const SPONSOR_DIFFERENT_PERSON = 'different_person';

    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly ContactUpsertService $contactUpsertService,
        private readonly LoggerInterface $logger,
    ) {}

    public function resolveAfterEmailChange(
        int $attendeeId,
        int $accountId,
        ?int $previousContactId,
        string $newEmail,
        ?string $firstName,
        ?string $lastName,
        ?string $buyerFirstName = null,
        ?string $buyerLastName = null,
        ?string $sponsorDecision = null,
        bool $renameSoleOwnerContact = false,
    ): AttendeeContactResolutionDTO {
        $newEmail = trim($newEmail);

        try {
            if ($newEmail === '') {
                return $this->result(AttendeeContactResolutionAction::UNCHANGED, $previousContactId, false);
            }

            // (2) Contactless — eager link to the contact that owns this email.
            if ($previousContactId === null) {
                return $this->linkToEmailOwner($attendeeId, $accountId, $newEmail, $firstName, $lastName, AttendeeContactResolutionAction::LINKED);
            }

            $previousContact = $this->contactRepository->findById($previousContactId);
            if ($previousContact === null) {
                // Dangling link — treat like contactless.
                return $this->linkToEmailOwner($attendeeId, $accountId, $newEmail, $firstName, $lastName, AttendeeContactResolutionAction::LINKED);
            }

            // (3) Already in sync.
            if (strtolower((string) $previousContact->getEmail()) === strtolower($newEmail)) {
                $this->clearFlag($accountId, $attendeeId);

                return $this->result(AttendeeContactResolutionAction::UNCHANGED, $previousContactId, false);
            }

            $isShared = $this->attendeeRepository->countActiveByContactId($previousContactId) > 1;

            // (4) Sole-owner divergence.
            if (! $isShared) {
                if ($renameSoleOwnerContact) {
                    return $this->renameOrSplit($attendeeId, $accountId, $previousContactId, $newEmail, $firstName, $lastName, 'self_service_edit');
                }

                $this->flagForReview($accountId, $attendeeId);

                return $this->result(AttendeeContactResolutionAction::FLAGGED, $previousContactId, false);
            }

            // (5) Shared contact. An explicit door decision is authoritative; the
            // name-vs-buyer check only matters when no decision was supplied.
            if ($sponsorDecision === self::SPONSOR_SAME_PERSON) {
                return $this->renameOrSplit($attendeeId, $accountId, $previousContactId, $newEmail, $firstName, $lastName, 'door_sponsor_confirm');
            }

            if ($sponsorDecision !== self::SPONSOR_DIFFERENT_PERSON
                && $this->namesMatch($firstName, $lastName, $buyerFirstName, $buyerLastName)) {
                // Looks like the order buyer (sponsor) but nobody disambiguated —
                // defer rather than guess whether they changed their own address.
                $this->flagForReview($accountId, $attendeeId);

                return $this->result(AttendeeContactResolutionAction::FLAGGED, $previousContactId, false);
            }

            // A guest individualizing a bundle seat (or an explicit "different
            // person") — split off without touching the shared contact.
            return $this->linkToEmailOwner($attendeeId, $accountId, $newEmail, $firstName, $lastName, AttendeeContactResolutionAction::SPLIT, $previousContactId);
        } catch (Throwable $e) {
            // Never fail the attendee edit because relinking hit a snag; the row
            // update already landed and the link reconciles on the next edit.
            $this->logger->warning('Failed to resolve attendee contact link after email change', [
                'attendee_id' => $attendeeId,
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return $this->result(AttendeeContactResolutionAction::UNCHANGED, $previousContactId, false);
        }
    }

    private function linkToEmailOwner(
        int $attendeeId,
        int $accountId,
        string $newEmail,
        ?string $firstName,
        ?string $lastName,
        AttendeeContactResolutionAction $action,
        ?int $previousContactId = null,
    ): AttendeeContactResolutionDTO {
        return DB::transaction(function () use ($attendeeId, $accountId, $newEmail, $firstName, $lastName, $action, $previousContactId) {
            $contact = $this->contactUpsertService->findOrCreateContact(
                accountId: $accountId,
                email: $newEmail,
                firstName: $firstName,
                lastName: $lastName,
            );

            $linkChanged = $contact->getId() !== $previousContactId;
            if ($linkChanged) {
                $this->attendeeRepository->updateFromArray($attendeeId, [
                    AttendeeDomainObjectAbstract::CONTACT_ID => $contact->getId(),
                ]);
            }
            $this->clearFlag($accountId, $attendeeId);

            return $this->result($action, $contact->getId(), $linkChanged);
        });
    }

    /**
     * Rename the sole-owner / sponsor contact's email in place. If another contact
     * already owns the target address we can't rename onto it (unique index), so we
     * fall back to splitting the attendee onto that existing contact.
     */
    private function renameOrSplit(
        int $attendeeId,
        int $accountId,
        int $contactId,
        string $newEmail,
        ?string $firstName,
        ?string $lastName,
        string $reason,
    ): AttendeeContactResolutionDTO {
        $owner = $this->contactRepository->findByEmailAndAccountId($newEmail, $accountId);
        if ($owner !== null && $owner->getId() !== $contactId) {
            return $this->linkToEmailOwner($attendeeId, $accountId, $newEmail, $firstName, $lastName, AttendeeContactResolutionAction::SPLIT, $contactId);
        }

        return DB::transaction(function () use ($attendeeId, $accountId, $contactId, $newEmail, $reason) {
            $this->contactRepository->updateEmail($contactId, $newEmail, $reason);
            $this->clearFlag($accountId, $attendeeId);

            return $this->result(AttendeeContactResolutionAction::CONTACT_RENAMED, $contactId, false);
        });
    }

    private function namesMatch(?string $firstName, ?string $lastName, ?string $buyerFirstName, ?string $buyerLastName): bool
    {
        $attendeeName = strtolower(trim(trim((string) $firstName).' '.trim((string) $lastName)));
        $buyerName = strtolower(trim(trim((string) $buyerFirstName).' '.trim((string) $buyerLastName)));

        return $attendeeName !== '' && $attendeeName === $buyerName;
    }

    private function flagForReview(int $accountId, int $attendeeId): void
    {
        // Mark as a deliberate, unreviewed edit and re-arm any prior dismissal so
        // a fresh divergence resurfaces in the Email Changes queue.
        $this->attendeeRepository->bulkUpdateContactEmailDivergenceFlaggedAt($accountId, [$attendeeId], now()->toDateTimeString());
        $this->attendeeRepository->bulkUpdateContactEmailDivergenceIgnoredAt($accountId, [$attendeeId], null);
    }

    private function clearFlag(int $accountId, int $attendeeId): void
    {
        $this->attendeeRepository->bulkUpdateContactEmailDivergenceFlaggedAt($accountId, [$attendeeId], null);
    }

    private function result(AttendeeContactResolutionAction $action, ?int $contactId, bool $linkChanged): AttendeeContactResolutionDTO
    {
        return new AttendeeContactResolutionDTO(
            action: $action,
            contactId: $contactId,
            linkChanged: $linkChanged,
        );
    }
}
