<?php

namespace HiEvents\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Helper\DateHelper;
use HiEvents\Mail\Attendee\AttendeeDetailsChangedMail;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Attendee\BundleSeatInfoPropagationService;
use HiEvents\Services\Domain\Contact\AttendeeContactLinkResolver;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use HiEvents\Services\Domain\Email\EmailSuppressionService;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

class PatchCheckInListAttendeePublicHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly CheckInListRepositoryInterface $checkInListRepository,
        private readonly BundleSeatInfoPropagationService $bundleSeatInfoPropagationService,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly AttendeeContactLinkResolver $contactLinkResolver,
        private readonly ContactSignedTokenService $contactTokenService,
        private readonly EmailSuppressionService $emailSuppressionService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  array<string, string|bool|null>  $fields  keys: first_name, last_name, email, seat_info, confirm_at_checkin, notify_email_change, contact_resolution (all optional)
     *
     * @throws CannotCheckInException
     */
    public function handle(string $shortId, string $attendeePublicId, array $fields): AttendeeDomainObject
    {
        $checkInList = $this->checkInListRepository->findFirstWhere([
            CheckInListDomainObjectAbstract::SHORT_ID => $shortId,
        ]);

        if (! $checkInList) {
            throw new ResourceNotFoundException(__('Check-in list not found'));
        }

        $this->validateCheckInListIsActive($checkInList);

        $attendee = $this->attendeeRepository->findAttendeeOnCheckInList($shortId, $attendeePublicId);
        if (! $attendee) {
            throw new ResourceNotFoundException(__('Attendee not found on this check-in list'));
        }

        $oldEmail = $attendee->getEmail();

        $updates = array_filter([
            'first_name' => $fields['first_name'] ?? null,
            'last_name' => $fields['last_name'] ?? null,
            'email' => $fields['email'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // The confirm-at-check-in flag is fully user-controlled: it changes only
        // when the client explicitly sends it (the check-in modal's switch, or
        // the door-capture modal which sends false on save). Handled outside the
        // array_filter above so an explicit `false` isn't dropped as falsy.
        if (array_key_exists('confirm_at_checkin', $fields) && $fields['confirm_at_checkin'] !== null) {
            $updates['confirm_at_checkin'] = (bool) $fields['confirm_at_checkin'];
        }

        // seat_info has its own rules: empty string clears the field (sets to
        // null); explicit null leaves it alone. We track changes separately so
        // we can fan out to bundle siblings after the row update lands.
        $previousSeatInfo = $attendee->getSeatInfo();
        $seatInfoChanged = false;
        $newSeatInfo = $previousSeatInfo;
        if (array_key_exists('seat_info', $fields)) {
            $raw = $fields['seat_info'];
            $newSeatInfo = ($raw === '' || $raw === null) ? null : $raw;
            if ($newSeatInfo !== $previousSeatInfo) {
                $updates['seat_info'] = $newSeatInfo;
                $seatInfoChanged = true;
            }
        }

        if (! empty($updates)) {
            $this->attendeeRepository->updateFromArray($attendee->getId(), $updates);
        }

        // Only notify the previous email holder when the client explicitly asks
        // (the "Edit attendee details" modal's confirmed "Save email" flow). The
        // door-capture modal omits this flag so capturing a placeholder's details
        // doesn't spam the buyer.
        $emailChanged = array_key_exists('email', $updates) && $updates['email'] !== $oldEmail;

        // Reconcile the attendee↔contact link after an email change. The resolver
        // decides whether to link (contactless), split off a shared/bundle contact,
        // rename a sponsor's contact (when the door confirmed it's the same person),
        // or flag a sole-owner divergence for review. It owns the divergence
        // flag, so the door no longer touches those columns directly.
        //
        // We deliberately only act on an actual email CHANGE. A grouped guest can
        // only be separated from the sponsor by being given their own, distinct
        // address (contacts are keyed by email) — so the door captures that new
        // email. Re-saving the buyer's inherited placeholder unchanged would just
        // merge the guest onto the sponsor, so we don't. Contactless attendees
        // left with no real email flow through Contacts → Sync → New Contacts.
        $accountId = null;
        if ($emailChanged) {
            $accountId = (int) $this->eventRepository->findById($attendee->getEventId())->getAccountId();
            $this->contactLinkResolver->resolveAfterEmailChange(
                attendeeId: $attendee->getId(),
                accountId: $accountId,
                previousContactId: $attendee->getContactId(),
                newEmail: (string) $updates['email'],
                firstName: $updates['first_name'] ?? $attendee->getFirstName(),
                lastName: $updates['last_name'] ?? $attendee->getLastName(),
                sponsorDecision: $fields['contact_resolution'] ?? null,
                renameSoleOwnerContact: false,
            );
        }

        // Only notify the previous email holder when the client explicitly asks
        // (the "Edit attendee details" modal's confirmed "Save email" flow). The
        // door-capture modal omits this flag so capturing a placeholder's details
        // doesn't spam the buyer.
        if (! empty($fields['notify_email_change']) && $emailChanged && ! empty($oldEmail)) {
            $this->sendChangeNotificationToOldEmail(
                oldEmail: $oldEmail,
                newEmail: $updates['email'],
                attendeeId: $attendee->getId(),
                eventId: $attendee->getEventId(),
            );
        }

        if ($seatInfoChanged) {
            $this->bundleSeatInfoPropagationService->propagate(
                attendeeId: $attendee->getId(),
                orderId: $attendee->getOrderId(),
                productId: $attendee->getProductId(),
                eventId: $attendee->getEventId(),
                newSeatInfo: $newSeatInfo,
                previousSeatInfo: $previousSeatInfo,
            );
        }

        $refreshed = $this->attendeeRepository->findById($attendee->getId());

        // After an email change that (re)linked a contact, mint a fresh contact
        // token on the returned attendee so the door modal can chain a profile /
        // registration-attribute save without refetching the whole check-in list.
        if ($emailChanged && $accountId !== null && $refreshed->getContactId() !== null) {
            try {
                $refreshed->setContactToken(
                    $this->contactTokenService->generate((int) $refreshed->getContactId(), $accountId),
                );
            } catch (Throwable $e) {
                $this->logger->warning('Failed to mint contact token after check-in email edit', [
                    'attendee_id' => $refreshed->getId(),
                    'contact_id' => $refreshed->getContactId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $refreshed;
    }

    private function sendChangeNotificationToOldEmail(
        string $oldEmail,
        string $newEmail,
        int $attendeeId,
        int $eventId,
    ): void {
        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(EventSettingDomainObject::class)
            ->findById($eventId);

        if ($this->emailSuppressionService->isEmailSuppressed($oldEmail, (int) $event->getAccountId(), 'transactional')) {
            return;
        }

        $attendee = $this->attendeeRepository
            ->loadRelation(new Relationship(ProductDomainObject::class, name: 'product'))
            ->findById($attendeeId);

        Mail::to($oldEmail)->queue(new AttendeeDetailsChangedMail(
            ticketTitle: $attendee->getProduct()?->getTitle() ?? __('Ticket'),
            event: $event,
            organizer: $event->getOrganizer(),
            eventSettings: $event->getEventSettings(),
            changedFields: [
                __('Email') => ['old' => $oldEmail, 'new' => $newEmail],
            ],
        ));
    }

    /**
     * @throws CannotCheckInException
     */
    private function validateCheckInListIsActive(CheckInListDomainObject $checkInList): void
    {
        if ($checkInList->getExpiresAt() && DateHelper::utcDateIsPast($checkInList->getExpiresAt())) {
            throw new CannotCheckInException(__('Check-in list has expired'));
        }

        if ($checkInList->getActivatesAt() && DateHelper::utcDateIsFuture($checkInList->getActivatesAt())) {
            throw new CannotCheckInException(__('Check-in list is not active yet'));
        }
    }
}
