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
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Domain\Contact\AttendeeContactLinkResolver;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use HiEvents\Services\Domain\SelfService\DTO\EditAttendeeResultDTO;
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
        private readonly AttendeeContactLinkResolver $contactLinkResolver,
        private readonly ContactSignedTokenService $contactTokenService,
        private readonly LoggerInterface $logger,
    ) {}

    public function editAttendee(
        AttendeeDomainObject $attendee,
        ?string $firstName,
        ?string $lastName,
        ?string $email,
        string $ipAddress,
        ?string $userAgent,
        ?string $seatInfo = null,
        ?bool $confirmAtCheckin = null
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

        if (! empty($updateData)) {
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
            // Self-service is the attendee acting on their own ticket, so a
            // sole-owner contact is renamed in place (they keep one identity);
            // a shared/bundle contact is split off. See AttendeeContactLinkResolver.
            $resolution = null;
            if ($emailChanged) {
                $resolution = $this->contactLinkResolver->resolveAfterEmailChange(
                    attendeeId: $attendee->getId(),
                    accountId: (int) $event->getAccountId(),
                    previousContactId: $attendee->getContactId(),
                    newEmail: $newValues['email'] ?? $attendee->getEmail(),
                    firstName: $newValues['first_name'] ?? $attendee->getFirstName(),
                    lastName: $newValues['last_name'] ?? $attendee->getLastName(),
                    renameSoleOwnerContact: true,
                );
            }

            // Mint a fresh contact_token for the (possibly new) linked contact so
            // the frontend can chain a contact-attribute save in the same flow
            // without needing to refetch the order first.
            if ($resolution !== null && $resolution->linkChanged && $resolution->contactId !== null) {
                try {
                    $newContactToken = $this->contactTokenService->generate(
                        $resolution->contactId,
                        (int) $event->getAccountId(),
                    );
                } catch (Throwable $e) {
                    $this->logger->warning('Failed to mint contact token after relink', [
                        'attendee_id' => $attendee->getId(),
                        'contact_id' => $resolution->contactId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($emailChanged) {
                $this->sendTicketToNewEmail($attendee->getId(), $event);
            }

            // Only notify the previous address when there actually was one. An
            // unassigned/contactless seat has a blank email, and Mail::to('')
            // throws an RFC-compliance exception — mirror the door, which gates
            // the notification on a non-empty old address (notify_email_change).
            if (trim((string) $oldEmail) !== '') {
                $this->sendChangeNotificationToOldEmail(
                    oldEmail: $oldEmail,
                    attendeeId: $attendee->getId(),
                    event: $event,
                    oldValues: $oldValues,
                    newValues: $newValues
                );
            }

            $this->orderAuditLogService->logAttendeeUpdate(
                attendee: $attendee,
                oldValues: $oldValues,
                newValues: $newValues,
                ipAddress: $ipAddress,
                userAgent: $userAgent
            );
        }

        // Seat / table assignment runs on its own track — it's an organizer
        // operation rather than an identity change, so we skip the
        // "your details changed" email to the previous email holder. Strictly
        // local to this attendee — bundle-wide changes go through the
        // order-level "Apply to All" endpoint, not per-attendee edits.
        if ($seatInfo !== null && $seatInfo !== $attendee->getSeatInfo()) {
            $previousSeatInfo = $attendee->getSeatInfo();
            $this->attendeeRepository->updateWhere(
                attributes: ['seat_info' => $seatInfo],
                where: ['id' => $attendee->getId()],
            );
            $this->orderAuditLogService->logAttendeeUpdate(
                attendee: $attendee,
                oldValues: ['seat_info' => $previousSeatInfo],
                newValues: ['seat_info' => $seatInfo],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );
        }

        // The confirm-at-check-in flag is an operational toggle, not an
        // identity change, so it runs on its own track like seat_info: persist
        // and audit-log it, but skip the "your details changed" email.
        if ($confirmAtCheckin !== null && $confirmAtCheckin !== $attendee->getConfirmAtCheckin()) {
            $previousConfirmAtCheckin = $attendee->getConfirmAtCheckin();
            $this->attendeeRepository->updateWhere(
                attributes: ['confirm_at_checkin' => $confirmAtCheckin],
                where: ['id' => $attendee->getId()],
            );
            $this->orderAuditLogService->logAttendeeUpdate(
                attendee: $attendee,
                oldValues: ['confirm_at_checkin' => $previousConfirmAtCheckin],
                newValues: ['confirm_at_checkin' => $confirmAtCheckin],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
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
