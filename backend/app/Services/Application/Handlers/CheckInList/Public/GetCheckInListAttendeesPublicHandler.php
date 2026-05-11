<?php

namespace HiEvents\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Helper\DateHelper;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use Illuminate\Contracts\Pagination\Paginator;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * Trust note: each attendee returned to the check-in app carries a freshly minted
 * contact_token (see {@see ContactSignedTokenService}). Anyone with the check-in
 * list short_id therefore inherits the same edit-profile capability as the
 * attendee themselves — name and registration-attribute changes via the contact
 * portal. This matches the existing trust scope (the same actor can already mark
 * any attendee on this list as checked in).
 */
class GetCheckInListAttendeesPublicHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly CheckInListRepositoryInterface $checkInListRepository,
        private readonly ContactSignedTokenService $contactTokenService,
    ) {}

    /**
     * @throws CannotCheckInException
     */
    public function handle(string $shortId, QueryParamsDTO $queryParams): Paginator
    {
        $checkInList = $this->checkInListRepository
            ->loadRelation(ProductDomainObject::class)
            ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
            ->findFirstWhere([
                CheckInListDomainObjectAbstract::SHORT_ID => $shortId,
            ]);

        if (! $checkInList) {
            throw new ResourceNotFoundException(__('Check-in list not found'));
        }

        $this->validateCheckInListIsActive($checkInList);

        $attendees = $this->attendeeRepository->getAttendeesByCheckInShortId($shortId, $queryParams);

        $groupPurchaseKeys = array_flip($this->attendeeRepository->getGroupPurchaseKeysByCheckInShortId($shortId));
        $accountId = $checkInList->getEvent()?->getAccountId();

        // Set the check-in, group-purchase flag, and freshly-minted contact token for each attendee.
        $attendees->getCollection()->transform(function (AttendeeDomainObject $attendee) use ($checkInList, $groupPurchaseKeys, $accountId) {
            $attendee->setCheckIn($attendee->getCheckIns()?->first(fn ($checkIn) => $checkIn->getCheckInListId() === $checkInList->getId()));
            $attendee->setFromGroupPurchase(isset($groupPurchaseKeys[$attendee->getOrderId() . ':' . $attendee->getProductPriceId()]));

            $contactId = $attendee->getContactId();
            if ($contactId !== null && $accountId !== null) {
                $attendee->setContactToken(
                    $this->contactTokenService->generate((int) $contactId, (int) $accountId),
                );
            }

            return $attendee;
        });

        return $attendees;
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
