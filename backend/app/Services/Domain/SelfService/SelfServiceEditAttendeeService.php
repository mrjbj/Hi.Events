<?php

namespace HiEvents\Services\Domain\SelfService;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Mail\Attendee\AttendeeDetailsChangedMail;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use HiEvents\Services\Domain\Contact\ContactUpsertService;
use HiEvents\Services\Domain\SelfService\DTO\EditAttendeeResultDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use Throwable;

class SelfServiceEditAttendeeService
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrderAuditLogService $orderAuditLogService,
        private readonly SendAttendeeTicketService $sendAttendeeTicketService,
        private readonly ContactUpsertService $contactUpsertService,
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly ContactSignedTokenService $contactTokenService,
        private readonly LoggerInterface $logger,
    ) {}

    public function editAttendee(
        AttendeeDomainObject $attendee,
        ?string $firstName,
        ?string $lastName,
        ?string $email,
        string $ipAddress,
        ?string $userAgent
    ): EditAttendeeResultDTO {
        $oldValues = [];
        $newValues = [];
        $emailChanged = false;
        $shortIdChanged = false;
        $newShortId = null;
        $newContactToken = null;

        $updateData = [];

        if ($firstName !== null && $firstName !== $attendee->getFirstName()) {
            $oldValues['first_name'] = $attendee->getFirstName();
            $newValues['first_name'] = $firstName;
            $updateData['first_name'] = $firstName;
        }

        if ($lastName !== null && $lastName !== $attendee->getLastName()) {
            $oldValues['last_name'] = $attendee->getLastName();
            $newValues['last_name'] = $lastName;
            $updateData['last_name'] = $lastName;
        }

        if ($email !== null && $email !== $attendee->getEmail()) {
            $oldValues['email'] = $attendee->getEmail();
            $newValues['email'] = $email;
            $updateData['email'] = $email;
            $emailChanged = true;
        }

        if (!empty($updateData)) {
            $oldEmail = $attendee->getEmail();

            if ($emailChanged) {
                $newShortId = IdHelper::shortId(IdHelper::ATTENDEE_PREFIX);
                $updateData['short_id'] = $newShortId;
                $shortIdChanged = true;

                $oldValues['short_id'] = $attendee->getShortId();
                $newValues['short_id'] = $newShortId;
            }

            $this->attendeeRepository->updateWhere(
                attributes: $updateData,
                where: ['id' => $attendee->getId()]
            );

            $event = $this->loadEventWithRelations($attendee->getEventId());

            // Re-resolve attendee.contact_id so the "My Profile" panel (and any
            // other contact-keyed surface) reflects the new attendee identity.
            // See resyncContactLink for the rules; in short: contacts are keyed by
            // (account_id, lower(email)), so changing the attendee's email always
            // routes to the contact for that email — creating one if needed.
            $newContactId = $this->resyncContactLink(
                attendeeId: $attendee->getId(),
                accountId: (int) $event->getAccountId(),
                newEmail: $newValues['email'] ?? $attendee->getEmail(),
                newFirstName: $newValues['first_name'] ?? $attendee->getFirstName(),
                newLastName: $newValues['last_name'] ?? $attendee->getLastName(),
                previousContactId: $attendee->getContactId(),
            );

            // Mint a fresh contact_token for the (possibly new) linked contact so
            // the frontend can chain a contact-attribute save in the same flow
            // without needing to refetch the order first.
            if ($newContactId !== null && $newContactId !== $attendee->getContactId()) {
                try {
                    $newContactToken = $this->contactTokenService->generate(
                        $newContactId,
                        (int) $event->getAccountId(),
                    );
                } catch (Throwable $e) {
                    $this->logger->warning('Failed to mint contact token after relink', [
                        'attendee_id' => $attendee->getId(),
                        'contact_id' => $newContactId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($emailChanged) {
                $this->sendTicketToNewEmail($attendee->getId(), $event);
            }

            $this->sendChangeNotificationToOldEmail(
                oldEmail: $oldEmail,
                attendeeId: $attendee->getId(),
                event: $event,
                oldValues: $oldValues,
                newValues: $newValues
            );

            $this->orderAuditLogService->logAttendeeUpdate(
                attendee: $attendee,
                oldValues: $oldValues,
                newValues: $newValues,
                ipAddress: $ipAddress,
                userAgent: $userAgent
            );
        }

        return new EditAttendeeResultDTO(
            success: true,
            shortIdChanged: $shortIdChanged,
            newShortId: $newShortId,
            emailChanged: $emailChanged,
            newContactToken: $newContactToken,
        );
    }

    /**
     * Reconcile attendee.contact_id with the attendee's current email and name.
     *
     * Rules (constrained by the (account_id, lower(email)) unique index on
     * contacts):
     *  1. The contact representing this attendee is whichever record has the
     *     attendee's current email on the event's account. If that record
     *     doesn't exist yet, create it with the attendee's name.
     *  2. If the link points to a different contact (typically because email
     *     just changed), rewire `attendees.contact_id` to the right one.
     *  3. If the linked contact is **exclusive** to this attendee (no other
     *     attendees + the contact's email matches the attendee's email), sync
     *     the contact's first_name/last_name to match the attendee. This is
     *     the "buyer fixed a typo in their own seat" case — the contact and
     *     the attendee should agree.
     *  4. If the linked contact is **shared** with other attendees (typical
     *     for bundle seats that all default to the buyer's email), leave the
     *     contact's name alone — we won't rename the buyer just because they
     *     reassigned a placeholder seat to a different person via name alone.
     *     The buyer should change the email to fully reassign the seat.
     */
    private function resyncContactLink(
        int $attendeeId,
        int $accountId,
        string $newEmail,
        ?string $newFirstName,
        ?string $newLastName,
        ?int $previousContactId,
    ): ?int {
        try {
            // Find or create the contact that "owns" this email on this account.
            $targetContact = $this->contactUpsertService->findOrCreateContact(
                accountId: $accountId,
                email: $newEmail,
                firstName: $newFirstName,
                lastName: $newLastName,
            );

            // Rewire the attendee's link if it's pointing elsewhere.
            if ($previousContactId !== $targetContact->getId()) {
                $this->attendeeRepository->updateWhere(
                    attributes: ['contact_id' => $targetContact->getId()],
                    where: ['id' => $attendeeId],
                );
            }

            // If the target contact is exclusive to this attendee and its email
            // matches, sync the contact's name. Skipped when other attendees
            // share the contact (avoids renaming the buyer mid-reassignment).
            $otherLinkedAttendees = DB::table('attendees')
                ->where('contact_id', $targetContact->getId())
                ->where('id', '!=', $attendeeId)
                ->whereNull('deleted_at')
                ->count();

            if ($otherLinkedAttendees === 0) {
                $contactUpdates = [];
                if ($newFirstName !== null && $newFirstName !== $targetContact->getFirstName()) {
                    $contactUpdates['first_name'] = $newFirstName;
                }
                if ($newLastName !== null && $newLastName !== $targetContact->getLastName()) {
                    $contactUpdates['last_name'] = $newLastName;
                }
                if (!empty($contactUpdates)) {
                    $this->contactRepository->updateFromArray($targetContact->getId(), $contactUpdates);
                }
            }

            return $targetContact->getId();
        } catch (Throwable $e) {
            // Don't fail the attendee edit just because contact relinking hit a
            // snag (e.g., race on the unique index). The attendee row update
            // already landed; the contact will reconcile on the next edit.
            $this->logger->warning('Failed to resync attendee contact link', [
                'attendee_id' => $attendeeId,
                'account_id' => $accountId,
                'new_email' => $newEmail,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function loadEventWithRelations(int $eventId): EventDomainObject
    {
        return $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(EventSettingDomainObject::class)
            ->findById($eventId);
    }

    private function sendTicketToNewEmail(int $attendeeId, EventDomainObject $event): void
    {
        $attendee = $this->attendeeRepository
            ->loadRelation(new Relationship(OrderDomainObject::class, nested: [
                new Relationship(OrderItemDomainObject::class),
            ], name: 'order'))
            ->findById($attendeeId);

        $this->sendAttendeeTicketService->send(
            order: $attendee->getOrder(),
            attendee: $attendee,
            event: $event,
            eventSettings: $event->getEventSettings(),
            organizer: $event->getOrganizer(),
        );
    }

    private function sendChangeNotificationToOldEmail(
        string $oldEmail,
        int $attendeeId,
        EventDomainObject $event,
        array $oldValues,
        array $newValues
    ): void {
        $attendee = $this->attendeeRepository
            ->loadRelation(new Relationship(ProductDomainObject::class, name: 'product'))
            ->findById($attendeeId);

        $changedFields = $this->formatChangedFields($oldValues, $newValues);

        Mail::to($oldEmail)->queue(new AttendeeDetailsChangedMail(
            ticketTitle: $attendee->getProduct()?->getTitle() ?? __('Ticket'),
            event: $event,
            organizer: $event->getOrganizer(),
            eventSettings: $event->getEventSettings(),
            changedFields: $changedFields
        ));
    }

    private function formatChangedFields(array $oldValues, array $newValues): array
    {
        $fieldLabels = [
            'first_name' => __('First Name'),
            'last_name' => __('Last Name'),
            'email' => __('Email'),
            'short_id' => __('Ticket Reference'),
        ];

        $changedFields = [];
        foreach ($oldValues as $field => $oldValue) {
            if ($field === 'short_id') {
                continue;
            }
            $label = $fieldLabels[$field] ?? $field;
            $changedFields[$label] = [
                'old' => $oldValue,
                'new' => $newValues[$field] ?? '',
            ];
        }

        return $changedFields;
    }
}
