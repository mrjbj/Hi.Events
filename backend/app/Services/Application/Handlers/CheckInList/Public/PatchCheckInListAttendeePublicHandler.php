<?php

namespace HiEvents\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Helper\DateHelper;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class PatchCheckInListAttendeePublicHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly CheckInListRepositoryInterface $checkInListRepository,
    ) {}

    /**
     * @param  array<string, string>  $fields  keys: first_name, last_name, email (all optional)
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

        if (! empty($updates)) {
            $this->attendeeRepository->updateFromArray($attendee->getId(), $updates);
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
