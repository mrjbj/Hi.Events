<?php

namespace HiEvents\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Helper\DateHelper;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Services\Domain\Attendee\BundleSeatInfoPropagationService;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class PatchCheckInListAttendeePublicHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly CheckInListRepositoryInterface $checkInListRepository,
        private readonly BundleSeatInfoPropagationService $bundleSeatInfoPropagationService,
    ) {}

    /**
     * @param  array<string, string|null>  $fields  keys: first_name, last_name, email, seat_info (all optional)
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

        $updates = array_filter([
            'first_name' => $fields['first_name'] ?? null,
            'last_name' => $fields['last_name'] ?? null,
            'email' => $fields['email'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

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

        return $this->attendeeRepository->findById($attendee->getId());
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
